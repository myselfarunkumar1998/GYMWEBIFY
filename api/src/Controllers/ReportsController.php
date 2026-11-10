<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Response, Util, V};

final class ReportsController extends Base
{
    private function range(Request $req): array
    {
        $to = $req->q('to') ? V::check(['d' => $req->q('to')], ['d' => 'date'])['d'] : Util::today();
        $from = $req->q('from') ? V::check(['d' => $req->q('from')], ['d' => 'date'])['d'] : date('Y-m-01', strtotime($to));
        if ($from > $to) throw HttpException::bad('From must be before To');
        return [$from, $to];
    }

    public function summary(Request $req): array
    {
        $g = $this->gym();
        [$from, $to] = $this->range($req);
        [$bfI] = $this->branchFilter($req, 'i.branch_id');
        [$bfS] = $this->branchFilter($req, 's.branch_id');
        [$bfM] = $this->branchFilter($req, 'm.branch_id');
        $fin = Auth::can('reports.finance');

        $revenue = $fin ? DB::all("SELECT DATE_FORMAT(p.paid_at, '%Y-%m') ym, SUM(p.amount) amount FROM payments p JOIN invoices i ON i.id = p.invoice_id
            WHERE p.gym_id = ? AND p.paid_at >= DATE_FORMAT(? - INTERVAL 11 MONTH, '%Y-%m-01') AND DATE(p.paid_at) <= ? $bfI GROUP BY ym ORDER BY ym", [$g, $to, $to]) : [];
        $byKind = $fin ? DB::all("SELECT i.kind, SUM(i.taxable) amount FROM invoices i WHERE i.gym_id = ? AND i.invoice_date BETWEEN ? AND ? AND i.status <> 'cancelled' $bfI GROUP BY i.kind", [$g, $from, $to]) : [];
        $expenses = $fin ? (float)DB::val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE gym_id = ? AND expense_date BETWEEN ? AND ?', [$g, $from, $to]) : null;
        $collected = $fin ? (float)DB::val("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.gym_id = ? AND DATE(p.paid_at) BETWEEN ? AND ? $bfI", [$g, $from, $to]) : null;

        $members = DB::one("SELECT
            (SELECT COUNT(*) FROM members m WHERE m.gym_id = ? AND m.deleted_at IS NULL AND DATE(m.created_at) BETWEEN ? AND ? $bfM) AS new_members,
            (SELECT COUNT(DISTINCT ms.member_id) FROM memberships ms JOIN members m ON m.id = ms.member_id WHERE ms.gym_id = ? AND ms.kind = 'membership' AND ms.status IN ('active','frozen') AND ms.end_date >= CURDATE() $bfM) AS active,
            (SELECT COUNT(DISTINCT ms.member_id) FROM memberships ms JOIN members m ON m.id = ms.member_id WHERE ms.gym_id = ? AND ms.kind = 'membership' AND ms.end_date BETWEEN ? AND ? $bfM) AS due_to_renew,
            (SELECT COUNT(DISTINCT ms.member_id) FROM memberships ms JOIN members m ON m.id = ms.member_id WHERE ms.gym_id = ? AND ms.kind = 'membership' AND ms.end_date BETWEEN ? AND ? $bfM
               AND EXISTS (SELECT 1 FROM memberships n WHERE n.member_id = ms.member_id AND n.kind = 'membership' AND n.start_date > ms.start_date AND n.status <> 'cancelled')) AS renewed",
            [$g, $from, $to, $g, $g, $from, $to, $g, $from, $to]);
        $members['renewal_rate'] = $members['due_to_renew'] ? round($members['renewed'] / $members['due_to_renew'] * 100) : null;
        $members['churn_rate'] = $members['due_to_renew'] ? 100 - $members['renewal_rate'] : null;

        $heat = DB::all("SELECT DAYOFWEEK(s.in_at) - 1 AS dow, HOUR(s.in_at) AS h, COUNT(*) n FROM attendance_sessions s WHERE s.gym_id = ? AND s.member_id IS NOT NULL AND DATE(s.in_at) BETWEEN ? AND ? $bfS GROUP BY dow, h", [$g, $from, $to]);
        $att = DB::one("SELECT COUNT(*) visits, ROUND(AVG(s.duration_min)) avg_min, COUNT(DISTINCT s.member_id) unique_members, SUM(s.auto_closed) missed_out FROM attendance_sessions s WHERE s.gym_id = ? AND s.member_id IS NOT NULL AND DATE(s.in_at) BETWEEN ? AND ? $bfS", [$g, $from, $to]);
        $leads = DB::all("SELECT source, COUNT(*) total, SUM(stage = 'joined') joined FROM leads WHERE gym_id = ? AND DATE(created_at) BETWEEN ? AND ? GROUP BY source ORDER BY total DESC", [$g, $from, $to]);
        $leadsByStaff = DB::all("SELECT u.name, COUNT(*) total, SUM(l.stage = 'joined') joined FROM leads l JOIN users u ON u.id = l.assigned_to WHERE l.gym_id = ? AND DATE(l.created_at) BETWEEN ? AND ? GROUP BY u.id", [$g, $from, $to]);
        $trainers = DB::all("SELECT u.name, COUNT(p.id) sessions, SUM(p.status = 'done') done, SUM(p.status = 'no_show') no_show,
              (SELECT COUNT(*) FROM members m WHERE m.trainer_id = u.id AND m.deleted_at IS NULL) clients
            FROM users u LEFT JOIN pt_appointments p ON p.trainer_id = u.id AND DATE(p.starts_at) BETWEEN ? AND ? WHERE u.gym_id = ? AND u.role = 'trainer' GROUP BY u.id", [$from, $to, $g]);
        $classes = DB::all("SELECT c.name, c.capacity, COUNT(b.id) bookings, SUM(b.status = 'attended') attended, SUM(b.status = 'no_show') no_show, COUNT(DISTINCT b.class_date) sessions
            FROM classes c LEFT JOIN class_bookings b ON b.class_id = c.id AND b.class_date BETWEEN ? AND ? WHERE c.gym_id = ? GROUP BY c.id", [$from, $to, $g]);
        foreach ($classes as &$c) $c['fill_pct'] = $c['sessions'] ? round($c['bookings'] / ($c['sessions'] * $c['capacity']) * 100) : 0;

        return ['from' => $from, 'to' => $to, 'revenue_by_month' => $revenue, 'revenue_by_kind' => $byKind, 'collected' => $collected, 'expenses' => $expenses,
            'profit' => $fin ? $collected - $expenses : null, 'members' => $members, 'attendance' => $att, 'heatmap' => $heat, 'leads_by_source' => $leads,
            'leads_by_staff' => $leadsByStaff, 'trainers' => $trainers, 'classes' => $classes,
            'dues' => $fin ? (float)DB::val("SELECT COALESCE(SUM(balance),0) FROM invoices i WHERE i.gym_id = ? AND i.status IN ('unpaid','partial') $bfI", [$g]) : null];
    }

    /** CSV downloads for every report. */
    public function export(Request $req): Response
    {
        $g = $this->gym();
        [$from, $to] = $this->range($req);
        $kind = $req->params['kind'] ?? '';
        $finance = ['collections', 'dues', 'expenses', 'invoices'];
        if (in_array($kind, $finance, true)) Auth::require('reports.finance');
        [$rows, $head] = match ($kind) {
            'collections' => [DB::all('SELECT DATE(p.paid_at), i.invoice_no, i.customer_name, p.mode, p.amount, p.reference FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.gym_id = ? AND DATE(p.paid_at) BETWEEN ? AND ? ORDER BY p.paid_at', [$g, $from, $to]), ['Date', 'Invoice', 'Customer', 'Mode', 'Amount', 'Reference']],
            'invoices' => [DB::all('SELECT invoice_no, invoice_date, customer_name, kind, taxable, cgst, sgst, igst, total, paid, balance, status FROM invoices WHERE gym_id = ? AND invoice_date BETWEEN ? AND ? ORDER BY id', [$g, $from, $to]), ['Invoice', 'Date', 'Customer', 'Type', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total', 'Paid', 'Balance', 'Status']],
            'dues' => [DB::all("SELECT m.member_code, m.name, m.phone, SUM(i.balance), MIN(i.due_date) FROM invoices i JOIN members m ON m.id = i.member_id WHERE i.gym_id = ? AND i.status IN ('unpaid','partial') GROUP BY m.id", [$g]), ['Member ID', 'Name', 'Phone', 'Balance', 'Oldest due']],
            'expenses' => [DB::all('SELECT expense_date, category, amount, note FROM expenses WHERE gym_id = ? AND expense_date BETWEEN ? AND ? ORDER BY expense_date', [$g, $from, $to]), ['Date', 'Category', 'Amount', 'Note']],
            'attendance' => [DB::all('SELECT DATE(s.in_at), m.member_code, m.name, TIME(s.in_at), TIME(s.out_at), s.duration_min, s.method, s.auto_closed FROM attendance_sessions s JOIN members m ON m.id = s.member_id WHERE s.gym_id = ? AND DATE(s.in_at) BETWEEN ? AND ? ORDER BY s.in_at', [$g, $from, $to]), ['Date', 'Member ID', 'Name', 'IN', 'OUT', 'Minutes', 'Method', 'Auto-closed']],
            'expiring' => [DB::all("SELECT m.member_code, m.name, m.phone, p.name, ms.end_date, ms.autopay FROM memberships ms JOIN members m ON m.id = ms.member_id JOIN plans p ON p.id = ms.plan_id WHERE ms.gym_id = ? AND ms.kind = 'membership' AND ms.status = 'active' AND ms.end_date BETWEEN ? AND ? ORDER BY ms.end_date", [$g, $from, $to]), ['Member ID', 'Name', 'Phone', 'Plan', 'Ends', 'AutoPay']],
            'leads' => [DB::all('SELECT l.created_at, l.name, l.phone, l.source, l.stage, u.name, l.lost_reason FROM leads l LEFT JOIN users u ON u.id = l.assigned_to WHERE l.gym_id = ? AND DATE(l.created_at) BETWEEN ? AND ? ORDER BY l.id', [$g, $from, $to]), ['Created', 'Name', 'Phone', 'Source', 'Stage', 'Assigned', 'Lost reason']],
            'staff_attendance' => [DB::all('SELECT DATE(s.in_at), u.name, u.role, TIME(s.in_at), TIME(s.out_at), s.duration_min FROM attendance_sessions s JOIN users u ON u.id = s.user_id WHERE s.gym_id = ? AND DATE(s.in_at) BETWEEN ? AND ? ORDER BY s.in_at', [$g, $from, $to]), ['Date', 'Name', 'Role', 'IN', 'OUT', 'Minutes']],
            default => throw HttpException::notFound('Unknown report'),
        };
        Util::audit('report.export', null, null, $kind);
        return Response::file(Util::csv($rows, $head), 'text/csv', "$kind-$from-to-$to.csv");
    }

    public function branches(Request $req): array
    {
        $g = $this->gym();
        $m = date('Y-m');
        $out = [];
        foreach (DB::all('SELECT * FROM branches WHERE gym_id = ? AND is_active = 1', [$g]) as $b) {
            $id = (int)$b['id'];
            $out[] = $b + [
                'inside' => (int)DB::val('SELECT COUNT(*) FROM attendance_sessions WHERE branch_id = ? AND member_id IS NOT NULL AND out_at IS NULL AND DATE(in_at) = CURDATE()', [$id]),
                'checkins_today' => (int)DB::val('SELECT COUNT(*) FROM attendance_sessions WHERE branch_id = ? AND member_id IS NOT NULL AND DATE(in_at) = CURDATE()', [$id]),
                'collected_today' => (float)DB::val('SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.branch_id = ? AND DATE(p.paid_at) = CURDATE()', [$id]),
                'dues' => (float)DB::val("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE branch_id = ? AND status IN ('unpaid','partial')", [$id]),
                'active_members' => (int)DB::val("SELECT COUNT(DISTINCT m.id) FROM members m JOIN memberships ms ON ms.member_id = m.id WHERE m.branch_id = ? AND m.deleted_at IS NULL AND ms.kind = 'membership' AND ms.status IN ('active','frozen') AND ms.end_date >= CURDATE()", [$id]),
                'new_joins' => (int)DB::val("SELECT COUNT(*) FROM members WHERE branch_id = ? AND deleted_at IS NULL AND DATE_FORMAT(created_at, '%Y-%m') = ?", [$id, $m]),
                'collected_month' => (float)DB::val("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.branch_id = ? AND DATE_FORMAT(p.paid_at, '%Y-%m') = ?", [$id, $m]),
                'avg_stay' => (int)DB::val("SELECT ROUND(AVG(duration_min)) FROM attendance_sessions WHERE branch_id = ? AND member_id IS NOT NULL AND DATE_FORMAT(in_at, '%Y-%m') = ?", [$id, $m]),
                'missed_out' => (int)DB::val("SELECT COUNT(*) FROM attendance_sessions WHERE branch_id = ? AND auto_closed = 1 AND DATE_FORMAT(in_at, '%Y-%m') = ?", [$id, $m]),
                'churn_risk' => (int)DB::val('SELECT COUNT(*) FROM members WHERE branch_id = ? AND churn_score >= 60 AND deleted_at IS NULL', [$id]),
                'devices_online' => (int)DB::val('SELECT COUNT(*) FROM devices WHERE branch_id = ? AND last_seen_at > NOW() - INTERVAL 5 MINUTE', [$id]),
                'manager' => DB::val("SELECT name FROM users WHERE branch_id = ? AND role = 'manager' AND status = 'active' LIMIT 1", [$id]),
            ];
        }
        return $out;
    }
}
