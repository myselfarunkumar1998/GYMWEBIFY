<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, DB, Util};

/**
 * Builds the demo gym with realistic data relative to today, so the demo always looks current.
 * Logins (password for all staff: Demo@12345):
 *   owner@demo.gym  (owner)   ·  manager@demo.gym  ·  desk@demo.gym (front desk)  ·  trainer@demo.gym (Vijay)
 *   Member app: gym "demo", phone 9876543210 (OTP shown on screen in demo mode)
 */
final class DemoSeed
{
    public const PASSWORD = 'Demo@12345';

    private const FIRST = ['Karthik', 'Priya', 'Arjun', 'Divya', 'Suresh', 'Meena', 'Naveen', 'Lakshmi', 'Vignesh', 'Anitha', 'Ramesh', 'Fathima', 'Gokul', 'Sowmya', 'Imran',
        'Bala', 'Harish', 'Keerthi', 'Manoj', 'Pooja', 'Senthil', 'Revathi', 'Ajay', 'Nandhini', 'Prakash', 'Deepika', 'Sathish', 'Gayathri', 'Rahul', 'Swathi', 'Dinesh',
        'Kavitha', 'Arun', 'Janani', 'Vinoth', 'Sangeetha', 'Mohan', 'Aishwarya', 'Saravanan', 'Pavithra', 'Ashok', 'Sneha', 'Kumar', 'Bhavani', 'Hari', 'Ranjani', 'Selvam', 'Yamini'];
    private const LAST = ['S', 'R', 'M', 'K', 'P', 'V', 'N', 'T', 'J', 'B', 'A', 'G', 'D', 'L', 'Subramaniam', 'Krishnan', 'Raj', 'Kumar', 'Natarajan', 'Pillai'];

    public static function run(): array
    {
        mt_srand(42);
        Provision::seedExercises();
        $existing = DB::val("SELECT id FROM gyms WHERE slug = 'demo'");
        if ($existing) { self::purge((int)$existing); DB::run('DELETE FROM gyms WHERE id = ?', [$existing]); }
        foreach (['owner@demo.gym', 'manager@demo.gym', 'desk@demo.gym', 'trainer@demo.gym', 'kavya@demo.gym', 'rahul@demo.gym', 'nisha@demo.gym', 'sathish@demo.gym'] as $e) DB::run('DELETE FROM users WHERE email = ?', [$e]);

        $r = Provision::createGym(['name' => 'FitZone Chennai', 'slug' => 'demo', 'legal_name' => 'FitZone Fitness Studio LLP', 'gstin' => '33ABCDE1234F1Z5', 'state_code' => '33',
            'address' => '12, 2nd Avenue, Anna Nagar, Chennai 600040', 'phone' => '9840012345', 'email' => 'hello@fitzone.demo', 'subscription_plan' => 'multi_branch',
            'subscription_until' => date('Y-m-d', strtotime('+1 year'))],
            ['name' => 'Ravi Kumar', 'email' => 'owner@demo.gym', 'password' => self::PASSWORD, 'phone' => '9840011111'],
            ['name' => 'Anna Nagar', 'address' => '12, 2nd Avenue, Anna Nagar, Chennai 600040', 'capacity' => 70]);
        $g = $r['gym_id'];
        Auth::reset(); Auth::$gymId = $g; Util::forgetGym();
        $an = $r['branch_id'];
        DB::update('branches', ['open_time' => '05:00:00', 'close_time' => '22:00:00', 'staffed_from' => '05:00:00', 'staffed_to' => '22:00:00', 'phone' => '9840012345'], ['id' => $an]);
        $vel = DB::insert('branches', ['gym_id' => $g, 'name' => 'Velachery', 'address' => '45, 100 Feet Road, Velachery, Chennai 600042', 'open_time' => '05:30:00', 'close_time' => '22:00:00',
            'staffed_from' => '06:00:00', 'staffed_to' => '21:30:00', 'capacity' => 50, 'phone' => '9840054321']);
        $s = Util::gym($g)['settings'];
        $s['google_review_url'] = 'https://g.page/r/demo-fitzone/review';
        $s['website']['about'] = 'Strength, cardio and group classes in Anna Nagar and Velachery. Fingerprint entry, trainers on every shift, and an app to track your workouts.';
        DB::update('gyms', ['settings' => json_encode($s)], ['id' => $g]);
        Util::forgetGym();
        $owner = $r['owner_id'];
        DB::update('users', ['device_user_id' => '1', 'shift_start' => '06:00:00'], ['id' => $owner]);

        $hash = Auth::hashPassword(self::PASSWORD);
        $staff = [];
        foreach ([
            ['Deepa', 'manager@demo.gym', 'manager', 'Manager', $an, 32000, 0, 2, '05:45:00', '9840022222'],
            ['Vijay', 'trainer@demo.gym', 'trainer', 'Head trainer', $an, 30000, 15, 0, '05:45:00', '9840033333'],
            ['Kavya', 'kavya@demo.gym', 'trainer', 'Trainer', $an, 24000, 15, 0, '07:30:00', '9840044444'],
            ['Rahul', 'rahul@demo.gym', 'trainer', 'Trainer', $vel, 24000, 15, 0, '16:00:00', '9840055555'],
            ['Nisha', 'nisha@demo.gym', 'trainer', 'Zumba coach', $an, 18000, 0, 0, '06:15:00', '9840066666'],
            ['Saravanan', 'desk@demo.gym', 'front_desk', 'Front desk', $an, 16000, 0, 1, '05:45:00', '9840077777'],
            ['Sathish', 'sathish@demo.gym', 'manager', 'Branch manager', $vel, 28000, 0, 2, '06:00:00', '9840088888'],
        ] as $i => [$n, $e, $role, $title, $b, $sal, $ptc, $sc, $shift, $ph]) {
            $staff[$n] = DB::insert('users', ['gym_id' => $g, 'branch_id' => $b, 'name' => $n, 'email' => $e, 'phone' => $ph, 'password_hash' => $hash, 'role' => $role, 'title' => $title,
                'salary' => $sal, 'pt_commission_pct' => $ptc, 'sales_commission_pct' => $sc, 'device_user_id' => (string)($i + 2), 'shift_start' => $shift]);
        }

        // Plans (prices exclude GST)
        $plans = [];
        foreach ([
            ['Monthly', 'membership', 30, 1500, 0, 0, null, null, 0, '30 days, gym + cardio'],
            ['Quarterly', 'membership', 90, 4000, 0, 7, null, null, 0, '90 days, gym + cardio'],
            ['Half-yearly', 'membership', 180, 7000, 0, 15, null, null, 0, '180 days, gym + cardio'],
            ['Yearly', 'membership', 365, 12000, 0, 30, null, null, 1, 'All hours, both branches'],
            ['Couple · Yearly', 'membership', 365, 21000, 0, 30, null, null, 1, 'Two people, both branches'],
            ['Student · Monthly', 'membership', 30, 1100, 0, 0, null, null, 0, 'With college ID'],
            ['Off-peak · Monthly', 'membership', 30, 999, 0, 0, '11:00:00', '16:00:00', 0, '11 AM – 4 PM only'],
            ['PT pack · 12 sessions', 'pt', 45, 6000, 12, 0, null, null, 0, 'Use within 45 days'],
            ['PT pack · 24 sessions', 'pt', 90, 11000, 24, 0, null, null, 0, 'Use within 90 days'],
        ] as [$n, $k, $dur, $price, $sess, $fz, $as, $ae, $all, $desc]) {
            $plans[$n] = DB::insert('plans', ['gym_id' => $g, 'name' => $n, 'kind' => $k, 'duration_days' => $dur, 'price' => $price, 'sessions_count' => $sess, 'freeze_days_allowed' => $fz,
                'access_start' => $as, 'access_end' => $ae, 'all_branches' => $all, 'description' => $desc, 'max_members' => $n === 'Couple · Yearly' ? 2 : 1]);
        }
        DB::insert('coupons', ['gym_id' => $g, 'code' => 'DIWALI20', 'kind' => 'percent', 'value' => 20, 'valid_from' => Util::addDays(Util::today(), -10), 'valid_to' => Util::addDays(Util::today(), 20), 'max_uses' => 50]);
        DB::insert('coupons', ['gym_id' => $g, 'code' => 'FRIEND500', 'kind' => 'flat', 'value' => 500, 'is_active' => 1]);

        // Members with plan history
        $today = Util::today();
        $memberIds = [];
        $special = [ // members referenced in the designs
            ['Karthik Subramaniam', '9876543210', 'Yearly', -240, $an, 'Vijay', 'male', 'Weight loss', 'regular'],
            ['Priya R', '9876500455', 'Quarterly', -20, $an, null, 'female', 'General fitness', 'regular'],
            ['Arjun M', '9876500781', 'Monthly', -27, $an, null, 'male', 'Muscle gain', 'regular'],
            ['Divya K', '9876500032', 'Monthly', -12, $an, 'Kavya', 'female', 'Weight loss', 'regular'],
            ['Suresh P', '9876500618', 'Half-yearly', -45, $an, 'Vijay', 'male', 'Muscle gain', 'regular'],
            ['Meena V', '9876500340', 'Quarterly', -15, $an, null, 'female', 'General fitness', 'dues'],
            ['Lakshmi N', '9876500127', 'Quarterly', -87, $an, 'Kavya', 'female', 'Weight loss', 'fading'],
            ['Ramesh B', '9876500904', 'Yearly', -130, $an, 'Vijay', 'male', 'General fitness', 'gone'],
            ['Fathima S', '9876500266', 'Monthly', -18, $an, null, 'female', 'Weight loss', 'frozen'],
            ['Gokul V', '9876500573', 'Monthly', -38, $an, null, 'male', 'Muscle gain', 'expired'],
            ['Vignesh T', '9876500530', 'Yearly', -350, $an, 'Vijay', 'male', 'Muscle gain', 'regular'],
            ['Naveen R', '9876500322', 'Half-yearly', -60, $vel, 'Rahul', 'male', 'Muscle gain', 'regular'],
            ['Sowmya D', '9876501017', 'Monthly', -16, $vel, null, 'female', 'Weight loss', 'fading'],
            ['Imran A', '9876500844', 'Quarterly', -50, $vel, 'Rahul', 'male', 'General fitness', 'gone'],
        ];
        $pool = [];
        for ($i = 0; $i < 130; $i++) {
            $name = self::FIRST[$i % count(self::FIRST)] . ' ' . self::LAST[mt_rand(0, count(self::LAST) - 1)];
            $planName = ['Monthly', 'Monthly', 'Quarterly', 'Quarterly', 'Half-yearly', 'Yearly', 'Student · Monthly', 'Off-peak · Monthly'][mt_rand(0, 7)];
            $dur = DB::val('SELECT duration_days FROM plans WHERE id = ?', [$plans[$planName]]);
            $startOffset = -mt_rand(0, (int)$dur + 60);
            $pattern = ['regular', 'regular', 'regular', 'regular', 'casual', 'casual', 'fading', 'gone'][mt_rand(0, 7)];
            if ($startOffset + $dur < -3) $pattern = 'expired';
            $pool[] = [$name, '9' . str_pad((string)mt_rand(100000000, 899999999), 9, '0'), $planName, $startOffset, mt_rand(0, 9) < 6 ? $an : $vel,
                mt_rand(0, 4) === 0 ? ['Vijay', 'Kavya', 'Rahul'][mt_rand(0, 2)] : null, mt_rand(0, 1) ? 'male' : 'female', ['Weight loss', 'Muscle gain', 'General fitness'][mt_rand(0, 2)], $pattern];
        }
        $seq = 1000;
        $all = array_merge($special, $pool);
        $phones = [];
        foreach ($all as $idx => [$name, $phone, $planName, $startOffset, $branch, $trainer, $gender, $goal, $pattern]) {
            if (isset($phones[$phone])) continue;
            $phones[$phone] = 1;
            $seq++;
            $joined = Util::addDays($today, $startOffset - ($idx < 14 && $startOffset > -100 ? mt_rand(30, 200) : 0));
            $mid = DB::insert('members', ['gym_id' => $g, 'branch_id' => $branch, 'member_code' => 'M-' . $seq, 'name' => $name, 'phone' => $phone,
                'email' => strtolower(strtok($name, ' ')) . $seq . '@example.com', 'dob' => date('Y-m-d', strtotime('-' . mt_rand(19, 52) . ' years -' . mt_rand(0, 364) . ' days')),
                'gender' => $gender, 'goal' => $goal, 'source' => ['Walk-in', 'Instagram ad', 'Referral', 'Google'][mt_rand(0, 3)], 'language' => mt_rand(0, 2) ? 'en' : 'ta',
                'trainer_id' => $trainer ? $staff[$trainer] : null, 'device_user_id' => (string)$seq, 'fingerprint_enrolled' => mt_rand(0, 9) > 0 ? 1 : 0, 'face_enrolled' => mt_rand(0, 6) === 0 ? 1 : 0,
                'qr_secret' => bin2hex(random_bytes(16)), 'waiver_signed_at' => $joined . ' 09:00:00', 'created_at' => $joined . ' 09:00:00', 'state_code' => '33',
                'emergency_contact' => 'Family · 98400' . mt_rand(10000, 99999), 'address' => ['Anna Nagar', 'Velachery', 'Mogappair', 'Kilpauk', 'Adyar', 'T Nagar'][mt_rand(0, 5)] . ', Chennai']);
            $memberIds[$name] = $mid;
            if ($idx === 0) DB::update('members', ['language' => 'en'], ['id' => $mid]);

            // Previous plan for older members, then the current plan.
            $planId = $plans[$planName];
            $dur = (int)DB::val('SELECT duration_days FROM plans WHERE id = ?', [$planId]);
            $start = Util::addDays($today, $startOffset);
            if ($startOffset < -$dur + 25 && mt_rand(0, 1)) {
                self::sell($g, $mid, $planId, Util::addDays($start, -$dur), 'paid');
            }
            $payState = $pattern === 'dues' ? 'partial' : ($idx === 0 ? 'paid' : (mt_rand(0, 14) === 0 ? 'unpaid' : 'paid'));
            $ms = self::sell($g, $mid, $planId, $start, $payState, $idx === 0 || mt_rand(0, 4) === 0);
            if ($pattern === 'frozen') {
                DB::update('memberships', ['status' => 'frozen', 'freeze_start' => Util::addDays($today, -3), 'freeze_end' => Util::addDays($today, 20), 'frozen_days_used' => 24,
                    'end_date' => Util::addDays(DB::val('SELECT end_date FROM memberships WHERE id = ?', [$ms]), 24)], ['id' => $ms]);
            }
            if (in_array($name, ['Suresh P', 'Karthik Subramaniam', 'Divya K', 'Naveen R', 'Lakshmi N'], true) || ($trainer && mt_rand(0, 2) === 0)) {
                $pt = self::sell($g, $mid, $plans['PT pack · 12 sessions'], Util::addDays($today, -mt_rand(5, 30)), 'paid');
                $used = $name === 'Lakshmi N' ? 11 : ($name === 'Karthik Subramaniam' || $name === 'Suresh P' ? 4 : mt_rand(1, 9));
                DB::update('memberships', ['sessions_used' => $used], ['id' => $pt]);
            }
            self::attendance($g, $mid, $branch, $joined, $pattern, $name);
        }
        DB::update('members', ['referred_by' => $memberIds['Karthik Subramaniam']], ['id' => $memberIds['Priya R']]);
        DB::run('UPDATE members SET credit_balance = 500 WHERE id = ?', [$memberIds['Karthik Subramaniam']]);
        DB::insert('member_credits', ['gym_id' => $g, 'member_id' => $memberIds['Karthik Subramaniam'], 'amount' => 500, 'reason' => 'Referral: Priya R']);

        // Staff attendance for this month
        foreach ($staff + ['Ravi' => $owner] as $n => $uid) {
            $shift = DB::val('SELECT shift_start FROM users WHERE id = ?', [$uid]) ?: '06:00:00';
            $branch = (int)(DB::val('SELECT branch_id FROM users WHERE id = ?', [$uid]) ?: $an);
            for ($d = 30; $d >= 0; $d--) {
                $day = Util::addDays($today, -$d);
                if (date('w', strtotime($day)) === '0' || mt_rand(0, 12) === 0) continue;
                $in = strtotime("$day $shift") + mt_rand(-15, mt_rand(0, 6) === 0 ? 40 : 10) * 60;
                if ($in > time()) continue;
                $out = $in + mt_rand(7, 9) * 3600;
                DB::insert('attendance_sessions', ['gym_id' => $g, 'branch_id' => $branch, 'user_id' => $uid, 'in_at' => date('Y-m-d H:i:s', $in),
                    'out_at' => $out < time() ? date('Y-m-d H:i:s', $out) : null, 'duration_min' => $out < time() ? intdiv($out - $in, 60) : null, 'method' => 'fingerprint']);
            }
        }

        // Classes
        $classIds = [];
        foreach ([['Zumba', '06:30:00', 'Studio A', 'Nisha', 20, $an, [1, 3, 5]], ['HIIT Burn', '07:30:00', 'Floor 2', 'Vijay', 15, $an, [1, 2, 3, 4, 5, 6]],
                     ['Yoga Flow', '18:00:00', 'Studio A', 'Kavya', 18, $an, [1, 2, 3, 4, 5]], ['Strength 101', '19:30:00', 'Floor 1', 'Rahul', 15, $vel, [1, 2, 3, 4, 5, 6]],
                     ['Spin', '06:00:00', 'Cycle room', 'Rahul', 12, $vel, [2, 4, 6]]] as [$n, $t, $room, $coach, $cap, $b, $days]) {
            foreach ($days as $dow) {
                $classIds[] = DB::insert('classes', ['gym_id' => $g, 'branch_id' => $b, 'name' => $n, 'coach_id' => $staff[$coach], 'room' => $room, 'day_of_week' => $dow, 'start_time' => $t,
                    'duration_min' => 60, 'capacity' => $cap, 'no_show_fee' => 0, 'late_cancel_fee' => $n === 'Spin' ? 100 : 0]);
            }
        }
        $active = array_column(DB::all("SELECT DISTINCT member_id FROM memberships WHERE gym_id = ? AND kind = 'membership' AND status = 'active'", [$g]), 'member_id');
        foreach (DB::all('SELECT * FROM classes WHERE gym_id = ?', [$g]) as $c) {
            for ($d = -14; $d <= 6; $d++) {
                $day = Util::addDays($today, $d);
                if ((int)date('w', strtotime($day)) !== (int)$c['day_of_week']) continue;
                $n = min(count($active), mt_rand((int)($c['capacity'] * 0.4), (int)$c['capacity'] + 3));
                shuffle($active);
                foreach (array_slice($active, 0, $n) as $k => $mid) {
                    $full = $k >= $c['capacity'];
                    $status = $full ? 'waitlist' : ($day < $today ? (mt_rand(0, 8) ? 'attended' : 'no_show') : 'booked');
                    DB::run('INSERT IGNORE INTO class_bookings (gym_id, class_id, class_date, member_id, status, waitlist_pos) VALUES (?,?,?,?,?,?)',
                        [$g, $c['id'], $day, $mid, $status, $full ? $k - $c['capacity'] + 1 : null]);
                }
            }
        }

        // PT appointments today
        foreach ([['06:00', 'Suresh P', 'Vijay', 'done'], ['07:00', 'Karthik Subramaniam', 'Vijay', 'booked'], ['08:00', 'Divya K', 'Kavya', 'booked'], ['18:30', 'Lakshmi N', 'Kavya', 'booked'], ['20:00', 'Naveen R', 'Rahul', 'booked']] as [$t, $m, $tr, $st]) {
            $ms = DB::val("SELECT id FROM memberships WHERE member_id = ? AND kind = 'pt' ORDER BY id DESC LIMIT 1", [$memberIds[$m]]);
            DB::insert('pt_appointments', ['gym_id' => $g, 'member_id' => $memberIds[$m], 'trainer_id' => $staff[$tr], 'membership_id' => $ms, 'starts_at' => "$today $t:00", 'status' => $st]);
        }
        DB::insert('resources', ['gym_id' => $g, 'branch_id' => $an, 'name' => 'Recovery room (ice bath + sauna)', 'slot_minutes' => 30, 'price' => 300, 'open_time' => '06:00:00', 'close_time' => '21:00:00']);
        DB::insert('resources', ['gym_id' => $g, 'branch_id' => $vel, 'name' => 'Badminton court', 'slot_minutes' => 60, 'price' => 400, 'open_time' => '06:00:00', 'close_time' => '22:00:00']);

        // Leads in every stage
        foreach ([['Harish K', 'instagram', 'Weight loss · evening batch', 'new', 'Saravanan', 0], ['Sowmya R', 'whatsapp_bot', 'Ladies batch · price asked', 'new', null, 0],
                     ['Ajay P', 'walk_in', 'Strength · couple plan', 'new', 'Saravanan', -1], ['Ramesh G', 'google', 'Free trial', 'trial', 'Vijay', 1], ['Keerthi S', 'referral', 'Free trial', 'trial', 'Deepa', 2],
                     ['Manoj V', 'instagram', 'Trial done · wants quarterly', 'follow_up', 'Vijay', 0], ['Pooja L', 'website', 'Asked for EMI on yearly', 'follow_up', 'Deepa', -2],
                     ['Senthil A', 'walk_in', 'PT for knee rehab', 'follow_up', 'Vijay', 3], ['Gokul D', 'instagram', 'Joined · Quarterly', 'joined', 'Deepa', null],
                     ['Revathi M', 'referral', 'Joined · Yearly', 'joined', 'Vijay', null], ['Bharath K', 'facebook', 'Too far from home', 'lost', 'Saravanan', null]] as [$n, $src, $int, $stage, $who, $fu]) {
            $lid = DB::insert('leads', ['gym_id' => $g, 'branch_id' => $an, 'name' => $n, 'phone' => '97' . mt_rand(10000000, 99999999), 'source' => $src, 'interest' => $int, 'stage' => $stage,
                'assigned_to' => $who ? ($staff[$who] ?? null) : null, 'next_followup_at' => $fu !== null ? date('Y-m-d H:i:s', strtotime("$today 17:00 +$fu days")) : null,
                'trial_at' => $stage === 'trial' ? date('Y-m-d H:i:s', strtotime("$today 07:00 +$fu days")) : null, 'trial_pass_code' => $stage === 'trial' ? Util::randomCode(6) : null,
                'lost_reason' => $stage === 'lost' ? 'Too far' : null, 'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 20) . ' days'))]);
            DB::insert('lead_activities', ['lead_id' => $lid, 'kind' => 'created', 'note' => 'Lead captured from ' . $src]);
        }
        for ($i = 0; $i < 30; $i++) {
            $src = ['instagram', 'instagram', 'google', 'walk_in', 'referral', 'website', 'whatsapp_bot'][mt_rand(0, 6)];
            DB::insert('leads', ['gym_id' => $g, 'name' => self::FIRST[mt_rand(0, count(self::FIRST) - 1)] . ' ' . self::LAST[mt_rand(0, 13)], 'phone' => '96' . mt_rand(10000000, 99999999), 'source' => $src,
                'stage' => mt_rand(0, 2) === 0 ? 'joined' : 'lost', 'lost_reason' => 'Price', 'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(5, 80) . ' days'))]);
        }

        // Store
        foreach ([['Whey protein 1 kg (chocolate)', 'supplement', 2400, 1700, 18, 14, 5], ['Creatine 250 g', 'supplement', 850, 520, 18, 9, 4], ['BCAA 300 g', 'supplement', 1200, 780, 18, 2, 4],
                     ['Shaker bottle 700 ml', 'merchandise', 250, 90, 18, 40, 10], ['Gym T-shirt', 'merchandise', 499, 210, 5, 25, 8], ['Gym towel', 'merchandise', 199, 80, 5, 3, 5],
                     ['Locker · 1 month', 'locker', 300, 0, 18, 0, 0], ['Diet consultation', 'service', 800, 0, 18, 0, 0]] as [$n, $cat, $price, $cost, $gst, $stock, $re]) {
            DB::insert('products', ['gym_id' => $g, 'name' => $n, 'category' => $cat, 'price' => $price, 'cost' => $cost, 'gst_rate' => $gst, 'stock' => $stock, 'reorder_level' => $re,
                'track_stock' => in_array($cat, ['supplement', 'merchandise'], true) ? 1 : 0, 'hsn' => $cat === 'supplement' ? '21069099' : ($cat === 'merchandise' ? '61091000' : '999723')]);
        }
        $prod = DB::all('SELECT id FROM products WHERE gym_id = ? AND track_stock = 1', [$g]);
        $mids = array_values($memberIds);
        DB::run('UPDATE products SET stock = stock + 30 WHERE gym_id = ? AND track_stock = 1', [$g]);
        for ($i = 0; $i < 18; $i++) {
            $inv = \App\Controllers\StoreController::sell($g, [['product_id' => $prod[mt_rand(0, count($prod) - 1)]['id'], 'qty' => 1]], $mids[mt_rand(0, count($mids) - 1)], null);
            $date = Util::addDays($today, -mt_rand(0, 25));
            $total = (float)DB::val('SELECT total FROM invoices WHERE id = ?', [$inv]);
            Billing::recordPayment($g, $inv, $total, ['cash', 'upi', 'upi', 'card'][mt_rand(0, 3)]);
            DB::run('UPDATE invoices SET invoice_date = ?, created_at = ? WHERE id = ?', [$date, "$date 10:00:00", $inv]);
            DB::run('UPDATE payments SET paid_at = ? WHERE invoice_id = ?', ["$date 10:05:00", $inv]);
        }
        foreach (['Whey protein 1 kg (chocolate)' => 14, 'Creatine 250 g' => 9, 'BCAA 300 g' => 2, 'Shaker bottle 700 ml' => 40, 'Gym T-shirt' => 25, 'Gym towel' => 3] as $pn => $st) {
            DB::run('UPDATE products SET stock = ? WHERE gym_id = ? AND name = ?', [$st, $g, $pn]);
        }

        // Expenses
        foreach ([['Rent', 85000, 1], ['Electricity', 18400, 5], ['Equipment service', 6500, 9], ['Housekeeping', 4200, 3], ['Marketing · Instagram ads', 12000, 2], ['Water & supplies', 2800, 12]] as [$cat, $amt, $day]) {
            for ($m = 0; $m < 3; $m++) {
                $d = date('Y-m-', strtotime("-$m months")) . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                if ($d <= $today) DB::insert('expenses', ['gym_id' => $g, 'branch_id' => $an, 'category' => $cat, 'amount' => $amt, 'expense_date' => $d, 'created_by' => $owner]);
            }
        }

        // Workout + diet for Karthik
        $ex = [];
        foreach (DB::all('SELECT id, name FROM exercises WHERE gym_id IS NULL') as $e) $ex[$e['name']] = $e['id'];
        $wp = DB::insert('workout_plans', ['gym_id' => $g, 'member_id' => $memberIds['Karthik Subramaniam'], 'name' => 'Fat loss · push/pull/legs', 'created_by' => $staff['Vijay']]);
        $days = [1 => ['Push', [['Bench press', '4', '8', '50 kg', 90], ['Incline dumbbell press', '3', '10', '16 kg', 75], ['Overhead press', '3', '8', '30 kg', 90], ['Lateral raise', '3', '12', '6 kg', 60], ['Triceps pushdown', '3', '12', '25 kg', 60], ['Treadmill incline walk', '1', '15 min', null, null]]],
            3 => ['Pull', [['Deadlift', '4', '5', '100 kg', 120], ['Lat pulldown', '3', '10', '50 kg', 75], ['Seated row', '3', '10', '45 kg', 75], ['Barbell curl', '3', '12', '20 kg', 60], ['Cycling', '1', '15 min', null, null]]],
            4 => ['Cardio', [['Rowing machine', '1', '20 min', null, null], ['Skipping', '5', '1 min', null, 60], ['Plank', '3', '45 s', null, 45]]],
            5 => ['Legs', [['Squat', '4', '6', '80 kg', 120], ['Leg press', '3', '10', '120 kg', 90], ['Romanian deadlift', '3', '10', '60 kg', 90], ['Walking lunge', '3', '12', '10 kg', 60], ['Calf raise', '3', '15', '40 kg', 45]]],
            6 => ['Full body', [['Deadlift', '3', '5', '90 kg', 120], ['Bench press', '3', '8', '47.5 kg', 90], ['Pull-up', '3', '8', null, 90], ['Hanging leg raise', '3', '12', null, 45]]]];
        $dow = (int)date('w');
        if (!isset($days[$dow])) $days[$dow] = $days[1];
        foreach ($days as $d => [$title, $items]) foreach ($items as $i => [$e, $sets, $reps, $w, $rest]) {
            DB::insert('workout_items', ['plan_id' => $wp, 'day_of_week' => $d, 'day_title' => $title, 'exercise_id' => $ex[$e], 'sets' => $sets, 'reps' => $reps, 'weight' => $w, 'rest_sec' => $rest, 'sort' => $i]);
        }
        DB::insert('workout_plans', ['gym_id' => $g, 'name' => 'Beginner full body (template)', 'is_template' => 1, 'created_by' => $staff['Vijay']]);
        $dp = DB::insert('diet_plans', ['gym_id' => $g, 'member_id' => $memberIds['Karthik Subramaniam'], 'name' => 'Weight-loss chart', 'kcal_target' => 1900, 'protein_target' => 130, 'created_by' => $staff['Vijay']]);
        foreach ([['6:00 AM', 'Black coffee, 1 banana', 110, 1], ['8:30 AM', '3 idli, sambar, 3 egg whites', 420, 24], ['1:00 PM', 'Rice 1 cup, dal, chicken 150 g, poriyal', 620, 45],
                     ['5:00 PM', 'Sundal, buttermilk', 220, 12], ['8:30 PM', '2 chapati, paneer 100 g, salad', 480, 30]] as $i => [$t, $f, $k, $p]) {
            $meal = DB::insert('diet_meals', ['diet_plan_id' => $dp, 'meal_time' => $t, 'food' => $f, 'kcal' => $k, 'protein' => $p, 'sort' => $i]);
            if ($i < 2) DB::run('INSERT IGNORE INTO diet_logs VALUES (?,?,?)', [$memberIds['Karthik Subramaniam'], $meal, $today]);
        }
        foreach ([[-150, 84, 25, 36], [-120, 83, 24.5, 35.5], [-90, 81.5, 23.5, 35], [-60, 80, 22.5, 34.5], [-30, 79, 21.5, 34.2], [-9, 78, 21, 34]] as [$d, $w, $bf, $waist]) {
            DB::insert('assessments', ['gym_id' => $g, 'member_id' => $memberIds['Karthik Subramaniam'], 'assessed_on' => Util::addDays($today, $d), 'weight_kg' => $w, 'height_cm' => 175,
                'bmi' => round($w / 1.75 ** 2, 1), 'body_fat_pct' => $bf, 'waist_in' => $waist, 'created_by' => $staff['Vijay']]);
        }
        foreach ([['Bench press', '50 kg × 8', -4], ['Deadlift', '100 kg × 5', -11], ['Squat', '80 kg × 6', -18]] as [$e, $v, $d]) {
            DB::insert('personal_records', ['gym_id' => $g, 'member_id' => $memberIds['Karthik Subramaniam'], 'exercise_id' => $ex[$e], 'value' => $v, 'achieved_on' => Util::addDays($today, $d)]);
        }
        foreach ([['Bench press', 3, 8, 47.5, -4], ['Overhead press', 3, 8, 27.5, -4], ['Deadlift', 4, 5, 100, -2]] as [$e, $s2, $r2, $w2, $d]) {
            DB::insert('workout_logs', ['gym_id' => $g, 'member_id' => $memberIds['Karthik Subramaniam'], 'exercise_id' => $ex[$e], 'log_date' => Util::addDays($today, $d), 'sets' => $s2, 'reps' => $r2, 'weight' => $w2]);
        }

        // Community
        DB::insert('challenges', ['gym_id' => $g, 'name' => date('F') . ' challenge: 20 visits', 'metric' => 'visits', 'target' => 20, 'start_date' => date('Y-m-01'), 'end_date' => date('Y-m-t'), 'prize' => '1 free PT session']);
        DB::insert('challenges', ['gym_id' => $g, 'name' => 'Most hours in the gym', 'metric' => 'hours', 'target' => 30, 'start_date' => date('Y-m-01'), 'end_date' => date('Y-m-t'), 'prize' => 'FitZone T-shirt']);
        DB::insert('announcements', ['gym_id' => $g, 'title' => 'Sunday: open 6 AM – 12 PM only', 'body' => 'Equipment service in the afternoon. Zumba at 7 AM runs as usual.', 'visible_until' => Util::addDays($today, 6), 'created_by' => $owner]);

        // Devices (registered; real devices connect to /iclock/cdata)
        DB::insert('devices', ['gym_id' => $g, 'branch_id' => $an, 'serial_no' => 'DEMO-MB160-AN01', 'name' => 'Main door', 'model' => 'eSSL MB160', 'kind' => 'fingerprint', 'last_seen_at' => date('Y-m-d H:i:s')]);
        DB::insert('devices', ['gym_id' => $g, 'branch_id' => $vel, 'serial_no' => 'DEMO-MB160-VL01', 'name' => 'Velachery entrance', 'model' => 'eSSL MB160', 'kind' => 'fingerprint', 'last_seen_at' => date('Y-m-d H:i:s')]);

        // Message history and a few denied punches
        foreach (array_slice($mids, 0, 25) as $mid) {
            $m = DB::one('SELECT * FROM members WHERE id = ?', [$mid]);
            DB::insert('messages', ['gym_id' => $g, 'member_id' => $mid, 'channel' => 'whatsapp', 'to_addr' => $m['phone'], 'tkey' => 'renewal_reminder', 'status' => ['sent', 'delivered', 'read'][mt_rand(0, 2)],
                'body' => "Hi {$m['name']}, your plan ends soon. Renew now.", 'provider_id' => 'simulated', 'sent_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 9) . ' days')), 'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 9) . ' days'))]);
        }
        $gokul = DB::one('SELECT * FROM members WHERE id = ?', [$memberIds['Gokul V']]);
        DB::run('INSERT IGNORE INTO punch_log (gym_id, branch_id, device_id, device_user_id, member_id, punched_at, method, result, reason) VALUES (?,?,?,?,?,?,?,?,?)',
            [$g, $an, DB::val('SELECT id FROM devices WHERE serial_no = ?', ['DEMO-MB160-AN01']), $gokul['device_user_id'], $gokul['id'], "$today 06:12:00", 'fingerprint', 'denied', 'Plan expired']);
        DB::run('INSERT IGNORE INTO punch_log (gym_id, branch_id, device_id, device_user_id, punched_at, method, result, reason) VALUES (?,?,?,?,?,?,?,?)',
            [$g, $an, DB::val('SELECT id FROM devices WHERE serial_no = ?', ['DEMO-MB160-AN01']), '9999', "$today 07:31:00", 'fingerprint', 'denied', 'Not enrolled on this device']);

        Memberships::maintain($g);
        DB::run('DELETE FROM device_commands');
        Churn::run($g);
        DB::run('REPLACE INTO cron_runs (job, last_run_at) VALUES (?, NOW())', ['daily_night']);
        Auth::reset();
        return ['gym_id' => $g, 'members' => count($memberIds), 'login' => 'owner@demo.gym / ' . self::PASSWORD];
    }

    private static function purge(int $g): void
    {
        foreach (['memberships', 'invoices', 'payments', 'credit_notes', 'payment_links', 'expenses', 'devices', 'attendance_sessions', 'punch_log', 'classes', 'class_bookings', 'pt_appointments',
                     'resources', 'resource_bookings', 'leads', 'products', 'stock_moves', 'payroll_runs', 'message_templates', 'campaigns', 'messages', 'notifications', 'workout_plans',
                     'workout_logs', 'diet_plans', 'assessments', 'personal_records', 'challenges', 'announcements', 'webhooks', 'api_keys', 'audit_log', 'coupons', 'gift_cards', 'member_credits'] as $t) {
            DB::run("DELETE FROM `$t` WHERE gym_id = ?", [$g]);
        }
    }

    private static function sell(int $g, int $mid, int $planId, string $start, string $pay, bool $autopay = false): int
    {
        $r = Memberships::sell($g, $mid, $planId, ['start_date' => $start, 'invoice_date' => min($start, Util::today()), 'autopay' => $autopay]);
        $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$r['invoice_id']]);
        $paidAt = min($start, Util::today()) . ' ' . sprintf('%02d:%02d:00', mt_rand(6, 20), mt_rand(0, 59));
        $mode = $autopay ? 'autopay' : ['upi', 'upi', 'upi', 'cash', 'card'][mt_rand(0, 4)];
        if ($pay === 'paid') Billing::recordPayment($g, (int)$inv['id'], (float)$inv['total'], $mode);
        if ($pay === 'partial') Billing::recordPayment($g, (int)$inv['id'], round((float)$inv['total'] - 2500, 2), 'upi');
        DB::run('UPDATE payments SET paid_at = ? WHERE invoice_id = ?', [$paidAt, $inv['id']]);
        DB::run('UPDATE invoices SET created_at = ?, due_date = ? WHERE id = ?', [$paidAt, $inv['invoice_date'], $inv['id']]);
        DB::run("DELETE FROM messages WHERE tkey = 'payment_receipt' AND gym_id = ?", [$g]);
        return $r['membership_id'];
    }

    /** Visits shaped by a behaviour pattern so churn scores and reports look realistic. */
    private static function attendance(int $g, int $mid, int $branch, string $joined, string $pattern, string $name): void
    {
        $today = Util::today();
        $prefMorning = mt_rand(0, 9) < 6;
        $baseHour = $prefMorning ? mt_rand(5, 7) : mt_rand(17, 20);
        $stay = mt_rand(45, 95);
        for ($d = 60; $d >= 0; $d--) {
            $day = Util::addDays($today, -$d);
            if ($day < $joined) continue;
            $p = match ($pattern) {
                'regular', 'dues' => 0.68, 'casual' => 0.35, 'fading' => $d > 14 ? 0.62 : 0.12, 'gone' => $d > 16 ? 0.6 : 0.0,
                'expired' => $d > 40 ? 0.5 : 0.0, 'frozen' => $d > 4 ? 0.6 : 0.0, default => 0.4,
            };
            if ($name === 'Karthik Subramaniam' && $d === 0) $p = 1;
            if (mt_rand(0, 99) >= $p * 100) continue;
            $in = strtotime($day . ' ' . $baseHour . ':' . sprintf('%02d', mt_rand(0, 59)) . ':00') + mt_rand(-20, 20) * 60;
            if ($name === 'Karthik Subramaniam' && $d === 0) $in = time() - 78 * 60;
            if ($in > time()) continue;
            $len = ($pattern === 'fading' && $d <= 14) ? mt_rand(18, 32) : $stay + mt_rand(-15, 15);
            $out = $in + $len * 60;
            $inside = $d === 0 && $out > time();
            $forgot = !$inside && mt_rand(0, 40) === 0;
            DB::insert('attendance_sessions', ['gym_id' => $g, 'branch_id' => $branch, 'member_id' => $mid, 'in_at' => date('Y-m-d H:i:s', $in),
                'out_at' => $inside ? null : ($forgot ? "$day 22:00:00" : date('Y-m-d H:i:s', $out)), 'duration_min' => $inside || $forgot ? null : $len,
                'auto_closed' => $forgot ? 1 : 0, 'note' => $forgot ? 'No OUT punch' : null, 'method' => ['fingerprint', 'fingerprint', 'fingerprint', 'fingerprint', 'face', 'qr'][mt_rand(0, 5)]]);
        }
    }
}
