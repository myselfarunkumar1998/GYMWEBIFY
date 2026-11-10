<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Config, DB, HttpException, RateLimit, Request, Response, Upload, Util, V};
use App\Services\{Access, Attendance, Billing, Http, Memberships, Messenger, Razorpay};

/** API for the member app (PWA). Everything is scoped to the signed-in member. */
final class MemberAppController extends Base
{
    private function me(): array { return Auth::$member; }
    private function mid(): int { return (int)Auth::$member['id']; }

    // ---- Sign in with OTP on WhatsApp ----

    public function requestOtp(Request $req): array
    {
        $d = V::check($req->json(), ['gym' => 'required|string|max:60', 'phone' => 'required|phone']);
        RateLimit::hit('otp-phone:' . $d['gym'] . ':' . $d['phone'], 5, 3600);
        $g = DB::one("SELECT * FROM gyms WHERE slug = ? AND status <> 'suspended'", [$d['gym']]);
        $out = ['sent' => true];
        if (!$g) return $out;
        Auth::$gymId = (int)$g['id'];
        $m = DB::one('SELECT * FROM members WHERE gym_id = ? AND phone = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$g['id'], $d['phone']]);
        if (!$m) return $out; // do not reveal whether the number is a member
        $code = (string)random_int(100000, 999999);
        DB::run('UPDATE otp_codes SET used_at = NOW() WHERE gym_id = ? AND phone = ? AND used_at IS NULL', [$g['id'], $d['phone']]);
        DB::insert('otp_codes', ['gym_id' => $g['id'], 'phone' => $d['phone'], 'code_hash' => hash_hmac('sha256', $code, (string)Config::get('app_key')), 'expires_at' => date('Y-m-d H:i:s', time() + 600)]);
        $gid = (int)$g['id'];
        $text = Messenger::render(Messenger::DEFAULTS['otp'][$m['language']] ?? Messenger::DEFAULTS['otp']['en'], ['code' => $code, 'gym' => $g['name']]);
        if (Messenger::whatsappConfigured($gid) || Util::isDemoGym($gid)) {
            $mid = Messenger::template($gid, 'otp', $m, ['code' => $code], ['force' => true]);
            $out['via'] = 'whatsapp';
        } elseif (Util::setting('integrations.sms_url', null, $gid)) {
            $mid = Messenger::queue($gid, 'sms', $m['phone'], $text, ['member_id' => (int)$m['id'], 'tkey' => 'otp']);
            $out['via'] = 'sms';
        } elseif ($m['email']) {
            $mid = Messenger::queue($gid, 'email', $m['email'], $text, ['member_id' => (int)$m['id'], 'tkey' => 'otp', 'payload' => ['subject' => 'Your login code']]);
            $out['via'] = 'email';
        } else {
            $mid = null;
            $out['via'] = 'none';
        }
        if ($mid) Messenger::dispatch(1, $mid);
        if (Util::isDemoGym($gid) && !Messenger::whatsappConfigured($gid)) $out['demo_code'] = $code;
        return $out;
    }

    public function verifyOtp(Request $req): array
    {
        $d = V::check($req->json(), ['gym' => 'required|string|max:60', 'phone' => 'required|phone', 'code' => 'required|string|max:6']);
        RateLimit::hit('otp-verify:' . $d['gym'] . ':' . $d['phone'], 10, 3600);
        $g = DB::one("SELECT * FROM gyms WHERE slug = ? AND status <> 'suspended'", [$d['gym']]);
        if (!$g) throw HttpException::bad('Wrong or expired code', ['code' => 'Wrong or expired code']);
        $otp = DB::one('SELECT * FROM otp_codes WHERE gym_id = ? AND phone = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1', [$g['id'], $d['phone']]);
        if (!$otp || $otp['attempts'] >= 5) throw HttpException::bad('Wrong or expired code', ['code' => 'Wrong or expired code']);
        if (!hash_equals($otp['code_hash'], hash_hmac('sha256', $d['code'], (string)Config::get('app_key')))) {
            DB::run('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$otp['id']]);
            throw HttpException::bad('Wrong or expired code', ['code' => 'Wrong or expired code']);
        }
        DB::run('UPDATE otp_codes SET used_at = NOW() WHERE id = ?', [$otp['id']]);
        $m = DB::one('SELECT * FROM members WHERE gym_id = ? AND phone = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$g['id'], $d['phone']]);
        if (!$m) throw HttpException::bad('Wrong or expired code');
        Auth::startSession('member', (int)$m['id'], (int)$g['id'], $req);
        Auth::$member = $m; Auth::$gymId = (int)$g['id'];
        Util::audit('member.login', 'member', (int)$m['id']);
        return $this->profile($req);
    }

    public function logout(Request $req): Response { Auth::endSession('member'); return Response::ok(); }

    public function profile(Request $req): array
    {
        $m = $this->me();
        $g = Util::gym();
        return ['member' => ['id' => (int)$m['id'], 'name' => $m['name'], 'member_code' => $m['member_code'], 'phone' => $m['phone'], 'email' => $m['email'], 'language' => $m['language'],
            'goal' => $m['goal'], 'leaderboard_opt_in' => (bool)$m['leaderboard_opt_in'], 'waiver_signed' => (bool)$m['waiver_signed_at'], 'credit_balance' => (float)$m['credit_balance'],
            'fingerprint_enrolled' => (bool)$m['fingerprint_enrolled'], 'face_enrolled' => (bool)$m['face_enrolled'], 'strava_connected' => (bool)$m['strava_tokens'], 'has_photo' => (bool)$m['photo_path']],
            'gym' => ['name' => $g['name'], 'slug' => $g['slug'], 'phone' => $g['phone'], 'logo_url' => $g['logo_url'], 'waiver_text' => $g['settings']['waiver_text'] ?? null],
            'vapid_public' => Config::get('vapid.public'), 'online_payments' => Razorpay::configured((int)$g['id']) || Util::isDemoGym((int)$g['id'])];
    }

    public function updatePrefs(Request $req): array
    {
        $d = V::check($req->json(), ['language' => 'nullable|in:en,ta', 'leaderboard_opt_in' => 'nullable|bool', 'email' => 'nullable|email', 'goal' => 'nullable|string|max:80']);
        DB::update('members', $d, ['id' => $this->mid()]);
        return ['ok' => true];
    }

    public function photo(Request $req): Response
    {
        return Upload::stream($this->gym(), $this->me()['photo_path']);
    }

    // ---- Home ----

    private function currentPlan(): ?array
    {
        return DB::one("SELECT ms.*, p.name AS plan_name, p.all_branches FROM memberships ms JOIN plans p ON p.id = ms.plan_id WHERE ms.member_id = ? AND ms.kind = 'membership' AND ms.status NOT IN ('cancelled','transferred') ORDER BY (ms.status = 'upcoming'), ms.end_date DESC LIMIT 1", [$this->mid()]);
    }

    public function home(Request $req): array
    {
        $id = $this->mid();
        $ms = $this->currentPlan();
        $week = [];
        $monday = date('Y-m-d', strtotime('monday this week'));
        for ($i = 0; $i < 7; $i++) {
            $d = Util::addDays($monday, $i);
            $week[] = ['date' => $d, 'minutes' => (int)DB::val('SELECT COALESCE(SUM(COALESCE(duration_min, TIMESTAMPDIFF(MINUTE, in_at, NOW()))),0) FROM attendance_sessions WHERE member_id = ? AND DATE(in_at) = ? AND (duration_min IS NOT NULL OR out_at IS NULL)', [$id, $d])];
        }
        $dow = (int)date('w');
        $plan = WorkoutsController::planFor($this->gym(), $id);
        $todayItems = $plan ? array_values(array_filter($plan['items'], fn($x) => (int)$x['day_of_week'] === $dow)) : [];
        $pt = DB::one("SELECT ms.sessions_total - ms.sessions_used AS left_count FROM memberships ms WHERE ms.member_id = ? AND ms.kind = 'pt' AND ms.status = 'active' ORDER BY ms.end_date DESC LIMIT 1", [$id]);
        $trainer = $this->me()['trainer_id'] ? DB::val('SELECT name FROM users WHERE id = ?', [$this->me()['trainer_id']]) : null;
        return [
            'session' => DB::one('SELECT id, in_at, TIMESTAMPDIFF(SECOND, in_at, NOW()) AS elapsed_sec FROM attendance_sessions WHERE member_id = ? AND out_at IS NULL AND DATE(in_at) = CURDATE()', [$id]),
            'week' => $week, 'week_visits' => count(array_filter($week, fn($w) => $w['minutes'] > 0)),
            'plan' => $ms ? ['name' => $ms['plan_name'], 'start_date' => $ms['start_date'], 'end_date' => $ms['end_date'], 'status' => Memberships::statusFor($ms, $this->graceDays()),
                'days_left' => max(0, Util::daysBetween(Util::today(), $ms['end_date'])), 'total_days' => Util::daysBetween($ms['start_date'], $ms['end_date']) + 1, 'autopay' => (bool)$ms['autopay']] : null,
            'pt_left' => $pt ? (int)$pt['left_count'] : null,
            'today_workout' => $todayItems ? ['title' => $todayItems[0]['day_title'], 'count' => count($todayItems)] : null, 'trainer' => $trainer,
            'dues' => (float)DB::val("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE member_id = ? AND status IN ('unpaid','partial')", [$id]),
            'announcements' => DB::all('SELECT title, body, created_at FROM announcements WHERE gym_id = ? AND (visible_until IS NULL OR visible_until >= CURDATE()) ORDER BY id DESC LIMIT 3', [$this->gym()]),
            'next_class' => DB::one("SELECT c.name, c.start_time, b.class_date FROM class_bookings b JOIN classes c ON c.id = b.class_id WHERE b.member_id = ? AND b.status = 'booked' AND b.class_date >= CURDATE() ORDER BY b.class_date, c.start_time LIMIT 1", [$id]),
            'unread' => (int)DB::val("SELECT COUNT(*) FROM notifications WHERE audience = 'member' AND member_id = ? AND read_at IS NULL", [$id]),
        ];
    }

    public function card(Request $req): array
    {
        $m = $this->me();
        $ms = $this->currentPlan();
        $access = Access::check($this->gym(), $m);
        return ['token' => Attendance::qrToken($m), 'expires_in' => 30 - time() % 30, 'name' => $m['name'], 'member_code' => $m['member_code'],
            'plan' => $ms ? ['name' => $ms['plan_name'], 'end_date' => $ms['end_date'], 'all_branches' => (bool)$ms['all_branches']] : null,
            'access' => ['allowed' => $access['allowed'], 'reason' => $access['reason']],
            'branches' => DB::all('SELECT name FROM branches WHERE gym_id = ? AND is_active = 1', [$this->gym()]),
            'methods' => array_values(array_filter(['fingerprint' => $m['fingerprint_enrolled'] ? 'Fingerprint' : null, 'face' => $m['face_enrolled'] ? 'Face' : null]))];
    }

    public function visits(Request $req): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string)$req->q('month')) ? $req->q('month') : date('Y-m');
        $rows = DB::all("SELECT in_at, out_at, duration_min, method, auto_closed FROM attendance_sessions WHERE member_id = ? AND DATE_FORMAT(in_at, '%Y-%m') = ? ORDER BY in_at DESC", [$this->mid(), $month]);
        $counted = array_filter($rows, fn($r) => $r['duration_min'] !== null);
        $total = array_sum(array_map(fn($r) => (int)$r['duration_min'], $counted));
        return ['month' => $month, 'visits' => count($rows), 'total_min' => $total, 'avg_min' => $counted ? intdiv($total, count($counted)) : null, 'items' => $rows];
    }

    // ---- Classes, PT and resources ----

    public function classes(Request $req): array
    {
        $date = V::check(['d' => $req->q('date', Util::today())], ['d' => 'date'])['d'];
        $dow = (int)date('w', strtotime($date));
        $rows = DB::all("SELECT c.id, c.name, c.start_time, c.duration_min, c.room, c.capacity, u.name AS coach, b.name AS branch,
              (SELECT COUNT(*) FROM class_bookings x WHERE x.class_id = c.id AND x.class_date = ? AND x.status IN ('booked','attended')) AS booked,
              (SELECT x.status FROM class_bookings x WHERE x.class_id = c.id AND x.class_date = ? AND x.member_id = ?) AS my_status,
              (SELECT x.id FROM class_bookings x WHERE x.class_id = c.id AND x.class_date = ? AND x.member_id = ?) AS my_booking_id,
              (SELECT x.waitlist_pos FROM class_bookings x WHERE x.class_id = c.id AND x.class_date = ? AND x.member_id = ?) AS my_waitlist_pos
            FROM classes c LEFT JOIN users u ON u.id = c.coach_id JOIN branches b ON b.id = c.branch_id WHERE c.gym_id = ? AND c.day_of_week = ? AND c.is_active = 1 ORDER BY c.start_time",
            [$date, $date, $this->mid(), $date, $this->mid(), $date, $this->mid(), $this->gym(), $dow]);
        $pt = DB::all("SELECT p.id, p.starts_at, p.duration_min, p.status, u.name AS trainer FROM pt_appointments p JOIN users u ON u.id = p.trainer_id WHERE p.member_id = ? AND p.starts_at >= CURDATE() AND p.status = 'booked' ORDER BY p.starts_at", [$this->mid()]);
        $ptLeft = DB::val("SELECT sessions_total - sessions_used FROM memberships WHERE member_id = ? AND kind = 'pt' AND status = 'active' ORDER BY end_date DESC LIMIT 1", [$this->mid()]);
        return ['date' => $date, 'classes' => $rows, 'pt' => $pt, 'pt_left' => $ptLeft !== null ? (int)$ptLeft : null,
            'trainers' => DB::all("SELECT id, name, title FROM users WHERE gym_id = ? AND role = 'trainer' AND status = 'active'", [$this->gym()])];
    }

    public function bookClass(Request $req): array
    {
        $c = $this->find('classes', $req->id(), 'Class');
        $date = V::check($req->json(), ['date' => 'required|date'])['date'];
        return ClassesController::book($this->gym(), $c, $date, $this->me());
    }

    public function cancelClass(Request $req): array
    {
        $b = DB::one('SELECT * FROM class_bookings WHERE id = ? AND member_id = ?', [$req->id(), $this->mid()]);
        if (!$b || !in_array($b['status'], ['booked', 'waitlist'], true)) throw HttpException::notFound('Booking not found');
        return ClassesController::cancelBooking($this->gym(), $b);
    }

    public function ptSlots(Request $req): array
    {
        $d = V::check($req->query, ['trainer_id' => 'required|int', 'date' => 'required|date']);
        $this->assertStaff($d['trainer_id']);
        $busy = DB::all("SELECT starts_at, duration_min FROM pt_appointments WHERE trainer_id = ? AND DATE(starts_at) = ? AND status = 'booked'", [$d['trainer_id'], $d['date']]);
        $b = DB::one('SELECT open_time, close_time FROM branches WHERE gym_id = ? ORDER BY id LIMIT 1', [$this->gym()]);
        $slots = [];
        for ($t = strtotime("{$d['date']} {$b['open_time']}"); $t + 3600 <= strtotime("{$d['date']} {$b['close_time']}"); $t += 3600) {
            if ($t < time() + 3600) continue;
            $free = true;
            foreach ($busy as $x) { $s = strtotime($x['starts_at']); if ($t < $s + $x['duration_min'] * 60 && $t + 3600 > $s) { $free = false; break; } }
            if ($free) $slots[] = date('Y-m-d H:i:s', $t);
        }
        return $slots;
    }

    public function bookPt(Request $req): array
    {
        $d = V::check($req->json(), ['trainer_id' => 'required|int', 'starts_at' => 'required|datetime']);
        $this->assertStaff($d['trainer_id']);
        if ($d['starts_at'] < date('Y-m-d H:i:s', time() + 3600)) throw HttpException::bad('Book at least an hour ahead');
        return ['id' => ClassesController::bookPt($this->gym(), $this->mid(), $d['trainer_id'], $d['starts_at'])];
    }

    public function cancelPt(Request $req): array
    {
        $p = DB::one("SELECT * FROM pt_appointments WHERE id = ? AND member_id = ? AND status = 'booked'", [$req->id(), $this->mid()]);
        if (!$p) throw HttpException::notFound('Session not found');
        if (strtotime($p['starts_at']) - time() < 3 * 3600) throw HttpException::bad('Cancel at least 3 hours before. Please call the gym.');
        DB::update('pt_appointments', ['status' => 'cancelled'], ['id' => $p['id']]);
        return ['ok' => true];
    }

    public function resources(Request $req): array
    {
        $date = V::check(['d' => $req->q('date', Util::today())], ['d' => 'date'])['d'];
        $res = DB::all('SELECT id, name, slot_minutes, price, open_time, close_time FROM resources WHERE gym_id = ? AND is_active = 1', [$this->gym()]);
        foreach ($res as &$r) $r['taken'] = array_column(DB::all("SELECT starts_at FROM resource_bookings WHERE resource_id = ? AND DATE(starts_at) = ? AND status = 'booked'", [$r['id'], $date]), 'starts_at');
        return ['date' => $date, 'items' => $res];
    }

    public function bookResource(Request $req): array
    {
        $r = $this->find('resources', $req->id(), 'Resource');
        $at = V::check($req->json(), ['starts_at' => 'required|datetime'])['starts_at'];
        return ['id' => ClassesController::bookResource($this->gym(), $r, $this->me(), $at)];
    }

    // ---- Workouts, diet, progress ----

    public function workout(Request $req): array
    {
        $plan = WorkoutsController::planFor($this->gym(), $this->mid());
        $last = [];
        foreach (DB::all('SELECT exercise_id, sets, reps, weight, log_date FROM workout_logs WHERE id IN (SELECT MAX(id) FROM workout_logs WHERE member_id = ? GROUP BY exercise_id)', [$this->mid()]) as $l) $last[$l['exercise_id']] = $l;
        $todayLogs = DB::all('SELECT exercise_id, sets, reps, weight FROM workout_logs WHERE member_id = ? AND log_date = CURDATE()', [$this->mid()]);
        return ['plan' => $plan, 'last' => $last, 'today' => $todayLogs];
    }

    public function logWorkout(Request $req): array
    {
        $d = V::check($req->json(), ['exercise_id' => 'required|int', 'sets' => 'required|int|min:1|max:20', 'reps' => 'required|int|min:1|max:200', 'weight' => 'nullable|num|min:0|max:500']);
        if (!DB::val('SELECT id FROM exercises WHERE id = ? AND (gym_id IS NULL OR gym_id = ?)', [$d['exercise_id'], $this->gym()])) throw HttpException::bad('Unknown exercise');
        DB::insert('workout_logs', $d + ['gym_id' => $this->gym(), 'member_id' => $this->mid(), 'log_date' => Util::today()]);
        // Personal record if this is the heaviest weight logged for the exercise.
        $pr = false;
        if (!empty($d['weight'])) {
            $max = (float)DB::val('SELECT MAX(weight) FROM workout_logs WHERE member_id = ? AND exercise_id = ? AND log_date < CURDATE()', [$this->mid(), $d['exercise_id']]);
            if ($d['weight'] > $max) {
                DB::insert('personal_records', ['gym_id' => $this->gym(), 'member_id' => $this->mid(), 'exercise_id' => $d['exercise_id'], 'value' => $d['weight'] . ' kg × ' . $d['reps'], 'achieved_on' => Util::today()]);
                $pr = true;
            }
        }
        return ['ok' => true, 'personal_record' => $pr];
    }

    public function diet(Request $req): array
    {
        $date = V::check(['d' => $req->q('date', Util::today())], ['d' => 'date'])['d'];
        $plan = WorkoutsController::dietFor($this->gym(), $this->mid());
        $done = $plan ? array_map('intval', array_column(DB::all('SELECT meal_id FROM diet_logs WHERE member_id = ? AND log_date = ?', [$this->mid(), $date]), 'meal_id')) : [];
        return ['date' => $date, 'plan' => $plan, 'done' => $done];
    }

    public function toggleMeal(Request $req): array
    {
        $d = V::check($req->json(), ['meal_id' => 'required|int', 'date' => 'nullable|date', 'done' => 'required|bool']);
        $ok = DB::val('SELECT dm.id FROM diet_meals dm JOIN diet_plans dp ON dp.id = dm.diet_plan_id WHERE dm.id = ? AND dp.member_id = ? AND dp.gym_id = ?', [$d['meal_id'], $this->mid(), $this->gym()]);
        if (!$ok) throw HttpException::notFound('Meal not found');
        $date = $d['date'] ?? Util::today();
        if ($date > Util::today()) throw HttpException::bad('Cannot log future meals');
        if ($d['done']) DB::run('INSERT IGNORE INTO diet_logs (member_id, meal_id, log_date) VALUES (?,?,?)', [$this->mid(), $d['meal_id'], $date]);
        else DB::run('DELETE FROM diet_logs WHERE member_id = ? AND meal_id = ? AND log_date = ?', [$this->mid(), $d['meal_id'], $date]);
        return ['ok' => true];
    }

    public function progress(Request $req): array
    {
        $id = $this->mid();
        return ['assessments' => DB::all('SELECT assessed_on, weight_kg, body_fat_pct, waist_in, chest_in, arm_in, bmi, photo_path IS NOT NULL AS has_photo, id FROM assessments WHERE member_id = ? AND gym_id = ? ORDER BY assessed_on', [$id, $this->gym()]),
            'records' => DB::all('SELECT pr.value, pr.achieved_on, e.name FROM personal_records pr JOIN exercises e ON e.id = pr.exercise_id WHERE pr.member_id = ? AND pr.id IN (SELECT MAX(id) FROM personal_records WHERE member_id = ? GROUP BY exercise_id) ORDER BY pr.achieved_on DESC LIMIT 10', [$id, $id]),
            'joined' => substr((string)DB::val('SELECT created_at FROM members WHERE id = ?', [$id]), 0, 10)];
    }

    public function logWeight(Request $req): array
    {
        $d = V::check($req->json(), ['weight_kg' => 'required|num|min:20|max:300', 'photo' => 'nullable|string']);
        $h = DB::val('SELECT height_cm FROM assessments WHERE member_id = ? AND height_cm IS NOT NULL ORDER BY assessed_on DESC LIMIT 1', [$this->mid()]);
        $row = ['gym_id' => $this->gym(), 'member_id' => $this->mid(), 'assessed_on' => Util::today(), 'weight_kg' => $d['weight_kg'], 'notes' => 'Logged in app',
            'bmi' => $h ? round($d['weight_kg'] / (($h / 100) ** 2), 1) : null];
        if (!empty($d['photo'])) $row['photo_path'] = Upload::store($this->gym(), $d['photo'], 4_000_000, ['image/jpeg', 'image/png', 'image/webp']);
        return ['id' => DB::insert('assessments', $row)];
    }

    public function progressPhoto(Request $req): Response
    {
        $a = DB::one('SELECT photo_path FROM assessments WHERE id = ? AND member_id = ?', [$req->id(), $this->mid()]);
        if (!$a) throw HttpException::notFound();
        return Upload::stream($this->gym(), $a['photo_path']);
    }

    public function challenges(Request $req): array
    {
        $rows = DB::all('SELECT * FROM challenges WHERE gym_id = ? AND end_date >= CURDATE() - INTERVAL 7 DAY ORDER BY end_date', [$this->gym()]);
        foreach ($rows as &$c) {
            $board = CampaignsController::leaderboard($this->gym(), $c, 50);
            $mine = null; $rank = 0; $public = [];
            foreach ($board as $i => $b) {
                if ((int)$b['id'] === $this->mid()) { $mine = (float)$b['score']; $rank = $i + 1; }
                $public[] = ['rank' => $i + 1, 'name' => (int)$b['id'] === $this->mid() ? 'You' : ($b['leaderboard_opt_in'] ? $b['name'] : 'Member'), 'score' => (float)$b['score'], 'me' => (int)$b['id'] === $this->mid()];
            }
            $c['leaderboard'] = array_slice($public, 0, 10);
            $c['my_score'] = $mine ?? 0;
            $c['my_rank'] = $rank ?: null;
            $c['days_left'] = max(0, Util::daysBetween(Util::today(), $c['end_date']));
        }
        return ['challenges' => $rows, 'announcements' => DB::all('SELECT title, body, created_at FROM announcements WHERE gym_id = ? AND (visible_until IS NULL OR visible_until >= CURDATE()) ORDER BY id DESC LIMIT 10', [$this->gym()])];
    }

    // ---- Pay and renew ----

    public function plans(Request $req): array
    {
        $cur = $this->currentPlan();
        return ['current' => $cur ? ['plan_id' => (int)$cur['plan_id'], 'name' => $cur['plan_name'], 'end_date' => $cur['end_date'], 'autopay' => (bool)$cur['autopay']] : null,
            'plans' => DB::all("SELECT id, name, kind, duration_days, price, gst_rate, description, sessions_count FROM plans WHERE gym_id = ? AND is_active = 1 ORDER BY kind, price", [$this->gym()]),
            'credit_balance' => (float)$this->me()['credit_balance'], 'prices_include_gst' => (bool)Util::setting('prices_include_gst', false),
            'invoices' => DB::all('SELECT id, invoice_no, invoice_date, total, balance, status FROM invoices WHERE member_id = ? AND gym_id = ? ORDER BY id DESC LIMIT 20', [$this->mid(), $this->gym()])];
    }

    /** Create the renewal invoice, then return a Razorpay order for Checkout. */
    public function renew(Request $req): array
    {
        $d = V::check($req->json(), ['plan_id' => 'required|int', 'coupon' => 'nullable|string|max:30', 'use_credit' => 'nullable|bool', 'autopay' => 'nullable|bool']);
        $plan = DB::one('SELECT * FROM plans WHERE id = ? AND gym_id = ? AND is_active = 1', [$d['plan_id'], $this->gym()]);
        if (!$plan) throw HttpException::bad('Choose a valid plan');
        // Re-use an unpaid renewal invoice for the same plan instead of piling up duplicates.
        $pending = DB::one("SELECT ms.id, ms.invoice_id FROM memberships ms JOIN invoices i ON i.id = ms.invoice_id WHERE ms.member_id = ? AND ms.plan_id = ? AND i.status = 'unpaid' AND i.created_at > NOW() - INTERVAL 1 DAY ORDER BY ms.id DESC LIMIT 1", [$this->mid(), $plan['id']]);
        if ($pending) { $invoiceId = (int)$pending['invoice_id']; $msId = (int)$pending['id']; }
        else { $r = Memberships::sell($this->gym(), $this->mid(), (int)$plan['id'], ['coupon' => $d['coupon'] ?? null, 'autopay' => $d['autopay'] ?? false]); $invoiceId = $r['invoice_id']; $msId = $r['membership_id']; }
        if (!empty($d['use_credit']) && (float)$this->me()['credit_balance'] > 0) {
            $bal = (float)DB::val('SELECT balance FROM invoices WHERE id = ?', [$invoiceId]);
            $use = min($bal, (float)DB::val('SELECT credit_balance FROM members WHERE id = ?', [$this->mid()]));
            if ($use > 0) Billing::recordPayment($this->gym(), $invoiceId, $use, 'credit', 'Referral credit');
        }
        $inv = DB::one('SELECT id, invoice_no, total, balance FROM invoices WHERE id = ?', [$invoiceId]);
        $order = (float)$inv['balance'] > 0 ? Razorpay::order($this->gym(), $invoiceId) : null;
        return ['invoice' => $inv, 'membership_id' => $msId, 'order' => $order, 'member' => ['name' => $this->me()['name'], 'phone' => $this->me()['phone'], 'email' => $this->me()['email']]];
    }

    public function payInvoice(Request $req): array
    {
        $inv = DB::one("SELECT id, invoice_no, total, balance FROM invoices WHERE id = ? AND member_id = ? AND status IN ('unpaid','partial')", [$req->id(), $this->mid()]);
        if (!$inv) throw HttpException::notFound('Invoice not found');
        return ['invoice' => $inv, 'order' => Razorpay::order($this->gym(), (int)$inv['id']), 'member' => ['name' => $this->me()['name'], 'phone' => $this->me()['phone'], 'email' => $this->me()['email']]];
    }

    public function verifyPayment(Request $req): array
    {
        $d = V::check($req->json(), ['invoice_id' => 'required|int', 'razorpay_order_id' => 'nullable|string|max:60', 'razorpay_payment_id' => 'nullable|string|max:60', 'razorpay_signature' => 'nullable|string|max:200', 'demo' => 'nullable|bool']);
        $inv = DB::one('SELECT * FROM invoices WHERE id = ? AND member_id = ?', [$d['invoice_id'], $this->mid()]);
        if (!$inv) throw HttpException::notFound('Invoice not found');
        if (!empty($d['demo'])) {
            if (!Util::isDemoGym($this->gym()) || Razorpay::configured($this->gym())) throw HttpException::bad('Demo payments are off');
            if ((float)$inv['balance'] > 0) Billing::recordPayment($this->gym(), (int)$inv['id'], (float)$inv['balance'], 'online', 'DEMO-UPI', 'demo_' . $inv['id'] . '_' . bin2hex(random_bytes(4)));
        } else {
            if (empty($d['razorpay_order_id']) || empty($d['razorpay_payment_id']) || empty($d['razorpay_signature'])) throw HttpException::bad('Missing payment details');
            Razorpay::verifyCheckout($this->gym(), (int)$inv['id'], $d['razorpay_order_id'], $d['razorpay_payment_id'], $d['razorpay_signature']);
        }
        $ms = DB::one('SELECT id, autopay FROM memberships WHERE invoice_id = ?', [$inv['id']]);
        $autopayUrl = null;
        if ($ms && $ms['autopay']) { try { $autopayUrl = Razorpay::subscribe($this->gym(), (int)$ms['id'])['short_url'] ?? null; } catch (\Throwable) {} }
        return ['ok' => true, 'invoice' => DB::one('SELECT id, invoice_no, total, paid, balance, status FROM invoices WHERE id = ?', [$inv['id']]), 'autopay_url' => $autopayUrl];
    }

    public function invoicePrint(Request $req): Response
    {
        $ok = DB::val('SELECT id FROM invoices WHERE id = ? AND member_id = ?', [$req->id(), $this->mid()]);
        if (!$ok) throw HttpException::notFound();
        return Response::text(Billing::invoiceHtml($this->gym(), $req->id()), 200, 'text/html');
    }

    // ---- Store ----

    public function store(Request $req): array
    {
        return ['items' => DB::all("SELECT id, name, category, price, gst_rate, stock, track_stock FROM products WHERE gym_id = ? AND is_active = 1 AND sell_online = 1 ORDER BY category, name", [$this->gym()]),
            'orders' => DB::all("SELECT i.id, i.invoice_no, i.total, i.status, i.created_at, (SELECT GROUP_CONCAT(description SEPARATOR ', ') FROM invoice_items WHERE invoice_id = i.id) items FROM invoices i WHERE i.member_id = ? AND i.kind = 'store' ORDER BY i.id DESC LIMIT 10", [$this->mid()])];
    }

    public function order(Request $req): array
    {
        $items = $req->input('items');
        if (!is_array($items) || !$items || count($items) > 20) throw HttpException::bad('Add items to your cart');
        foreach ($items as $it) {
            if (!DB::val('SELECT id FROM products WHERE id = ? AND gym_id = ? AND sell_online = 1 AND is_active = 1', [(int)($it['product_id'] ?? 0), $this->gym()])) throw HttpException::bad('A product is not available online');
        }
        $inv = StoreController::sell($this->gym(), $items, $this->mid(), null);
        $row = DB::one('SELECT id, invoice_no, total, balance FROM invoices WHERE id = ?', [$inv]);
        \App\Services\Notify::staff($this->gym(), 'App order ' . $row['invoice_no'], $this->me()['name'] . ' ordered from the store. Collect at the desk.', 'store');
        $order = null;
        try { $order = Razorpay::order($this->gym(), $inv); } catch (HttpException) {}
        return ['invoice' => $row, 'order' => $order, 'member' => ['name' => $this->me()['name'], 'phone' => $this->me()['phone'], 'email' => $this->me()['email']]];
    }

    // ---- Waiver, referral, notifications, push ----

    public function waiver(Request $req): array
    {
        $sig = (string)$req->input('signature');
        $path = Upload::store($this->gym(), $sig, 500_000, ['image/png']);
        DB::update('members', ['waiver_signed_at' => date('Y-m-d H:i:s'), 'waiver_signature_path' => $path], ['id' => $this->mid()]);
        Util::audit('member.waiver', 'member', $this->mid());
        return ['ok' => true];
    }

    public function referral(Request $req): array
    {
        $m = $this->me();
        return ['code' => $m['member_code'], 'credit_balance' => (float)$m['credit_balance'], 'credit_per_referral' => (float)Util::setting('rules.referral_credit', 0),
            'referred' => DB::all('SELECT name, created_at FROM members WHERE referred_by = ? AND deleted_at IS NULL ORDER BY id DESC', [$m['id']]),
            'history' => DB::all('SELECT amount, reason, created_at FROM member_credits WHERE member_id = ? ORDER BY id DESC LIMIT 20', [$m['id']])];
    }

    public function refer(Request $req): array
    {
        $d = V::check($req->json(), ['name' => 'required|string|max:120', 'phone' => 'required|phone']);
        RateLimit::hit('refer:' . $this->mid(), 10, 86400);
        $id = DB::insert('leads', ['gym_id' => $this->gym(), 'name' => $d['name'], 'phone' => $d['phone'], 'source' => 'referral', 'interest' => 'Referred by ' . $this->me()['name'] . ' (' . $this->me()['member_code'] . ')',
            'next_followup_at' => date('Y-m-d H:i:s', time() + 3600)]);
        \App\Services\Notify::staff($this->gym(), 'New referral: ' . $d['name'], 'From ' . $this->me()['name'], 'leads');
        return ['id' => $id];
    }

    public function notifications(Request $req): array
    {
        $items = DB::all("SELECT id, title, body, link, created_at, read_at FROM notifications WHERE audience = 'member' AND member_id = ? ORDER BY id DESC LIMIT 30", [$this->mid()]);
        DB::run("UPDATE notifications SET read_at = NOW() WHERE audience = 'member' AND member_id = ? AND read_at IS NULL", [$this->mid()]);
        return $items;
    }

    public function pushSubscribe(Request $req): array
    {
        $sub = $req->input('subscription');
        if (!is_array($sub) || !preg_match('#^https://#', (string)($sub['endpoint'] ?? ''))) throw HttpException::bad('Invalid subscription');
        DB::update('members', ['push_subscription' => json_encode(['endpoint' => $sub['endpoint']])], ['id' => $this->mid()]);
        return ['ok' => true];
    }

    // ---- Strava (wearables) ----

    public function stravaConnect(Request $req): array
    {
        $cid = Util::setting('integrations.strava_client_id');
        if (!$cid) throw HttpException::bad('Strava is not enabled at this gym');
        $state = $this->mid() . '.' . time() . '.' . substr(hash_hmac('sha256', $this->mid() . '.' . time(), (string)Config::get('app_key')), 0, 24);
        $cb = rtrim((string)Config::get('app_url'), '/') . '/api/m/strava/callback';
        return ['url' => 'https://www.strava.com/oauth/authorize?' . http_build_query(['client_id' => $cid, 'redirect_uri' => $cb, 'response_type' => 'code', 'scope' => 'activity:read', 'approval_prompt' => 'auto', 'state' => $state])];
    }

    public function stravaCallback(Request $req): Response
    {
        $state = (string)$req->q('state');
        $app = rtrim((string)Config::get('app_url'), '/');
        if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{24})$/', $state, $m) || time() - (int)$m[2] > 900
            || !hash_equals(substr(hash_hmac('sha256', $m[1] . '.' . $m[2], (string)Config::get('app_key')), 0, 24), $m[3]) || (int)$m[1] !== $this->mid()) {
            return new Response(302, '', ['Location' => "$app/m/?strava=error"], true);
        }
        $g = $this->gym();
        [$code, $res] = Http::json('POST', 'https://www.strava.com/oauth/token', ['client_id' => Util::setting('integrations.strava_client_id'), 'client_secret' => Util::secret('strava_client_secret'),
            'code' => (string)$req->q('code'), 'grant_type' => 'authorization_code']);
        if ($code !== 200) return new Response(302, '', ['Location' => "$app/m/?strava=error"], true);
        DB::update('members', ['strava_tokens' => Util::encrypt(json_encode(['access' => $res['access_token'], 'refresh' => $res['refresh_token'], 'expires' => $res['expires_at']]))], ['id' => $this->mid()]);
        return new Response(302, '', ['Location' => "$app/m/" . Util::gym()['slug'] . '/progress?strava=connected'], true);
    }

    public function stravaActivities(Request $req): array
    {
        $raw = $this->me()['strava_tokens'];
        if (!$raw) return ['connected' => false, 'items' => []];
        $t = json_decode((string)Util::decrypt($raw), true);
        if ($t['expires'] < time() + 60) {
            [$code, $res] = Http::json('POST', 'https://www.strava.com/oauth/token', ['client_id' => Util::setting('integrations.strava_client_id'), 'client_secret' => Util::secret('strava_client_secret'),
                'refresh_token' => $t['refresh'], 'grant_type' => 'refresh_token']);
            if ($code !== 200) return ['connected' => false, 'items' => []];
            $t = ['access' => $res['access_token'], 'refresh' => $res['refresh_token'], 'expires' => $res['expires_at']];
            DB::update('members', ['strava_tokens' => Util::encrypt(json_encode($t))], ['id' => $this->mid()]);
        }
        [$code, $res] = Http::json('GET', 'https://www.strava.com/api/v3/athlete/activities?per_page=10', null, ['Authorization: Bearer ' . $t['access']]);
        if ($code !== 200 || !is_array($res)) return ['connected' => true, 'items' => []];
        return ['connected' => true, 'items' => array_map(fn($a) => ['name' => $a['name'] ?? '', 'type' => $a['sport_type'] ?? $a['type'] ?? '', 'date' => $a['start_date_local'] ?? '',
            'distance_km' => round(($a['distance'] ?? 0) / 1000, 2), 'moving_min' => intdiv((int)($a['moving_time'] ?? 0), 60)], array_filter($res, 'is_array'))];
    }

    public function stravaDisconnect(Request $req): array
    {
        DB::update('members', ['strava_tokens' => null], ['id' => $this->mid()]);
        return ['ok' => true];
    }
}
