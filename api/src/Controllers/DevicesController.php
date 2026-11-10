<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Config, DB, HttpException, Request, Util, V};
use App\Services\Adms;

final class DevicesController extends Base
{
    public function index(Request $req): array
    {
        $rows = DB::all("SELECT d.*, b.name AS branch_name,
            (SELECT COUNT(*) FROM device_commands c WHERE c.device_id = d.id AND c.status = 'pending') AS pending_commands,
            (SELECT COUNT(*) FROM punch_log p WHERE p.device_id = d.id AND DATE(p.punched_at) = CURDATE()) AS punches_today,
            d.last_seen_at > NOW() - INTERVAL 5 MINUTE AS online
            FROM devices d JOIN branches b ON b.id = d.branch_id WHERE d.gym_id = ? ORDER BY d.id", [$this->gym()]);
        $host = parse_url((string)Config::get('app_url'), PHP_URL_HOST);
        return ['devices' => $rows, 'server' => ['host' => $host, 'port' => 443, 'path' => '/iclock/cdata'],
            'enrolled' => DB::one('SELECT SUM(fingerprint_enrolled) fingerprint, SUM(face_enrolled) face, COUNT(*) members FROM members WHERE gym_id = ? AND deleted_at IS NULL', [$this->gym()])];
    }

    public function save(Request $req): array
    {
        $d = V::check($req->json(), ['name' => 'required|string|max:100', 'serial_no' => 'required|string|max:60', 'branch_id' => 'required|int', 'model' => 'nullable|string|max:60',
            'kind' => 'nullable|in:fingerprint,face,rfid,turnstile,qr_kiosk', 'direction' => 'nullable|in:both,in,out', 'relay_controls_door' => 'nullable|bool', 'is_active' => 'nullable|bool']);
        $d['serial_no'] = preg_replace('/[^A-Za-z0-9_-]/', '', $d['serial_no']);
        $this->assertBranch($d['branch_id']);
        $id = $req->id();
        $dupe = DB::val('SELECT id FROM devices WHERE serial_no = ? AND id <> ?', [$d['serial_no'], $id]);
        if ($dupe) throw HttpException::bad('This serial number is already registered', ['serial_no' => 'Already registered']);
        if ($id) { $this->find('devices', $id, 'Device'); DB::update('devices', $d, ['id' => $id, 'gym_id' => $this->gym()]); }
        else { $id = DB::insert('devices', $d + ['gym_id' => $this->gym()]); Adms::syncAll($this->gym(), $id); }
        Util::audit('device.save', 'device', $id);
        return ['id' => $id];
    }

    public function destroy(Request $req): array
    {
        $d = $this->find('devices', $req->id(), 'Device');
        DB::run('DELETE FROM device_commands WHERE device_id = ?', [$d['id']]);
        DB::run('DELETE FROM devices WHERE id = ? AND gym_id = ?', [$d['id'], $this->gym()]);
        return ['ok' => true];
    }

    public function sync(Request $req): array
    {
        $d = $this->find('devices', $req->id(), 'Device');
        return ['queued' => Adms::syncAll($this->gym(), (int)$d['id'])];
    }

    public function commands(Request $req): array
    {
        $d = $this->find('devices', $req->id(), 'Device');
        return DB::all('SELECT * FROM device_commands WHERE device_id = ? ORDER BY id DESC LIMIT 100', [$d['id']]);
    }

    /** Raw command (owner only), e.g. "REBOOT", "CHECK", "CLEAR LOG". */
    public function command(Request $req): array
    {
        $d = $this->find('devices', $req->id(), 'Device');
        $cmd = V::check($req->json(), ['command' => 'required|in:REBOOT,CHECK,INFO,CLEAR LOG,OPEN DOOR'])['command'];
        if ($cmd === 'OPEN DOOR') $cmd = 'CONTROL DEVICE 01010103';
        DB::insert('device_commands', ['device_id' => $d['id'], 'command' => $cmd]);
        Util::audit('device.command', 'device', (int)$d['id'], $cmd);
        return ['ok' => true];
    }
}
