<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Response, Upload, Util, V};
use App\Services\{Access, Adms, Memberships, Messenger, Webhooks};

final class MembersController extends Base
{
    private const FIELDS = [
        'name' => 'required|string|max:120', 'phone' => 'required|phone', 'email' => 'nullable|email|max:150', 'dob' => 'nullable|date',
        'gender' => 'nullable|in:male,female,other', 'address' => 'nullable|string|max:400', 'state_code' => 'nullable|string|max:2',
        'emergency_contact' => 'nullable|string|max:150', 'health_notes' => 'nullable|string|max:2000', 'goal' => 'nullable|string|max:80',
        'source' => 'nullable|string|max:60', 'language' => 'nullable|in:en,ta', 'trainer_id' => 'nullable|int', 'referred_by' => 'nullable|int',
        'branch_id' => 'nullable|int', 'device_user_id' => 'nullable|string|max:20', 'leaderboard_opt_in' => 'nullable|bool',
    ];

    /** Base list query with current plan, last visit, dues and live status. */
    private function listSql(Request $req, array &$params): string
    {
        $g = $this->gym();
        $grace = $this->graceDays();
        $params = [$g];
        $where = 'm.gym_id = ? AND m.deleted_at IS NULL';
        [$bf] = $this->branchFilter($req, 'm.branch_id');
        $where .= $bf;
        if (Auth::role() === 'trainer') $where .= ' AND m.trainer_id = ' . (int)Auth::staffId();
        if ($q = trim((string)$req->q('q', ''))) {
            $where .= ' AND (m.name LIKE ? OR m.phone LIKE ? OR m.member_code LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr($q, 0, 60)) . '%';
            array_push($params, $like, $like, $like);
        }
        if ($pid = (int)$req->q('plan_id', 0)) { $where .= ' AND ms.plan_id = ?'; $params[] = $pid; }
        if ($tid = (int)$req->q('trainer_id', 0)) { $where .= ' AND m.trainer_id = ?'; $params[] = $tid; }

        $sql = "SELECT * FROM (SELECT m.id, m.member_code, m.name, m.phone, m.email, m.photo_path, m.branch_id, m.trainer_id, m.churn_score, m.churn_reason, m.is_blocked, m.created_at,
                ms.id AS membership_id, ms.end_date, ms.status AS ms_status, ms.autopay, p.name AS plan_name, p.kind,
                CASE WHEN ms.id IS NULL THEN 'no_plan' WHEN ms.status = 'frozen' THEN 'frozen' WHEN ms.status = 'upcoming' THEN 'upcoming'
                     WHEN ms.end_date >= CURDATE() THEN 'active' WHEN ms.end_date >= CURDATE() - INTERVAL $grace DAY THEN 'grace' ELSE 'expired' END AS plan_status,
                (SELECT MAX(s.in_at) FROM attendance_sessions s WHERE s.member_id = m.id) AS last_visit,
                (SELECT COUNT(*) FROM attendance_sessions s WHERE s.member_id = m.id AND s.out_at IS NULL AND DATE(s.in_at) = CURDATE()) AS inside,
                (SELECT ROUND(AVG(s.duration_min)) FROM attendance_sessions s WHERE s.member_id = m.id AND s.duration_min IS NOT NULL AND s.in_at > NOW() - INTERVAL 60 DAY) AS avg_stay,
                (SELECT COALESCE(SUM(i.balance),0) FROM invoices i WHERE i.member_id = m.id AND i.status IN ('unpaid','partial')) AS dues,
                (SELECT ms2.sessions_total - ms2.sessions_used FROM memberships ms2 WHERE ms2.member_id = m.id AND ms2.kind = 'pt' AND ms2.status = 'active' ORDER BY ms2.end_date DESC LIMIT 1) AS pt_left
            FROM members m
            LEFT JOIN memberships ms ON ms.id = (SELECT x.id FROM memberships x WHERE x.member_id = m.id AND x.kind = 'membership' AND x.status NOT IN ('cancelled','transferred') ORDER BY (x.status = 'upcoming'), x.end_date DESC LIMIT 1)
            LEFT JOIN plans p ON p.id = ms.plan_id
            WHERE $where) t WHERE 1=1";
        switch ($req->q('status')) {
            case 'active': $sql .= " AND plan_status IN ('active','grace')"; break;
            case 'expiring': $sql .= " AND plan_status = 'active' AND end_date <= CURDATE() + INTERVAL 7 DAY"; break;
            case 'dues': $sql .= ' AND dues > 0'; break;
            case 'frozen': $sql .= " AND plan_status = 'frozen'"; break;
            case 'expired': $sql .= " AND plan_status IN ('expired','no_plan')"; break;
            case 'inside': $sql .= ' AND inside > 0'; break;
            case 'churn': $sql .= ' AND churn_score >= 60'; break;
        }
        $order = match ($req->q('sort')) { 'name' => 'name', 'end_date' => 'end_date', 'churn' => 'churn_score DESC', 'last_visit' => 'last_visit DESC', default => 'id DESC' };
        return "$sql ORDER BY $order";
    }

    public function index(Request $req): array
    {
        $params = [];
        $sql = $this->listSql($req, $params);
        $res = $this->paged($sql, $params, $req);
        // Counts per status chip.
        $g = $this->gym();
        $base = new Request('GET', '/', array_diff_key($req->query, ['status' => 1, 'page' => 1]), '', []);
        $p2 = [];
        $all = $this->listSql($base, $p2);
        $res['counts'] = DB::one("SELECT COUNT(*) AS all_count,
            SUM(plan_status IN ('active','grace')) AS active, SUM(plan_status = 'active' AND end_date <= CURDATE() + INTERVAL 7 DAY) AS expiring,
            SUM(dues > 0) AS dues, SUM(plan_status = 'frozen') AS frozen, SUM(plan_status IN ('expired','no_plan')) AS expired, SUM(inside > 0) AS inside,
            SUM(churn_score >= 60) AS churn FROM ($all) c", $p2);
        return $res;
    }

    public function export(Request $req): Response
    {
        Auth::require('reports.view');
        $params = [];
        $rows = DB::all($this->listSql($req, $params), $params);
        $out = array_map(fn($r) => [$r['member_code'], $r['name'], $r['phone'], $r['email'], $r['plan_name'], $r['end_date'], $r['plan_status'], $r['last_visit'], $r['avg_stay'], $r['dues']], $rows);
        Util::audit('members.export', null, null, ['count' => count($rows)]);
        return Response::file(Util::csv($out, ['Member ID', 'Name', 'Phone', 'Email', 'Plan', 'Valid till', 'Status', 'Last visit', 'Avg stay (min)', 'Dues']), 'text/csv', 'members-' . date('Y-m-d') . '.csv');
    }

    private function nextCode(): string
    {
        DB::one('SELECT id FROM gyms WHERE id = ? FOR UPDATE', [$this->gym()]);
        DB::run('UPDATE gyms SET member_seq = GREATEST(member_seq, (SELECT COUNT(*) FROM members WHERE gym_id = ?)) + 1 WHERE id = ?', [$this->gym(), $this->gym()]);
        $n = (int)DB::val('SELECT member_seq FROM gyms WHERE id = ?', [$this->gym()]);
        do { $code = 'M-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT); $n++; }
        while (DB::val('SELECT id FROM members WHERE gym_id = ? AND member_code = ?', [$this->gym(), $code]));
        DB::run('UPDATE gyms SET member_seq = ? WHERE id = ?', [$n - 1, $this->gym()]);
        return $code;
    }

    private function nextDevicePin(): string
    {
        $max = (int)DB::val('SELECT GREATEST(COALESCE((SELECT MAX(CAST(device_user_id AS UNSIGNED)) FROM members WHERE gym_id = ?),0), COALESCE((SELECT MAX(CAST(device_user_id AS UNSIGNED)) FROM users WHERE gym_id = ?),0))', [$this->gym(), $this->gym()]);
        return (string)max(1001, $max + 1);
    }

    private function validate(array $in, bool $create): array
    {
        $rules = self::FIELDS;
        if (!$create) $rules = array_map(fn($r) => str_replace('required|', '', $r), $rules);
        $d = V::check($in, $rules);
        if (array_key_exists('trainer_id', $d)) $this->assertStaff($d['trainer_id']);
        if (array_key_exists('branch_id', $d)) $this->assertBranch($d['branch_id']);
        if (!empty($d['referred_by']) && !DB::val('SELECT id FROM members WHERE id = ? AND gym_id = ?', [$d['referred_by'], $this->gym()])) throw HttpException::bad('Referrer not found', ['referred_by' => 'Not found']);
        if (!empty($d['device_user_id'])) {
            if (!preg_match('/^\d{1,9}$/', $d['device_user_id'])) throw HttpException::bad('Device ID must be digits', ['device_user_id' => 'Digits only']);
        }
        return $d;
    }

    public function store(Request $req): array
    {
        $d = $this->validate($req->json(), true);
        $g = $this->gym();
        if (DB::val('SELECT id FROM members WHERE gym_id = ? AND phone = ? AND deleted_at IS NULL', [$g, $d['phone']]) && !$req->input('allow_duplicate_phone')) {
            throw HttpException::bad('A member with this phone already exists', ['phone' => 'Already registered']);
        }
        $id = DB::tx(function () use ($d, $g, $req) {
            $d['member_code'] = $this->nextCode();
            $d['device_user_id'] ??= $this->nextDevicePin();
            if (DB::val('SELECT id FROM members WHERE gym_id = ? AND device_user_id = ? AND deleted_at IS NULL', [$g, $d['device_user_id']])) throw HttpException::bad('Device ID already used', ['device_user_id' => 'Already used']);
            $d['branch_id'] ??= $this->defaultBranch();
            $d['language'] ??= Util::gym()['default_language'];
            $d['gym_id'] = $g;
            $d['qr_secret'] = bin2hex(random_bytes(16));
            $id = DB::insert('members', $d);
            if ($photo = $req->input('photo')) DB::update('members', ['photo_path' => Upload::store($g, $photo)], ['id' => $id]);
            if ($leadId = (int)$req->input('lead_id', 0)) {
                DB::run("UPDATE leads SET stage = 'joined', member_id = ? WHERE id = ? AND gym_id = ?", [$id, $leadId, $g]);
            }
            return $id;
        });
        $m = DB::one('SELECT * FROM members WHERE id = ?', [$id]);
        // Register the member on every door device so they can enrol a fingerprint.
        Access::syncUser($g, $m, true);
        Messenger::template($g, 'welcome', $m);
        Webhooks::emit($g, 'member.created', ['member_id' => $id, 'member_code' => $m['member_code'], 'name' => $m['name']]);
        Util::audit('member.create', 'member', $id);

        $result = ['id' => $id, 'member_code' => $m['member_code']];
        if ($planId = (int)$req->input('plan_id', 0)) {
            Auth::require('billing.collect');
            $p = V::check($req->json(), ['start_date' => 'nullable|date', 'discount' => 'nullable|num|min:0', 'coupon' => 'nullable|string|max:30',
                'pay_amount' => 'nullable|num|min:0', 'pay_mode' => 'nullable|in:cash,upi,card,netbanking,credit,giftcard', 'pay_reference' => 'nullable|string|max:100', 'autopay' => 'nullable|bool']);
            $result += Memberships::sell($g, $id, $planId, $p);
        }
        return $result;
    }

    public function show(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $g = $this->gym();
        $id = (int)$m['id'];
        unset($m['qr_secret'], $m['push_subscription'], $m['strava_tokens']);
        $m['memberships'] = DB::all('SELECT ms.*, p.name AS plan_name, p.freeze_days_allowed, i.invoice_no, i.balance AS invoice_balance FROM memberships ms JOIN plans p ON p.id = ms.plan_id LEFT JOIN invoices i ON i.id = ms.invoice_id WHERE ms.member_id = ? AND ms.gym_id = ? ORDER BY ms.end_date DESC', [$id, $g]);
        $current = null;
        foreach ($m['memberships'] as $ms) if ($ms['kind'] === 'membership' && !in_array($ms['status'], ['cancelled', 'transferred'], true)) { $current = $ms; break; }
        $m['plan_status'] = Memberships::statusFor($current, $this->graceDays());
        $m['current'] = $current;
        $m['pt'] = DB::one("SELECT ms.*, p.name AS plan_name FROM memberships ms JOIN plans p ON p.id = ms.plan_id WHERE ms.member_id = ? AND ms.kind = 'pt' AND ms.status = 'active' ORDER BY ms.end_date DESC LIMIT 1", [$id]);
        $m['trainer'] = $m['trainer_id'] ? DB::one('SELECT id, name FROM users WHERE id = ?', [$m['trainer_id']]) : null;
        $m['referrer'] = $m['referred_by'] ? DB::one('SELECT id, name, member_code FROM members WHERE id = ?', [$m['referred_by']]) : null;
        $m['open_session'] = DB::one('SELECT * FROM attendance_sessions WHERE member_id = ? AND out_at IS NULL AND DATE(in_at) = CURDATE()', [$id]);
        $monthStart = date('Y-m-01');
        $m['stats'] = DB::one('SELECT COUNT(*) visits, ROUND(AVG(duration_min)) avg_min, COALESCE(SUM(duration_min),0) total_min FROM attendance_sessions WHERE member_id = ? AND in_at >= ?', [$id, $monthStart]);
        $m['visits'] = DB::all('SELECT id, in_at, out_at, duration_min, method, auto_closed FROM attendance_sessions WHERE member_id = ? ORDER BY in_at DESC LIMIT 30', [$id]);
        $m['invoices'] = Auth::can('billing.view') ? DB::all('SELECT id, invoice_no, invoice_date, total, paid, balance, status, kind FROM invoices WHERE member_id = ? AND gym_id = ? ORDER BY id DESC LIMIT 20', [$id, $g]) : [];
        $m['dues'] = (float)DB::val("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE member_id = ? AND status IN ('unpaid','partial')", [$id]);
        $m['assessments'] = DB::all('SELECT * FROM assessments WHERE member_id = ? AND gym_id = ? ORDER BY assessed_on DESC LIMIT 10', [$id, $g]);
        $m['credits'] = DB::all('SELECT amount, reason, created_at FROM member_credits WHERE member_id = ? ORDER BY id DESC LIMIT 10', [$id]);
        $m['messages'] = DB::all('SELECT id, direction, channel, body, status, created_at FROM messages WHERE member_id = ? ORDER BY id DESC LIMIT 10', [$id]);
        $m['access'] = Access::check($g, DB::one('SELECT * FROM members WHERE id = ?', [$id]));
        unset($m['access']['membership']);
        $m['has_photo'] = (bool)$m['photo_path'];
        $m['has_id_proof'] = (bool)$m['id_proof_path'];
        return $m;
    }

    public function update(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $d = $this->validate($req->json(), false);
        if (isset($d['phone']) && $d['phone'] !== $m['phone'] && DB::val('SELECT id FROM members WHERE gym_id = ? AND phone = ? AND id <> ? AND deleted_at IS NULL', [$this->gym(), $d['phone'], $m['id']])) {
            throw HttpException::bad('Another member has this phone', ['phone' => 'Already registered']);
        }
        if (isset($d['device_user_id']) && $d['device_user_id'] !== $m['device_user_id'] && DB::val('SELECT id FROM members WHERE gym_id = ? AND device_user_id = ? AND id <> ?', [$this->gym(), $d['device_user_id'], $m['id']])) {
            throw HttpException::bad('Device ID already used', ['device_user_id' => 'Already used']);
        }
        DB::update('members', $d, ['id' => $m['id'], 'gym_id' => $this->gym()]);
        Webhooks::emit($this->gym(), 'member.updated', ['member_id' => (int)$m['id']]);
        Util::audit('member.update', 'member', (int)$m['id'], array_keys($d));
        return ['ok' => true];
    }

    public function destroy(Request $req): array
    {
        $m = $this->memberRow($req->id());
        DB::update('members', ['deleted_at' => date('Y-m-d H:i:s'), 'phone' => $m['phone']], ['id' => $m['id']]);
        if ($m['device_user_id']) {
            foreach (DB::all('SELECT id FROM devices WHERE gym_id = ?', [$this->gym()]) as $d) DB::insert('device_commands', ['device_id' => $d['id'], 'command' => "DATA DELETE USERINFO PIN={$m['device_user_id']}"]);
        }
        Auth::endAllSessions('member', (int)$m['id']);
        Util::audit('member.delete', 'member', (int)$m['id']);
        return ['ok' => true];
    }

    public function upload(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $kind = $req->params['kind'] ?? '';
        $col = ['photo' => 'photo_path', 'id-proof' => 'id_proof_path'][$kind] ?? null;
        if (!$col) throw HttpException::notFound();
        $file = $req->files['file'] ?? $req->input('file');
        $path = Upload::store($this->gym(), $file);
        DB::update('members', [$col => $path], ['id' => $m['id']]);
        Util::audit('member.upload', 'member', (int)$m['id'], $kind);
        return ['ok' => true];
    }

    public function file(Request $req): Response
    {
        $m = $this->memberRow($req->id());
        $col = ['photo' => 'photo_path', 'id-proof' => 'id_proof_path', 'waiver' => 'waiver_signature_path'][$req->params['kind'] ?? ''] ?? null;
        if (!$col) throw HttpException::notFound();
        return Upload::stream($this->gym(), $m[$col]);
    }

    public function waiver(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $sig = (string)$req->input('signature');
        $path = Upload::store($this->gym(), $sig, 500_000, ['image/png']);
        DB::update('members', ['waiver_signed_at' => date('Y-m-d H:i:s'), 'waiver_signature_path' => $path], ['id' => $m['id']]);
        Util::audit('member.waiver', 'member', (int)$m['id']);
        return ['ok' => true];
    }

    public function block(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $block = (bool)$req->input('blocked', true);
        $reason = V::check($req->json(), ['reason' => 'nullable|string|max:120'])['reason'] ?? null;
        DB::update('members', ['is_blocked' => $block ? 1 : 0, 'blocked_reason' => $block ? ($reason ?: 'Blocked by staff') : null], ['id' => $m['id']]);
        Access::reevaluate($this->gym(), (int)$m['id']);
        Util::audit($block ? 'member.block' : 'member.unblock', 'member', (int)$m['id'], $reason);
        return ['ok' => true];
    }

    /** Ask every door device to start fingerprint/face enrolment for this member. */
    public function enroll(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $d = V::check($req->json(), ['device_id' => 'required|int', 'kind' => 'nullable|in:fingerprint,face', 'finger' => 'nullable|int|min:0|max:9']);
        $dev = $this->find('devices', $d['device_id'], 'Device');
        Access::syncUser($this->gym(), $m, true);
        $cmd = ($d['kind'] ?? 'fingerprint') === 'face' ? "ENROLL_BIO TYPE=9\tPIN={$m['device_user_id']}\tRETRY=3\tOVERWRITE=1" : "ENROLL_FP PIN={$m['device_user_id']}\tFID=" . ($d['finger'] ?? 6) . "\tRETRY=3\tOVERWRITE=1";
        DB::insert('device_commands', ['device_id' => $dev['id'], 'command' => $cmd]);
        return ['ok' => true, 'message' => 'Ask the member to place their ' . (($d['kind'] ?? 'fingerprint') === 'face' ? 'face in front of' : 'finger on') . ' ' . $dev['name'] . ' within 60 seconds.'];
    }

    public function message(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $d = V::check($req->json(), ['body' => 'required|string|max:1000', 'channel' => 'nullable|in:whatsapp,sms,email']);
        $ch = $d['channel'] ?? 'whatsapp';
        $to = $ch === 'email' ? ($m['email'] ?? '') : $m['phone'];
        if (!$to) throw HttpException::bad('Member has no ' . $ch . ' contact');
        $id = Messenger::queue($this->gym(), $ch, $to, $d['body'], ['member_id' => (int)$m['id'], 'tkey' => 'manual']);
        Messenger::dispatch(1, $id);
        return DB::one('SELECT id, status, error FROM messages WHERE id = ?', [$id]);
    }

    public function visits(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $month = preg_match('/^\d{4}-\d{2}$/', (string)$req->q('month')) ? $req->q('month') : date('Y-m');
        return DB::all("SELECT id, in_at, out_at, duration_min, method, auto_closed, note FROM attendance_sessions WHERE member_id = ? AND DATE_FORMAT(in_at, '%Y-%m') = ? ORDER BY in_at DESC", [$m['id'], $month]);
    }

    /** Bulk import from Excel/CSV rows parsed in the browser. */
    public function import(Request $req): array
    {
        $rows = $req->input('rows');
        if (!is_array($rows) || count($rows) === 0) throw HttpException::bad('No rows to import');
        if (count($rows) > 5000) throw HttpException::bad('Import at most 5,000 rows at a time');
        $g = $this->gym();
        $plans = [];
        foreach (DB::all('SELECT id, name FROM plans WHERE gym_id = ?', [$g]) as $p) $plans[mb_strtolower(trim($p['name']))] = (int)$p['id'];
        $created = 0; $skipped = []; $line = 1;
        foreach ($rows as $r) {
            $line++;
            if (!is_array($r)) { $skipped[] = ['row' => $line, 'error' => 'Bad row']; continue; }
            try {
                $d = V::check($r, ['name' => 'required|string|max:120', 'phone' => 'required|phone', 'email' => 'nullable|email', 'dob' => 'nullable|date',
                    'gender' => 'nullable|in:male,female,other', 'address' => 'nullable|string|max:400', 'plan' => 'nullable|string|max:120',
                    'start_date' => 'nullable|date', 'end_date' => 'nullable|date', 'paid' => 'nullable|num', 'device_user_id' => 'nullable|string|max:20']);
                if (DB::val('SELECT id FROM members WHERE gym_id = ? AND phone = ? AND deleted_at IS NULL', [$g, $d['phone']])) { $skipped[] = ['row' => $line, 'error' => 'Phone already exists']; continue; }
                DB::tx(function () use ($d, $g, $plans) {
                    $id = DB::insert('members', ['gym_id' => $g, 'branch_id' => $this->defaultBranch(), 'member_code' => $this->nextCode(), 'name' => $d['name'], 'phone' => $d['phone'],
                        'email' => $d['email'] ?? null, 'dob' => $d['dob'] ?? null, 'gender' => $d['gender'] ?? null, 'address' => $d['address'] ?? null,
                        'device_user_id' => $d['device_user_id'] ?? $this->nextDevicePin(), 'qr_secret' => bin2hex(random_bytes(16)), 'source' => 'import', 'language' => Util::gym()['default_language']]);
                    $pid = isset($d['plan']) ? ($plans[mb_strtolower(trim($d['plan']))] ?? null) : null;
                    if ($pid && !empty($d['end_date'])) {
                        $p = DB::one('SELECT * FROM plans WHERE id = ?', [$pid]);
                        $start = $d['start_date'] ?? Util::addDays($d['end_date'], -(int)$p['duration_days'] + 1);
                        // Imported history: record the plan without a new invoice (it was billed in the old system).
                        DB::insert('memberships', ['gym_id' => $g, 'member_id' => $id, 'plan_id' => $pid, 'kind' => $p['kind'], 'start_date' => $start, 'end_date' => $d['end_date'],
                            'price' => $d['paid'] ?? $p['price'], 'status' => $d['end_date'] >= Util::today() ? ($start > Util::today() ? 'upcoming' : 'active') : 'expired',
                            'sessions_total' => (int)$p['sessions_count'], 'notes' => 'Imported', 'created_by' => Auth::staffId()]);
                    }
                });
                $created++;
            } catch (HttpException $e) {
                $skipped[] = ['row' => $line, 'error' => $e->fields ? implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($e->fields), $e->fields)) : $e->getMessage()];
            }
        }
        Util::audit('members.import', null, null, ['created' => $created, 'skipped' => count($skipped)]);
        return ['created' => $created, 'skipped' => $skipped];
    }

    // ---- Memberships ----

    public function sell(Request $req): array
    {
        $m = $this->memberRow($req->id());
        $d = V::check($req->json(), ['plan_id' => 'required|int', 'start_date' => 'nullable|date', 'discount' => 'nullable|num|min:0', 'coupon' => 'nullable|string|max:30',
            'pay_amount' => 'nullable|num|min:0', 'pay_mode' => 'nullable|in:cash,upi,card,netbanking,credit,giftcard', 'pay_reference' => 'nullable|string|max:100', 'autopay' => 'nullable|bool']);
        return Memberships::sell($this->gym(), (int)$m['id'], $d['plan_id'], $d);
    }

    private function ms(Request $req): array
    {
        $ms = $this->find('memberships', $req->id(), 'Membership');
        $this->memberRow((int)$ms['member_id']);
        return $ms;
    }

    public function freeze(Request $req): array
    {
        $ms = $this->ms($req);
        $d = V::check($req->json(), ['from' => 'required|date', 'days' => 'required|int|min:1|max:365']);
        return Memberships::freeze($this->gym(), (int)$ms['id'], $d['from'], $d['days']);
    }

    public function unfreeze(Request $req): array { $ms = $this->ms($req); Memberships::unfreeze($this->gym(), (int)$ms['id']); return ['ok' => true]; }

    public function extend(Request $req): array
    {
        Auth::require('billing.manage');
        $ms = $this->ms($req);
        $d = V::check($req->json(), ['days' => 'required|int|min:1|max:365', 'reason' => 'required|string|max:150']);
        Memberships::extend($this->gym(), (int)$ms['id'], $d['days'], $d['reason']);
        return ['ok' => true];
    }

    public function upgrade(Request $req): array
    {
        $ms = $this->ms($req);
        $d = V::check($req->json(), ['plan_id' => 'required|int', 'pay_amount' => 'nullable|num|min:0', 'pay_mode' => 'nullable|in:cash,upi,card,netbanking', 'pay_reference' => 'nullable|string|max:100']);
        return Memberships::upgrade($this->gym(), (int)$ms['id'], $d['plan_id'], ['amount' => $d['pay_amount'] ?? 0, 'mode' => $d['pay_mode'] ?? 'cash', 'reference' => $d['pay_reference'] ?? null]);
    }

    public function transfer(Request $req): array
    {
        $ms = $this->ms($req);
        $d = V::check($req->json(), ['to_member_id' => 'required|int']);
        return Memberships::transfer($this->gym(), (int)$ms['id'], $d['to_member_id']);
    }

    public function autopay(Request $req): array
    {
        $ms = $this->ms($req);
        $on = (bool)$req->input('enabled', true);
        if ($on) return \App\Services\Razorpay::subscribe($this->gym(), (int)$ms['id']);
        DB::update('memberships', ['autopay' => 0], ['id' => $ms['id']]);
        return ['ok' => true];
    }
}
