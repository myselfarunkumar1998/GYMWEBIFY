<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{DB, Response};

/**
 * eSSL / ZKTeco "ADMS" push protocol (iclock). Configure the device's Cloud/ADMS server to
 * this site's domain, port 443/80. Only devices registered in Settings › Devices are accepted.
 *
 *  GET  /iclock/cdata?SN=..&options=all      handshake → device options
 *  POST /iclock/cdata?SN=..&table=ATTLOG     punches: "PIN\tYYYY-MM-DD HH:MM:SS\tstatus\tverify\t..."
 *  POST /iclock/cdata?SN=..&table=OPERLOG    user/fingerprint changes (used to mark enrolment)
 *  GET  /iclock/getrequest?SN=..             device polls for queued commands
 *  POST /iclock/devicecmd?SN=..              command results "ID=1&Return=0&CMD=DATA"
 */
final class Adms
{
    public static function handle(string $path, array $query, string $body): Response
    {
        $sn = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($query['SN'] ?? ''));
        $device = $sn ? DB::one('SELECT * FROM devices WHERE serial_no = ? AND is_active = 1', [$sn]) : null;
        if (!$device) return Response::text('ERROR: device not registered', 403);
        DB::run('UPDATE devices SET last_seen_at = NOW() WHERE id = ?', [$device['id']]);

        $p = rtrim($path, '/');
        if (str_ends_with($p, '/cdata')) {
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' || isset($query['options'])) {
                $stamp = $device['last_stamp'] ?: '0';
                return Response::text("GET OPTION FROM: $sn\nATTLOGStamp=$stamp\nOPERLOGStamp=9999\nATTPHOTOStamp=None\nErrorDelay=30\nDelay=10\nTransTimes=00:00;14:05\nTransInterval=1\nTransFlag=TransData AttLog\tOpLog\tEnrollUser\tEnrollFP\nTimeZone=5.5\nRealtime=1\nEncrypt=None\n");
            }
            $table = strtoupper((string)($query['table'] ?? ''));
            if ($table === 'ATTLOG') return Response::text('OK: ' . self::attlog($device, $body));
            if ($table === 'OPERLOG') { self::operlog($device, $body); return Response::text('OK'); }
            return Response::text('OK');
        }
        if (str_ends_with($p, '/getrequest')) {
            $cmds = DB::all("SELECT * FROM device_commands WHERE device_id = ? AND status = 'pending' ORDER BY id LIMIT 20", [$device['id']]);
            if (!$cmds) return Response::text('OK');
            $out = '';
            foreach ($cmds as $c) {
                $out .= 'C:' . $c['id'] . ':' . $c['command'] . "\n";
                DB::run("UPDATE device_commands SET status = 'sent', sent_at = NOW() WHERE id = ?", [$c['id']]);
            }
            return Response::text($out);
        }
        if (str_ends_with($p, '/devicecmd')) {
            foreach (preg_split('/\r?\n/', trim($body)) as $line) {
                parse_str($line, $r);
                if (!empty($r['ID'])) {
                    DB::run('UPDATE device_commands SET status = ?, result = ? WHERE id = ? AND device_id = ?',
                        [((string)($r['Return'] ?? '-1')) === '0' ? 'done' : 'failed', substr($line, 0, 250), (int)$r['ID'], $device['id']]);
                }
            }
            return Response::text('OK');
        }
        return Response::text('OK');
    }

    /** Parse ATTLOG lines and run each punch through session tracking. Returns number processed. */
    public static function attlog(array $device, string $body): int
    {
        $n = 0; $maxStamp = null;
        $verifyMap = ['0' => 'manual', '1' => 'fingerprint', '15' => 'face', '4' => 'rfid', '2' => 'rfid', '3' => 'manual'];
        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            $f = explode("\t", trim($line));
            if (count($f) < 2 || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $f[1])) continue;
            $pin = preg_replace('/[^0-9A-Za-z]/', '', $f[0]);
            $at = $f[1];
            $method = $verifyMap[$f[3] ?? '1'] ?? 'fingerprint';
            if ($method === 'manual') $method = 'fingerprint';
            $gymId = (int)$device['gym_id'];
            $member = DB::one('SELECT * FROM members WHERE gym_id = ? AND device_user_id = ? AND deleted_at IS NULL', [$gymId, $pin]);
            $user = $member ? null : DB::one("SELECT * FROM users WHERE gym_id = ? AND device_user_id = ? AND status = 'active'", [$gymId, $pin]);
            if (!$member && !$user) {
                DB::run('INSERT IGNORE INTO punch_log (gym_id, branch_id, device_id, device_user_id, punched_at, method, result, reason) VALUES (?,?,?,?,?,?,?,?)',
                    [$gymId, $device['branch_id'], $device['id'], $pin, $at, $method, 'denied', 'Not enrolled on this device']);
            } else {
                Attendance::punch($gymId, (int)$device['branch_id'], $member ? ['member' => $member] : ['user' => $user], $method, $device, $at, $pin);
            }
            $n++;
            $maxStamp = $at;
        }
        if ($maxStamp) DB::run('UPDATE devices SET last_stamp = ? WHERE id = ?', [strtotime($maxStamp), $device['id']]);
        return $n;
    }

    /** OPERLOG/USER lines: mark fingerprint/face enrolment so the profile shows it. */
    private static function operlog(array $device, string $body): void
    {
        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            if (preg_match('/^FP PIN=(\w+)/', $line, $m)) {
                DB::run('UPDATE members SET fingerprint_enrolled = 1 WHERE gym_id = ? AND device_user_id = ?', [$device['gym_id'], $m[1]]);
            } elseif (preg_match('/^(FACE|BIODATA) PIN=(\w+)/', $line, $m)) {
                DB::run('UPDATE members SET face_enrolled = 1 WHERE gym_id = ? AND device_user_id = ?', [$device['gym_id'], $m[2]]);
            }
        }
    }

    /** Queue all active members and staff to a device (initial sync or after a reset). */
    public static function syncAll(int $gymId, int $deviceId): int
    {
        $n = 0;
        foreach (DB::all('SELECT * FROM members WHERE gym_id = ? AND deleted_at IS NULL AND device_user_id IS NOT NULL', [$gymId]) as $m) {
            $allowed = Access::check($gymId, $m)['allowed'];
            $grp = $allowed ? 1 : Access::blockedGroup($gymId);
            $name = substr(preg_replace('/[^A-Za-z0-9 .]/', '', $m['name']), 0, 24);
            DB::insert('device_commands', ['device_id' => $deviceId, 'command' => "DATA UPDATE USERINFO PIN={$m['device_user_id']}\tName=$name\tPri=0\tGrp=$grp"]);
            $n++;
        }
        foreach (DB::all("SELECT * FROM users WHERE gym_id = ? AND status = 'active' AND device_user_id IS NOT NULL", [$gymId]) as $u) {
            $name = substr(preg_replace('/[^A-Za-z0-9 .]/', '', $u['name']), 0, 24);
            DB::insert('device_commands', ['device_id' => $deviceId, 'command' => "DATA UPDATE USERINFO PIN={$u['device_user_id']}\tName=$name\tPri=0\tGrp=1"]);
            $n++;
        }
        return $n;
    }
}
