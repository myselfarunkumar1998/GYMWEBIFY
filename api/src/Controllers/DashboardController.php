<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, Request, Util};

final class DashboardController extends Base
{
    public function index(Request $req): array
    {
        $g = $this->gym();
        [$bf] = $this->branchFilter($req, 's.branch_id');
        [$bfi] = $this->branchFilter($req, 'i.branch_id');
        $today = Util::today();
        $capacity = (int)DB::val('SELECT COALESCE(SUM(capacity),0) FROM branches s WHERE s.gym_id = ? AND s.is_active = 1' . str_replace('s.branch_id', 's.id', $bf), [$g]);
        $inside = (int)DB::val("SELECT COUNT(*) FROM attendance_sessions s WHERE s.gym_id = ? AND s.member_id IS NOT NULL AND s.out_at IS NULL AND DATE(s.in_at) = ? $bf", [$g, $today]);
        $avgStay = DB::val("SELECT AVG(duration_min) FROM attendance_sessions s WHERE s.gym_id = ? AND s.member_id IS NOT NULL AND DATE(s.in_at) = ? AND s.duration_min IS NOT NULL $bf", [$g, $today]);
        $methods = DB::all("SELECT method, COUNT(*) n FROM attendance_sessions s WHERE s.gym_id = ? AND s.member_id IS NOT NULL AND DATE(s.in_at) = ? $bf GROUP BY method", [$g, $today]);
        $checkins = array_sum(array_column($methods, 'n'));

        $finance = Auth::can('billing.view');
        $collected = $finance ? DB::all("SELECT p.mode, SUM(p.amount) amt FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.gym_id = ? AND DATE(p.paid_at) = ? $bfi GROUP BY p.mode", [$g, $today]) : [];
        $dues = $finance ? DB::one("SELECT COALESCE(SUM(i.balance),0) amt, COUNT(DISTINCT i.member_id) members FROM invoices i WHERE i.gym_id = ? AND i.status IN ('unpaid','partial') $bfi", [$g]) : null;

        $punches = DB::all("SELECT s.id, s.in_at, s.out_at, s.duration_min, s.auto_closed, s.method, m.id AS member_id, m.name, m.member_code,
              (SELECT p.name FROM memberships ms JOIN plans p ON p.id = ms.plan_id WHERE ms.member_id = m.id AND ms.kind = 'membership' AND ms.status IN ('active','frozen') ORDER BY ms.end_date DESC LIMIT 1) AS plan_name,
              (SELECT ms.end_date FROM memberships ms WHERE ms.member_id = m.id AND ms.kind = 'membership' AND ms.status IN ('active','frozen') ORDER BY ms.end_date DESC LIMIT 1) AS end_date,
              (SELECT COALESCE(SUM(i.balance),0) FROM invoices i WHERE i.member_id = m.id AND i.status IN ('unpaid','partial')) AS dues
            FROM attendance_sessions s JOIN members m ON m.id = s.member_id WHERE s.gym_id = ? AND DATE(s.in_at) = ? $bf ORDER BY GREATEST(s.in_at, COALESCE(s.out_at, s.in_at)) DESC LIMIT 12", [$g, $today]);

        $renewals = DB::all("SELECT m.id, m.name, p.name AS plan_name, p.price, ms.end_date, ms.autopay FROM memberships ms JOIN members m ON m.id = ms.member_id JOIN plans p ON p.id = ms.plan_id
            WHERE ms.gym_id = ? AND ms.kind = 'membership' AND ms.status = 'active' AND ms.end_date BETWEEN ? AND ? AND m.deleted_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM memberships x WHERE x.member_id = ms.member_id AND x.kind = 'membership' AND x.status = 'upcoming') ORDER BY ms.end_date", [$g, $today, Util::addDays($today, 7)]);

        $hours = DB::all("SELECT HOUR(s.in_at) h, COUNT(*) n FROM attendance_sessions s WHERE s.gym_id = ? AND s.member_id IS NOT NULL AND DATE(s.in_at) = ? $bf GROUP BY HOUR(s.in_at)", [$g, $today]);
        // Members inside per hour: sessions overlapping each hour window.
        $occ = [];
        $sessions = DB::all("SELECT in_at, COALESCE(out_at, NOW()) out_at FROM attendance_sessions s WHERE s.gym_id = ? AND s.member_id IS NOT NULL AND DATE(s.in_at) = ? $bf", [$g, $today]);
        for ($h = 5; $h <= 22; $h++) {
            $start = strtotime("$today $h:00:00"); $end = $start + 3600; $n = 0;
            foreach ($sessions as $s) if (strtotime($s['in_at']) < $end && strtotime($s['out_at']) > $start) $n++;
            $occ[] = ['hour' => $h, 'count' => $n];
        }

        $risk = (int)DB::val('SELECT COUNT(*) FROM members WHERE gym_id = ? AND churn_score >= 60 AND deleted_at IS NULL', [$g]);
        return [
            'inside' => $inside, 'capacity' => $capacity, 'avg_stay_min' => $avgStay !== null ? (int)$avgStay : null,
            'checkins' => $checkins, 'checkins_by_method' => $methods,
            'collected' => $finance ? ['total' => array_sum(array_map('floatval', array_column($collected, 'amt'))), 'by_mode' => $collected] : null,
            'dues' => $dues ? ['amount' => (float)$dues['amt'], 'members' => (int)$dues['members']] : null,
            'punches' => $punches, 'renewals' => $renewals, 'occupancy' => $occ, 'churn_risk' => $risk,
            'new_members_today' => (int)DB::val('SELECT COUNT(*) FROM members WHERE gym_id = ? AND DATE(created_at) = ? AND deleted_at IS NULL', [$g, $today]),
            'missed_out_yesterday' => (int)DB::val('SELECT COUNT(*) FROM attendance_sessions WHERE gym_id = ? AND auto_closed = 1 AND DATE(in_at) = ?', [$g, Util::addDays($today, -1)]),
            'insights' => (new InsightsController())->feed($g, 2),
        ];
    }
}
