<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, DB, HttpException, Util};

/**
 * Session tracking: every IN punch opens a session, the next OUT punch closes it.
 * Devices set to "both" toggle; "in"/"out" devices are explicit. Duplicate punches within
 * the debounce window are ignored. Open sessions are closed by cron at closing time.
 */
final class Attendance
{
    public const DEBOUNCE_SEC = 90;

    /**
     * @param array $who ['member' => row] or ['user' => row]
     * @return array{result: string, reason: ?string, session_id: ?int, name: string, duration_min: ?int}
     */
    public static function punch(int $gymId, int $branchId, array $who, string $method, ?array $device = null, ?string $at = null, ?string $deviceUserId = null): array
    {
        $at ??= date('Y-m-d H:i:s');
        return DB::tx(function () use ($gymId, $branchId, $who, $method, $device, $at, $deviceUserId) {
            $member = $who['member'] ?? null;
            $user = $who['user'] ?? null;
            $col = $member ? 'member_id' : 'user_id';
            $subjectId = (int)($member['id'] ?? $user['id']);
            $name = $member['name'] ?? $user['name'];
            $direction = $device['direction'] ?? 'both';

            $log = fn(string $result, ?string $reason) => DB::run('INSERT IGNORE INTO punch_log (gym_id, branch_id, device_id, device_user_id, member_id, user_id, punched_at, method, result, reason) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$gymId, $branchId, $device['id'] ?? null, $deviceUserId, $member['id'] ?? null, $user['id'] ?? null, $at, $method, $result, $reason]);

            // Devices resend logs after reconnecting: the same punch must never be counted twice.
            if ($device && $deviceUserId !== null && DB::val('SELECT id FROM punch_log WHERE device_id = ? AND device_user_id = ? AND punched_at = ?', [$device['id'], $deviceUserId, $at])) {
                return ['result' => 'ignored', 'reason' => 'Already received', 'session_id' => null, 'name' => $name, 'duration_min' => null];
            }
            $last = DB::val("SELECT MAX(GREATEST(in_at, COALESCE(out_at, in_at))) FROM attendance_sessions WHERE gym_id = ? AND $col = ?", [$gymId, $subjectId]);
            if ($last && strtotime($at) < strtotime((string)$last) - self::DEBOUNCE_SEC) {
                $log('ignored', 'Older than the last recorded punch');
                return ['result' => 'ignored', 'reason' => 'Older than the last recorded punch', 'session_id' => null, 'name' => $name, 'duration_min' => null];
            }
            if ($last && abs(strtotime($at) - strtotime((string)$last)) < self::DEBOUNCE_SEC) {
                $log('ignored', 'Duplicate punch');
                return ['result' => 'ignored', 'reason' => 'Duplicate punch', 'session_id' => null, 'name' => $name, 'duration_min' => null];
            }

            $open = DB::one("SELECT * FROM attendance_sessions WHERE gym_id = ? AND $col = ? AND out_at IS NULL AND in_at <= ? ORDER BY in_at DESC LIMIT 1 FOR UPDATE", [$gymId, $subjectId, $at]);
            // A session left open from a previous day is stale; the cron should have closed it.
            if ($open && substr($open['in_at'], 0, 10) !== substr($at, 0, 10)) {
                self::autoClose($open);
                $open = null;
            }

            $wantsOut = $direction === 'out' || ($direction === 'both' && $open);
            if ($wantsOut) {
                if (!$open) { $log('ignored', 'OUT without IN'); return ['result' => 'ignored', 'reason' => 'OUT without an IN punch', 'session_id' => null, 'name' => $name, 'duration_min' => null]; }
                $dur = intdiv(strtotime($at) - strtotime($open['in_at']), 60);
                DB::update('attendance_sessions', ['out_at' => $at, 'duration_min' => $dur], ['id' => $open['id']]);
                $log('out', null);
                return ['result' => 'out', 'reason' => null, 'session_id' => (int)$open['id'], 'name' => $name, 'duration_min' => $dur];
            }

            if ($open) { // "in" device while already inside
                $log('denied', 'Second IN without OUT (anti-passback)');
                Notify::staff($gymId, 'Anti-passback: ' . $name, 'Second IN punch without an OUT at ' . date('g:i A', strtotime($at)), 'sessions', 'warning');
                return ['result' => 'denied', 'reason' => 'Second IN without OUT (anti-passback)', 'session_id' => (int)$open['id'], 'name' => $name, 'duration_min' => null];
            }

            if ($member) {
                $chk = Access::check($gymId, $member, $branchId, $at);
                if (!$chk['allowed']) {
                    $log('denied', $chk['reason']);
                    return ['result' => 'denied', 'reason' => $chk['reason'], 'session_id' => null, 'name' => $name, 'duration_min' => null];
                }
            }

            $sid = DB::insert('attendance_sessions', [
                'gym_id' => $gymId, 'branch_id' => $branchId, 'member_id' => $member['id'] ?? null, 'user_id' => $user['id'] ?? null,
                'in_at' => $at, 'method' => $method, 'device_id' => $device['id'] ?? null, 'created_by' => Auth::staffId(),
            ]);
            $log('in', null);
            self::unstaffedAlert($gymId, $branchId, $name, $at);
            if ($member) Webhooks::emit($gymId, 'checkin', ['member_id' => (int)$member['id'], 'member_code' => $member['member_code'], 'at' => $at, 'method' => $method]);
            return ['result' => 'in', 'reason' => null, 'session_id' => $sid, 'name' => $name, 'duration_min' => null];
        });
    }

    private static function unstaffedAlert(int $gymId, int $branchId, string $name, string $at): void
    {
        $b = DB::one('SELECT name, staffed_from, staffed_to FROM branches WHERE id = ?', [$branchId]);
        $t = substr($at, 11, 8);
        if ($b && ($t < $b['staffed_from'] || $t > $b['staffed_to'])) {
            Notify::staff($gymId, 'Unstaffed entry at ' . $b['name'], "$name entered at " . date('g:i A', strtotime($at)) . ' (outside staffed hours)', 'sessions', 'warning', true);
        }
    }

    public static function autoClose(array $s): void
    {
        $close = DB::val('SELECT close_time FROM branches WHERE id = ?', [$s['branch_id']]) ?: '22:00:00';
        $outAt = substr($s['in_at'], 0, 10) . ' ' . $close;
        if ($outAt < $s['in_at']) $outAt = $s['in_at'];
        DB::update('attendance_sessions', ['out_at' => $outAt, 'duration_min' => null, 'auto_closed' => 1, 'note' => 'No OUT punch'], ['id' => $s['id']]);
    }

    /** Close every session still open after its branch's closing time. */
    public static function autoCloseDue(): int
    {
        $rows = DB::all("SELECT s.* FROM attendance_sessions s JOIN branches b ON b.id = s.branch_id
            WHERE s.out_at IS NULL AND (DATE(s.in_at) < CURDATE() OR TIME(NOW()) >= b.close_time)");
        foreach ($rows as $r) self::autoClose($r);
        return count($rows);
    }

    /** Rotating QR token: valid for the current and previous 30-second window. */
    public static function qrToken(array $member, ?int $window = null): string
    {
        $window ??= intdiv(time(), 30);
        $mac = substr(hash_hmac('sha256', $member['id'] . '.' . $window, $member['qr_secret']), 0, 20);
        return 'GM1.' . $member['id'] . '.' . $window . '.' . $mac;
    }

    public static function verifyQr(int $gymId, string $token): array
    {
        if (!preg_match('/^GM1\.(\d+)\.(\d+)\.([a-f0-9]{20})$/', trim($token), $m)) throw HttpException::bad('Not a member QR code');
        $member = DB::one('SELECT * FROM members WHERE id = ? AND gym_id = ? AND deleted_at IS NULL', [(int)$m[1], $gymId]);
        if (!$member) throw HttpException::bad('QR code not recognised');
        $now = intdiv(time(), 30);
        $w = (int)$m[2];
        if ($w < $now - 1 || $w > $now + 1) throw HttpException::bad('QR code expired. Ask the member to refresh it.');
        if (!hash_equals(self::qrToken($member, $w), trim($token))) throw HttpException::bad('QR code not recognised');
        return $member;
    }
}
