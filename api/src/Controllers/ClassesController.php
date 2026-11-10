<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Util, V};
use App\Services\{Billing, Messenger};

final class ClassesController extends Base
{
    private const RULES = ['name' => 'required|string|max:100', 'branch_id' => 'required|int', 'coach_id' => 'nullable|int', 'room' => 'nullable|string|max:60',
        'day_of_week' => 'required|int|min:0|max:6', 'start_time' => 'required|time', 'duration_min' => 'required|int|min:10|max:300', 'capacity' => 'required|int|min:1|max:500',
        'late_cancel_fee' => 'nullable|num|min:0', 'no_show_fee' => 'nullable|num|min:0', 'is_active' => 'nullable|bool'];

    public function index(Request $req): array
    {
        $g = $this->gym();
        $date = $req->q('date') ? V::check(['d' => $req->q('date')], ['d' => 'date'])['d'] : Util::today();
        $dow = (int)date('w', strtotime($date));
        [$bf] = $this->branchFilter($req, 'c.branch_id');
        $today = DB::all("SELECT c.*, u.name AS coach_name, b.name AS branch_name,
              (SELECT COUNT(*) FROM class_bookings x WHERE x.class_id = c.id AND x.class_date = ? AND x.status IN ('booked','attended')) AS booked,
              (SELECT COUNT(*) FROM class_bookings x WHERE x.class_id = c.id AND x.class_date = ? AND x.status = 'waitlist') AS waitlist
            FROM classes c LEFT JOIN users u ON u.id = c.coach_id JOIN branches b ON b.id = c.branch_id
            WHERE c.gym_id = ? AND c.day_of_week = ? AND c.is_active = 1 $bf ORDER BY c.start_time", [$date, $date, $g, $dow]);
        $all = DB::all("SELECT c.*, u.name AS coach_name FROM classes c LEFT JOIN users u ON u.id = c.coach_id WHERE c.gym_id = ? $bf ORDER BY c.day_of_week, c.start_time", [$g]);
        $trainers = DB::all("SELECT u.id, u.name, u.title,
              (SELECT s.in_at FROM attendance_sessions s WHERE s.user_id = u.id AND DATE(s.in_at) = ? ORDER BY s.in_at DESC LIMIT 1) AS in_at,
              (SELECT s.out_at FROM attendance_sessions s WHERE s.user_id = u.id AND DATE(s.in_at) = ? ORDER BY s.in_at DESC LIMIT 1) AS out_at,
              (SELECT COUNT(*) FROM pt_appointments p WHERE p.trainer_id = u.id AND DATE(p.starts_at) = ? AND p.status <> 'cancelled') AS pt_today
            FROM users u WHERE u.gym_id = ? AND u.role = 'trainer' AND u.status = 'active'", [$date, $date, $date, $g]);
        return ['date' => $date, 'today' => $today, 'schedule' => $all, 'trainers' => $trainers, 'pt' => $this->ptList($date)];
    }

    public function save(Request $req): array
    {
        Auth::require('classes.manage');
        $d = V::check($req->json(), self::RULES);
        $this->assertBranch($d['branch_id']);
        $this->assertStaff($d['coach_id'] ?? null, 'coach_id');
        if ($id = $req->id()) { $this->find('classes', $id, 'Class'); DB::update('classes', $d, ['id' => $id, 'gym_id' => $this->gym()]); return ['id' => $id]; }
        return ['id' => DB::insert('classes', $d + ['gym_id' => $this->gym()])];
    }

    public function destroy(Request $req): array
    {
        Auth::require('classes.manage');
        $c = $this->find('classes', $req->id(), 'Class');
        DB::update('classes', ['is_active' => 0], ['id' => $c['id']]);
        return ['ok' => true];
    }

    public function bookings(Request $req): array
    {
        $c = $this->find('classes', $req->id(), 'Class');
        $date = V::check(['d' => $req->q('date', Util::today())], ['d' => 'date'])['d'];
        return ['class' => $c, 'items' => DB::all('SELECT b.*, m.name, m.member_code, m.phone FROM class_bookings b JOIN members m ON m.id = b.member_id WHERE b.class_id = ? AND b.class_date = ? ORDER BY b.status, b.waitlist_pos, b.id', [$c['id'], $date])];
    }

    /** Shared by staff and member app. Books or waitlists; enforces plan validity and one booking per class per day. */
    public static function book(int $gymId, array $class, string $date, array $member): array
    {
        if ($date < Util::today()) throw HttpException::bad('That class has already happened');
        if ((int)date('w', strtotime($date)) !== (int)$class['day_of_week']) throw HttpException::bad('This class does not run on that day');
        if ($date === Util::today() && $class['start_time'] < date('H:i:s')) throw HttpException::bad('This class has already started');
        $chk = \App\Services\Access::check($gymId, $member, (int)$class['branch_id'], "$date {$class['start_time']}");
        if (!$chk['allowed']) throw HttpException::bad('Cannot book: ' . $chk['reason']);
        return DB::tx(function () use ($gymId, $class, $date, $member) {
            DB::one('SELECT id FROM classes WHERE id = ? FOR UPDATE', [$class['id']]);
            $existing = DB::one('SELECT * FROM class_bookings WHERE class_id = ? AND class_date = ? AND member_id = ?', [$class['id'], $date, $member['id']]);
            if ($existing && in_array($existing['status'], ['booked', 'waitlist', 'attended'], true)) throw HttpException::bad('Already booked for this class');
            $booked = (int)DB::val("SELECT COUNT(*) FROM class_bookings WHERE class_id = ? AND class_date = ? AND status IN ('booked','attended')", [$class['id'], $date]);
            if ($booked < (int)$class['capacity']) { $status = 'booked'; $pos = null; }
            else { $status = 'waitlist'; $pos = (int)DB::val("SELECT COALESCE(MAX(waitlist_pos),0)+1 FROM class_bookings WHERE class_id = ? AND class_date = ? AND status = 'waitlist'", [$class['id'], $date]); }
            if ($existing) { DB::update('class_bookings', ['status' => $status, 'waitlist_pos' => $pos], ['id' => $existing['id']]); $id = (int)$existing['id']; }
            else $id = DB::insert('class_bookings', ['gym_id' => $gymId, 'class_id' => $class['id'], 'class_date' => $date, 'member_id' => $member['id'], 'status' => $status, 'waitlist_pos' => $pos]);
            return ['id' => $id, 'status' => $status, 'waitlist_pos' => $pos];
        });
    }

    /** Cancel; charges a late-cancel fee inside the window and promotes the first waitlisted member. */
    public static function cancelBooking(int $gymId, array $b): array
    {
        $c = DB::one('SELECT * FROM classes WHERE id = ?', [$b['class_id']]);
        $start = strtotime($b['class_date'] . ' ' . $c['start_time']);
        $late = $b['status'] === 'booked' && $start - time() < (int)Util::setting('rules.late_cancel_hours', 2, $gymId) * 3600;
        DB::update('class_bookings', ['status' => $late ? 'late_cancel' : 'cancelled', 'waitlist_pos' => null], ['id' => $b['id']]);
        if ($late && (float)$c['late_cancel_fee'] > 0) {
            Billing::createInvoice($gymId, [['description' => "Late cancellation: {$c['name']} on " . date('d M', $start), 'rate' => (float)$c['late_cancel_fee'], 'gst_rate' => 18, 'ref_type' => 'late_cancel']], ['member_id' => (int)$b['member_id'], 'kind' => 'other']);
        }
        $promoted = null;
        if ($b['status'] === 'booked') {
            $next = DB::one("SELECT b.*, m.name, m.phone, m.language, m.member_code, m.id AS mid FROM class_bookings b JOIN members m ON m.id = b.member_id WHERE b.class_id = ? AND b.class_date = ? AND b.status = 'waitlist' ORDER BY b.waitlist_pos LIMIT 1", [$b['class_id'], $b['class_date']]);
            if ($next) {
                DB::update('class_bookings', ['status' => 'booked', 'waitlist_pos' => null], ['id' => $next['id']]);
                Messenger::template($gymId, 'waitlist_promoted', ['id' => $next['mid'], 'name' => $next['name'], 'phone' => $next['phone'], 'language' => $next['language'], 'member_code' => $next['member_code']],
                    ['class' => $c['name'], 'date' => date('d M', $start), 'time' => date('g:i A', $start)]);
                \App\Services\Notify::member($gymId, (int)$next['mid'], 'You are in: ' . $c['name'], 'A spot opened on ' . date('d M, g:i A', $start));
                $promoted = (int)$next['member_id'];
            }
        }
        return ['late_fee' => $late && (float)$c['late_cancel_fee'] > 0, 'promoted_member_id' => $promoted];
    }

    public function staffBook(Request $req): array
    {
        $c = $this->find('classes', $req->id(), 'Class');
        $d = V::check($req->json(), ['member_id' => 'required|int', 'date' => 'required|date']);
        $m = $this->memberRow($d['member_id']);
        return self::book($this->gym(), $c, $d['date'], $m);
    }

    public function updateBooking(Request $req): array
    {
        $b = $this->find('class_bookings', $req->id(), 'Booking');
        $status = V::check($req->json(), ['status' => 'required|in:attended,no_show,cancelled'])['status'];
        if ($status === 'cancelled') return self::cancelBooking($this->gym(), $b);
        DB::update('class_bookings', ['status' => $status], ['id' => $b['id']]);
        return ['ok' => true];
    }

    // ---- Personal training ----

    private function ptList(string $date): array
    {
        $sql = 'SELECT p.*, m.name AS member_name, m.member_code, u.name AS trainer_name, ms.sessions_total, ms.sessions_used
            FROM pt_appointments p JOIN members m ON m.id = p.member_id JOIN users u ON u.id = p.trainer_id LEFT JOIN memberships ms ON ms.id = p.membership_id
            WHERE p.gym_id = ? AND DATE(p.starts_at) = ?';
        $params = [$this->gym(), $date];
        if (Auth::role() === 'trainer') { $sql .= ' AND p.trainer_id = ?'; $params[] = Auth::staffId(); }
        return DB::all($sql . ' ORDER BY p.starts_at', $params);
    }

    public function pt(Request $req): array
    {
        $date = V::check(['d' => $req->q('date', Util::today())], ['d' => 'date'])['d'];
        return $this->ptList($date);
    }

    public static function bookPt(int $gymId, int $memberId, int $trainerId, string $startsAt, int $duration = 60): int
    {
        $ms = DB::one("SELECT * FROM memberships WHERE gym_id = ? AND member_id = ? AND kind = 'pt' AND status = 'active' AND (sessions_total = 0 OR sessions_used < sessions_total) ORDER BY end_date LIMIT 1", [$gymId, $memberId]);
        if (!$ms) throw HttpException::bad('No PT sessions left. Buy a PT pack first.');
        $clash = DB::val("SELECT id FROM pt_appointments WHERE trainer_id = ? AND status = 'booked' AND starts_at < ? + INTERVAL ? MINUTE AND starts_at + INTERVAL duration_min MINUTE > ?", [$trainerId, $startsAt, $duration, $startsAt]);
        if ($clash) throw HttpException::bad('The trainer is busy at that time');
        $booked = (int)DB::val("SELECT COUNT(*) FROM pt_appointments WHERE membership_id = ? AND status = 'booked'", [$ms['id']]);
        if ($ms['sessions_total'] > 0 && $ms['sessions_used'] + $booked >= $ms['sessions_total']) throw HttpException::bad('All remaining PT sessions are already booked');
        return DB::insert('pt_appointments', ['gym_id' => $gymId, 'member_id' => $memberId, 'trainer_id' => $trainerId, 'membership_id' => $ms['id'], 'starts_at' => $startsAt, 'duration_min' => $duration]);
    }

    public function createPt(Request $req): array
    {
        $d = V::check($req->json(), ['member_id' => 'required|int', 'trainer_id' => 'required|int', 'starts_at' => 'required|datetime', 'duration_min' => 'nullable|int|min:15|max:180']);
        $this->memberRow($d['member_id']);
        $this->assertStaff($d['trainer_id']);
        if (Auth::role() === 'trainer' && $d['trainer_id'] !== Auth::staffId()) throw HttpException::forbidden('Trainers can only book their own sessions');
        return ['id' => self::bookPt($this->gym(), $d['member_id'], $d['trainer_id'], $d['starts_at'], $d['duration_min'] ?? 60)];
    }

    public function updatePt(Request $req): array
    {
        $p = $this->find('pt_appointments', $req->id(), 'Session');
        if (Auth::role() === 'trainer' && (int)$p['trainer_id'] !== Auth::staffId()) throw HttpException::forbidden();
        $d = V::check($req->json(), ['status' => 'required|in:done,no_show,cancelled', 'notes' => 'nullable|string|max:255']);
        if ($p['status'] !== 'booked') throw HttpException::bad('This session is already ' . $p['status']);
        DB::tx(function () use ($p, $d) {
            DB::update('pt_appointments', ['status' => $d['status'], 'notes' => $d['notes'] ?? null], ['id' => $p['id']]);
            // Done and no-show both use up a session from the pack; a cancellation does not.
            if ($d['status'] !== 'cancelled' && $p['membership_id']) DB::run('UPDATE memberships SET sessions_used = sessions_used + 1 WHERE id = ?', [$p['membership_id']]);
        });
        return ['ok' => true];
    }

    // ---- Resources (courts, pool lanes, recovery rooms) ----

    public function resources(Request $req): array
    {
        $date = V::check(['d' => $req->q('date', Util::today())], ['d' => 'date'])['d'];
        $res = DB::all('SELECT r.*, b.name AS branch_name FROM resources r JOIN branches b ON b.id = r.branch_id WHERE r.gym_id = ? ORDER BY r.name', [$this->gym()]);
        foreach ($res as &$r) $r['bookings'] = DB::all("SELECT rb.*, m.name FROM resource_bookings rb JOIN members m ON m.id = rb.member_id WHERE rb.resource_id = ? AND DATE(rb.starts_at) = ? AND rb.status = 'booked' ORDER BY rb.starts_at", [$r['id'], $date]);
        return $res;
    }

    public function saveResource(Request $req): array
    {
        Auth::require('classes.manage');
        $d = V::check($req->json(), ['name' => 'required|string|max:100', 'branch_id' => 'required|int', 'slot_minutes' => 'required|int|min:15|max:480', 'price' => 'nullable|num|min:0',
            'open_time' => 'nullable|time', 'close_time' => 'nullable|time', 'is_active' => 'nullable|bool']);
        $this->assertBranch($d['branch_id']);
        if ($id = $req->id()) { $this->find('resources', $id, 'Resource'); DB::update('resources', $d, ['id' => $id, 'gym_id' => $this->gym()]); return ['id' => $id]; }
        return ['id' => DB::insert('resources', $d + ['gym_id' => $this->gym()])];
    }

    public static function bookResource(int $gymId, array $r, array $member, string $startsAt): int
    {
        if (!$r['is_active']) throw HttpException::bad('Not available');
        $end = date('Y-m-d H:i:s', strtotime($startsAt) + $r['slot_minutes'] * 60);
        $t = substr($startsAt, 11, 8);
        if ($t < $r['open_time'] || substr($end, 11, 8) > $r['close_time'] || $startsAt < date('Y-m-d H:i:s')) throw HttpException::bad('Choose a time within opening hours');
        return DB::tx(function () use ($gymId, $r, $member, $startsAt, $end) {
            DB::one('SELECT id FROM resources WHERE id = ? FOR UPDATE', [$r['id']]);
            if (DB::val("SELECT id FROM resource_bookings WHERE resource_id = ? AND status = 'booked' AND starts_at < ? AND ends_at > ?", [$r['id'], $end, $startsAt])) throw HttpException::bad('That slot is taken');
            $id = DB::insert('resource_bookings', ['gym_id' => $gymId, 'resource_id' => $r['id'], 'member_id' => $member['id'], 'starts_at' => $startsAt, 'ends_at' => $end]);
            if ((float)$r['price'] > 0) Billing::createInvoice($gymId, [['description' => $r['name'] . ' ' . date('d M g:i A', strtotime($startsAt)), 'rate' => (float)$r['price'], 'gst_rate' => 18, 'ref_type' => 'resource', 'ref_id' => $id]], ['member_id' => (int)$member['id'], 'kind' => 'other']);
            return $id;
        });
    }

    public function staffBookResource(Request $req): array
    {
        $r = $this->find('resources', $req->id(), 'Resource');
        $d = V::check($req->json(), ['member_id' => 'required|int', 'starts_at' => 'required|datetime']);
        return ['id' => self::bookResource($this->gym(), $r, $this->memberRow($d['member_id']), $d['starts_at'])];
    }
}
