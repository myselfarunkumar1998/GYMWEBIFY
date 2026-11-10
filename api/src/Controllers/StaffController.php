<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Util, V};
use App\Services\Access;

final class StaffController extends Base
{
    public function index(Request $req): array
    {
        $g = $this->gym();
        $month = preg_match('/^\d{4}-\d{2}$/', (string)$req->q('month')) ? $req->q('month') : date('Y-m');
        $canPay = Auth::can('payroll.manage');
        $rows = DB::all("SELECT u.id, u.name, u.email, u.phone, u.role, u.title, u.branch_id, u.device_user_id, u.shift_start, u.status, u.last_login_at, u.language,
              u.salary, u.pt_commission_pct, u.sales_commission_pct, b.name AS branch_name,
              (SELECT s.in_at FROM attendance_sessions s WHERE s.user_id = u.id AND DATE(s.in_at) = CURDATE() ORDER BY s.in_at DESC LIMIT 1) AS today_in,
              (SELECT s.out_at FROM attendance_sessions s WHERE s.user_id = u.id AND DATE(s.in_at) = CURDATE() ORDER BY s.in_at DESC LIMIT 1) AS today_out,
              (SELECT COUNT(DISTINCT DATE(s.in_at)) FROM attendance_sessions s WHERE s.user_id = u.id AND DATE_FORMAT(s.in_at, '%Y-%m') = ?) AS days_present,
              (SELECT COUNT(*) FROM pt_appointments p WHERE p.trainer_id = u.id AND p.status IN ('done','no_show') AND DATE_FORMAT(p.starts_at, '%Y-%m') = ?) AS pt_sessions,
              (SELECT COUNT(DISTINCT DATE(s.in_at)) FROM attendance_sessions s WHERE s.user_id = u.id AND DATE_FORMAT(s.in_at, '%Y-%m') = ? AND u.shift_start IS NOT NULL AND TIME(s.in_at) > ADDTIME(u.shift_start, '00:15:00')) AS late_days
            FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.gym_id = ? ORDER BY u.status, FIELD(u.role,'owner','manager','trainer','front_desk','staff'), u.name", [$month, $month, $month, $g]);
        foreach ($rows as &$r) {
            $r['commission'] = $this->commission((int)$r['id'], $month, $r);
            if (!$canPay) unset($r['salary'], $r['pt_commission_pct'], $r['sales_commission_pct'], $r['commission']);
        }
        $onDuty = count(array_filter($rows, fn($r) => $r['today_in'] && !$r['today_out']));
        return ['month' => $month, 'items' => $rows, 'on_duty' => $onDuty, 'total' => count(array_filter($rows, fn($r) => $r['status'] === 'active')),
            'late_total' => array_sum(array_column($rows, 'late_days')), 'payroll' => $canPay ? DB::one('SELECT * FROM payroll_runs WHERE gym_id = ? AND period = ?', [$g, $month]) : null];
    }

    /** Commission = PT % of PT pack sales value used + sales % of memberships/store this staff member sold. */
    private function commission(int $userId, string $month, array $u): float
    {
        $pt = (float)DB::val("SELECT COALESCE(SUM(ms.price / GREATEST(ms.sessions_total,1)),0) FROM pt_appointments p JOIN memberships ms ON ms.id = p.membership_id
            WHERE p.trainer_id = ? AND p.status IN ('done','no_show') AND DATE_FORMAT(p.starts_at, '%Y-%m') = ?", [$userId, $month]);
        $sales = (float)DB::val("SELECT COALESCE(SUM(taxable),0) FROM invoices WHERE created_by = ? AND status <> 'cancelled' AND DATE_FORMAT(invoice_date, '%Y-%m') = ?", [$userId, $month]);
        return round($pt * (float)$u['pt_commission_pct'] / 100 + $sales * (float)$u['sales_commission_pct'] / 100, 2);
    }

    private function rules(bool $create): array
    {
        $r = ['name' => 'required|string|max:120', 'email' => 'required|email|max:150', 'phone' => 'nullable|phone', 'role' => 'required|in:manager,front_desk,trainer,staff',
            'title' => 'nullable|string|max:80', 'branch_id' => 'nullable|int', 'device_user_id' => 'nullable|string|max:20', 'shift_start' => 'nullable|time',
            'salary' => 'nullable|num|min:0', 'pt_commission_pct' => 'nullable|num|min:0|max:100', 'sales_commission_pct' => 'nullable|num|min:0|max:100',
            'status' => 'nullable|in:active,inactive', 'password' => 'nullable|string|max:200'];
        if ($create) $r['password'] = 'required|string|max:200';
        else $r = array_map(fn($x) => str_replace('required|', '', $x), $r);
        return $r;
    }

    public function store(Request $req): array
    {
        Auth::require('staff.manage');
        $d = V::check($req->json(), $this->rules(true));
        if ($err = Auth::checkPasswordStrength($d['password'])) throw HttpException::bad($err, ['password' => $err]);
        if (DB::val('SELECT id FROM users WHERE email = ?', [$d['email']])) throw HttpException::bad('Email already in use', ['email' => 'Already in use']);
        $this->assertBranch($d['branch_id'] ?? null);
        $this->checkPin($d['device_user_id'] ?? null, 0);
        $d['password_hash'] = Auth::hashPassword($d['password']);
        unset($d['password']);
        $id = DB::insert('users', $d + ['gym_id' => $this->gym()]);
        if (!empty($d['device_user_id'])) Access::syncUser($this->gym(), ['device_user_id' => $d['device_user_id'], 'name' => $d['name']], true);
        Util::audit('staff.create', 'user', $id, $d['role']);
        return ['id' => $id];
    }

    private function checkPin(?string $pin, int $userId): void
    {
        if (!$pin) return;
        if (!preg_match('/^\d{1,9}$/', $pin)) throw HttpException::bad('Device ID must be digits', ['device_user_id' => 'Digits only']);
        if (DB::val('SELECT id FROM members WHERE gym_id = ? AND device_user_id = ? AND deleted_at IS NULL', [$this->gym(), $pin])
            || DB::val('SELECT id FROM users WHERE gym_id = ? AND device_user_id = ? AND id <> ?', [$this->gym(), $pin, $userId])) {
            throw HttpException::bad('Device ID already used', ['device_user_id' => 'Already used']);
        }
    }

    public function update(Request $req): array
    {
        Auth::require('staff.manage');
        $u = $this->find('users', $req->id(), 'Staff member');
        $d = V::check($req->json(), $this->rules(false));
        if ($u['role'] === 'owner' && isset($d['role'])) throw HttpException::bad('The owner role cannot be changed here');
        if ($u['role'] === 'owner' && ($d['status'] ?? 'active') !== 'active') throw HttpException::bad('The owner cannot be deactivated');
        if (isset($d['email']) && DB::val('SELECT id FROM users WHERE email = ? AND id <> ?', [$d['email'], $u['id']])) throw HttpException::bad('Email already in use', ['email' => 'Already in use']);
        $this->assertBranch($d['branch_id'] ?? null);
        $this->checkPin($d['device_user_id'] ?? null, (int)$u['id']);
        if (!empty($d['password'])) {
            if ($err = Auth::checkPasswordStrength($d['password'])) throw HttpException::bad($err, ['password' => $err]);
            $d['password_hash'] = Auth::hashPassword($d['password']);
            Auth::endAllSessions('staff', (int)$u['id']);
        }
        unset($d['password']);
        DB::update('users', $d, ['id' => $u['id'], 'gym_id' => $this->gym()]);
        if (($d['status'] ?? null) === 'inactive') Auth::endAllSessions('staff', (int)$u['id']);
        Util::audit('staff.update', 'user', (int)$u['id'], array_keys($d));
        return ['ok' => true];
    }

    public function attendance(Request $req): array
    {
        $u = $this->find('users', $req->id(), 'Staff member');
        $month = preg_match('/^\d{4}-\d{2}$/', (string)$req->q('month')) ? $req->q('month') : date('Y-m');
        return DB::all("SELECT in_at, out_at, duration_min, method, auto_closed FROM attendance_sessions WHERE user_id = ? AND DATE_FORMAT(in_at, '%Y-%m') = ? ORDER BY in_at", [$u['id'], $month]);
    }

    public function runPayroll(Request $req): array
    {
        Auth::require('payroll.manage');
        $period = V::check($req->json(), ['period' => 'required|string|max:7'])['period'];
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) throw HttpException::bad('Use YYYY-MM');
        $g = $this->gym();
        $existing = DB::one('SELECT * FROM payroll_runs WHERE gym_id = ? AND period = ?', [$g, $period]);
        if ($existing && $existing['status'] === 'paid') throw HttpException::bad('Payroll for this month is already marked paid');
        $working = (int)($req->input('working_days') ?: $this->workingDays($period));
        return DB::tx(function () use ($g, $period, $existing, $working) {
            if ($existing) { DB::run('DELETE FROM payroll_items WHERE run_id = ?', [$existing['id']]); $runId = (int)$existing['id']; }
            else $runId = DB::insert('payroll_runs', ['gym_id' => $g, 'period' => $period, 'created_by' => Auth::staffId()]);
            $total = 0;
            foreach (DB::all("SELECT * FROM users WHERE gym_id = ? AND status = 'active' AND salary > 0", [$g]) as $u) {
                $days = (int)DB::val("SELECT COUNT(DISTINCT DATE(in_at)) FROM attendance_sessions WHERE user_id = ? AND DATE_FORMAT(in_at, '%Y-%m') = ?", [$u['id'], $period]);
                $pt = (int)DB::val("SELECT COUNT(*) FROM pt_appointments WHERE trainer_id = ? AND status IN ('done','no_show') AND DATE_FORMAT(starts_at, '%Y-%m') = ?", [$u['id'], $period]);
                $base = (float)$u['salary'];
                $deduct = $working > 0 ? round($base / $working * max(0, $working - $days - 4), 2) : 0; // 4 paid leave days
                $comm = $this->commission((int)$u['id'], $period, $u);
                $net = round($base - $deduct + $comm, 2);
                DB::insert('payroll_items', ['run_id' => $runId, 'user_id' => $u['id'], 'days_present' => $days, 'working_days' => $working, 'base_salary' => $base,
                    'pt_sessions' => $pt, 'commission' => $comm, 'deductions' => $deduct, 'net' => $net]);
                $total += $net;
            }
            DB::update('payroll_runs', ['total' => round($total, 2)], ['id' => $runId]);
            Util::audit('payroll.run', 'payroll', $runId, $period);
            return $this->payrollData($runId);
        });
    }

    private function workingDays(string $period): int
    {
        $n = 0; $days = (int)date('t', strtotime("$period-01"));
        for ($d = 1; $d <= $days; $d++) if (date('w', strtotime("$period-$d")) !== '0') $n++;
        return $n;
    }

    private function payrollData(int $runId): array
    {
        $run = DB::one('SELECT * FROM payroll_runs WHERE id = ?', [$runId]);
        $run['items'] = DB::all('SELECT pi.*, u.name, u.role, u.title FROM payroll_items pi JOIN users u ON u.id = pi.user_id WHERE pi.run_id = ? ORDER BY u.name', [$runId]);
        return $run;
    }

    public function payroll(Request $req): array
    {
        Auth::require('payroll.manage');
        $period = (string)$req->params['period'];
        $run = DB::one('SELECT id FROM payroll_runs WHERE gym_id = ? AND period = ?', [$this->gym(), $period]);
        return $run ? $this->payrollData((int)$run['id']) : ['period' => $period, 'items' => [], 'status' => null];
    }

    public function markPaid(Request $req): array
    {
        Auth::require('payroll.manage');
        $run = DB::one('SELECT * FROM payroll_runs WHERE gym_id = ? AND period = ?', [$this->gym(), (string)$req->params['period']]);
        if (!$run) throw HttpException::notFound('Run payroll first');
        DB::update('payroll_runs', ['status' => 'paid'], ['id' => $run['id']]);
        DB::insert('expenses', ['gym_id' => $this->gym(), 'category' => 'Salaries', 'amount' => $run['total'], 'expense_date' => Util::today(), 'note' => 'Payroll ' . $run['period'], 'created_by' => Auth::staffId()]);
        Util::audit('payroll.paid', 'payroll', (int)$run['id']);
        return ['ok' => true];
    }
}
