<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Util, V};
use App\Services\{Adms, Attendance};

final class SessionsController extends Base
{
    public function index(Request $req): array
    {
        $g = $this->gym();
        $date = $req->q('date') ? V::check(['d' => $req->q('date')], ['d' => 'date'])['d'] : Util::today();
        [$bf] = $this->branchFilter($req, 's.branch_id');
        $sql = "SELECT s.*, TIMESTAMPDIFF(MINUTE, s.in_at, NOW()) AS elapsed_min, m.name, m.member_code, u.name AS staff_name, d.name AS device_name, b.name AS branch_name, cb.name AS created_by_name
            FROM attendance_sessions s LEFT JOIN members m ON m.id = s.member_id LEFT JOIN users u ON u.id = s.user_id LEFT JOIN devices d ON d.id = s.device_id
            LEFT JOIN branches b ON b.id = s.branch_id LEFT JOIN users cb ON cb.id = s.created_by
            WHERE s.gym_id = ? AND DATE(s.in_at) = ? $bf";
        $p = [$g, $date];
        $who = $req->q('who', 'members');
        $sql .= $who === 'staff' ? ' AND s.user_id IS NOT NULL' : ' AND s.member_id IS NOT NULL';
        if ($req->q('filter') === 'inside') $sql .= ' AND s.out_at IS NULL';
        if ($req->q('filter') === 'flagged') $sql .= " AND (s.auto_closed = 1 OR s.method = 'manual')";
        $rows = DB::all($sql . ' ORDER BY s.in_at DESC LIMIT 500', $p);
        $stats = DB::one("SELECT SUM(s.out_at IS NULL) inside, COUNT(*) checkins, ROUND(AVG(s.duration_min)) avg_min FROM attendance_sessions s WHERE s.gym_id = ? AND DATE(s.in_at) = ? AND s.member_id IS NOT NULL $bf", [$g, $date]);
        $stats['missed_out_yesterday'] = (int)DB::val("SELECT COUNT(*) FROM attendance_sessions s WHERE s.gym_id = ? AND s.auto_closed = 1 AND DATE(s.in_at) = ? $bf", [$g, Util::addDays($date, -1)]);
        $stats['blocked'] = (int)DB::val("SELECT COUNT(*) FROM punch_log s WHERE s.gym_id = ? AND s.result = 'denied' AND DATE(s.punched_at) = ? $bf", [$g, $date]);
        return ['date' => $date, 'stats' => $stats, 'items' => $rows];
    }

    public function punches(Request $req): array
    {
        $date = $req->q('date') ? V::check(['d' => $req->q('date')], ['d' => 'date'])['d'] : Util::today();
        [$bf] = $this->branchFilter($req, 's.branch_id');
        $sql = "SELECT s.*, m.name AS member_name, m.member_code, u.name AS staff_name, d.name AS device_name FROM punch_log s
            LEFT JOIN members m ON m.id = s.member_id LEFT JOIN users u ON u.id = s.user_id LEFT JOIN devices d ON d.id = s.device_id
            WHERE s.gym_id = ? AND DATE(s.punched_at) = ? $bf";
        if ($req->q('result') === 'denied') $sql .= " AND s.result = 'denied'";
        return DB::all($sql . ' ORDER BY s.punched_at DESC LIMIT 500', [$this->gym(), $date]);
    }

    /** Front desk: manual check-in/out by member, or by scanning the member app QR. */
    public function checkin(Request $req): array
    {
        $d = V::check($req->json(), ['member_id' => 'nullable|int', 'qr' => 'nullable|string|max:120', 'branch_id' => 'nullable|int', 'user_id' => 'nullable|int', 'trial_code' => 'nullable|string|max:12']);
        $branch = $this->assertBranch($d['branch_id'] ?? null) ?? $this->defaultBranch();
        $g = $this->gym();
        if (!empty($d['trial_code'])) {
            $lead = DB::one("SELECT * FROM leads WHERE gym_id = ? AND trial_pass_code = ? AND stage IN ('trial','new','follow_up')", [$g, strtoupper($d['trial_code'])]);
            if (!$lead) throw HttpException::bad('Trial pass not found');
            DB::insert('lead_activities', ['lead_id' => $lead['id'], 'user_id' => Auth::staffId(), 'kind' => 'trial_visit', 'note' => 'Checked in with trial pass']);
            DB::update('leads', ['stage' => 'follow_up', 'next_followup_at' => date('Y-m-d H:i:s', time() + 3 * 3600)], ['id' => $lead['id']]);
            return ['result' => 'in', 'name' => $lead['name'] . ' (trial)', 'reason' => null];
        }
        if (!empty($d['user_id'])) {
            Auth::require('staff.view');
            $u = DB::one("SELECT * FROM users WHERE id = ? AND gym_id = ? AND status = 'active'", [$d['user_id'], $g]);
            if (!$u) throw HttpException::notFound('Staff not found');
            return Attendance::punch($g, $branch, ['user' => $u], 'manual');
        }
        if (!empty($d['qr'])) {
            $m = Attendance::verifyQr($g, $d['qr']);
            $method = 'qr';
        } elseif (!empty($d['member_id'])) {
            $m = DB::one('SELECT * FROM members WHERE id = ? AND gym_id = ? AND deleted_at IS NULL', [$d['member_id'], $g]);
            if (!$m) throw HttpException::notFound('Member not found');
            $method = 'manual';
        } else {
            throw HttpException::bad('Choose a member or scan a QR code');
        }
        $r = Attendance::punch($g, $branch, ['member' => $m], $method);
        $r['member'] = ['id' => (int)$m['id'], 'member_code' => $m['member_code'], 'has_photo' => (bool)$m['photo_path']];
        $ms = DB::one("SELECT ms.end_date, p.name FROM memberships ms JOIN plans p ON p.id = ms.plan_id WHERE ms.member_id = ? AND ms.kind = 'membership' AND ms.status IN ('active','frozen') ORDER BY ms.end_date DESC LIMIT 1", [$m['id']]);
        $r['plan'] = $ms;
        $r['dues'] = (float)DB::val("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE member_id = ? AND status IN ('unpaid','partial')", [$m['id']]);
        if ($method === 'manual') Util::audit('session.manual_' . $r['result'], 'member', (int)$m['id']);
        return $r;
    }

    public function checkout(Request $req): array
    {
        $s = $this->find('attendance_sessions', $req->id(), 'Session');
        if ($s['out_at']) throw HttpException::bad('Already checked out');
        $now = date('Y-m-d H:i:s');
        DB::update('attendance_sessions', ['out_at' => $now, 'duration_min' => intdiv(time() - strtotime($s['in_at']), 60), 'note' => 'Checked out at desk'], ['id' => $s['id']]);
        Util::audit('session.manual_out', 'session', (int)$s['id']);
        return ['ok' => true];
    }

    /** Fix a session (e.g. a missed OUT punch). */
    public function update(Request $req): array
    {
        Auth::require('sessions.manage');
        $s = $this->find('attendance_sessions', $req->id(), 'Session');
        $d = V::check($req->json(), ['in_at' => 'nullable|datetime', 'out_at' => 'nullable|datetime', 'note' => 'nullable|string|max:150']);
        $in = $d['in_at'] ?? $s['in_at'];
        $out = $d['out_at'] ?? $s['out_at'];
        if ($out && $out < $in) throw HttpException::bad('OUT must be after IN', ['out_at' => 'Must be after IN']);
        DB::update('attendance_sessions', ['in_at' => $in, 'out_at' => $out, 'duration_min' => $out ? intdiv(strtotime($out) - strtotime($in), 60) : null,
            'auto_closed' => 0, 'note' => $d['note'] ?? 'Corrected by staff'], ['id' => $s['id']]);
        Util::audit('session.fix', 'session', (int)$s['id'], $d);
        return ['ok' => true];
    }

    public function allow(Request $req): array
    {
        // Override an anti-passback denial: close the stale open session so the next punch is a fresh IN.
        Auth::require('sessions.manage');
        $s = $this->find('attendance_sessions', $req->id(), 'Session');
        if (!$s['out_at']) Attendance::autoClose($s);
        Util::audit('session.allow', 'session', (int)$s['id']);
        return ['ok' => true];
    }
}
