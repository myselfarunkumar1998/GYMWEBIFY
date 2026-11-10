<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{DB, Util};

/**
 * Churn risk score (0–100) per active member, recomputed nightly.
 * A transparent weighted model over visit frequency, recency, stay time, dues, plan end and app use,
 * so staff can see why a member was flagged.
 */
final class Churn
{
    public static function scoreMember(int $gymId, array $m, ?array $ms): array
    {
        $today = Util::today();
        $score = 0; $reasons = [];
        $last = DB::val('SELECT MAX(in_at) FROM attendance_sessions WHERE gym_id = ? AND member_id = ?', [$gymId, $m['id']]);
        $daysSince = $last ? Util::daysBetween(substr((string)$last, 0, 10), $today) : 999;
        $recent = (int)DB::val('SELECT COUNT(*) FROM attendance_sessions WHERE gym_id = ? AND member_id = ? AND in_at >= ?', [$gymId, $m['id'], Util::addDays($today, -14)]);
        $prior = (int)DB::val('SELECT COUNT(*) FROM attendance_sessions WHERE gym_id = ? AND member_id = ? AND in_at >= ? AND in_at < ?', [$gymId, $m['id'], Util::addDays($today, -56), Util::addDays($today, -14)]);
        $priorPer2w = $prior / 3;
        $stayRecent = (float)DB::val('SELECT AVG(duration_min) FROM attendance_sessions WHERE gym_id = ? AND member_id = ? AND in_at >= ? AND duration_min IS NOT NULL', [$gymId, $m['id'], Util::addDays($today, -14)]);
        $stayPrior = (float)DB::val('SELECT AVG(duration_min) FROM attendance_sessions WHERE gym_id = ? AND member_id = ? AND in_at >= ? AND in_at < ? AND duration_min IS NOT NULL', [$gymId, $m['id'], Util::addDays($today, -56), Util::addDays($today, -14)]);

        if ($daysSince >= 21) { $score += 45; $reasons[] = "No visit in $daysSince days."; }
        elseif ($daysSince >= 10) { $score += 32; $reasons[] = "No visit in $daysSince days."; }
        elseif ($daysSince >= 6) { $score += 15; }

        if ($priorPer2w >= 3 && $recent <= $priorPer2w * 0.4) {
            $score += 25;
            $reasons[] = 'Was ' . round($priorPer2w / 2, 1) . ' visits a week, now ' . round($recent / 2, 1) . '.';
        } elseif ($priorPer2w >= 2 && $recent < $priorPer2w * 0.7) { $score += 10; }

        if ($stayPrior > 30 && $stayRecent > 0 && $stayRecent < $stayPrior * 0.6) {
            $score += 12;
            $reasons[] = 'Stay dropped from ' . Util::minutesLabel((int)$stayPrior) . ' to ' . Util::minutesLabel((int)$stayRecent) . '.';
        }

        $dues = (float)DB::val("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE gym_id = ? AND member_id = ? AND status IN ('unpaid','partial')", [$gymId, $m['id']]);
        $duesAge = DB::val("SELECT MIN(due_date) FROM invoices WHERE gym_id = ? AND member_id = ? AND status IN ('unpaid','partial')", [$gymId, $m['id']]);
        if ($dues > 0 && $duesAge && Util::daysBetween((string)$duesAge, $today) > 7) {
            $score += 12;
            $reasons[] = 'Dues ₹' . number_format($dues) . ' for ' . Util::daysBetween((string)$duesAge, $today) . ' days.';
        }

        if ($ms) {
            $left = Util::daysBetween($today, $ms['end_date']);
            if ($left >= 0 && $left <= 7 && !$ms['autopay']) { $score += 15; $reasons[] = "Plan ends in $left days, AutoPay off."; }
            if ($left < 0) { $score += 20; $reasons[] = 'Plan expired ' . date('j M', strtotime($ms['end_date'])) . '.'; }
        }

        $noShows = (int)DB::val("SELECT COUNT(*) FROM pt_appointments WHERE gym_id = ? AND member_id = ? AND status = 'no_show' AND starts_at >= ?", [$gymId, $m['id'], Util::addDays($today, -30)]);
        if ($noShows >= 2) { $score += 10; $reasons[] = "Skipped $noShows booked PT sessions."; }

        $appSeen = DB::val("SELECT MAX(last_seen_at) FROM auth_sessions WHERE subject_type = 'member' AND subject_id = ?", [$m['id']]);
        if ($appSeen && Util::daysBetween(substr((string)$appSeen, 0, 10), $today) > 14 && $daysSince >= 10) { $score += 5; $reasons[] = 'App not opened.'; }

        return [min(100, $score), implode(' ', array_slice($reasons, 0, 2)) ?: null];
    }

    public static function run(int $gymId): int
    {
        $members = DB::all('SELECT * FROM members WHERE gym_id = ? AND deleted_at IS NULL', [$gymId]);
        $n = 0;
        foreach ($members as $m) {
            $ms = DB::one("SELECT * FROM memberships WHERE gym_id = ? AND member_id = ? AND kind = 'membership' AND status <> 'cancelled' ORDER BY end_date DESC LIMIT 1", [$gymId, $m['id']]);
            if (!$ms || Util::daysBetween($ms['end_date'], Util::today()) > 30) {
                DB::update('members', ['churn_score' => null, 'churn_reason' => null], ['id' => $m['id']]);
                continue;
            }
            [$score, $reason] = self::scoreMember($gymId, $m, $ms);
            DB::update('members', ['churn_score' => $score, 'churn_reason' => $reason], ['id' => $m['id']]);
            $n++;
        }
        return $n;
    }
}
