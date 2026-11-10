<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, Request, Util, V};
use App\Services\{Churn, Messenger};

final class InsightsController extends Base
{
    public function index(Request $req): array
    {
        $g = $this->gym();
        $risk = DB::all("SELECT m.id, m.name, m.member_code, m.phone, m.churn_score, m.churn_reason, m.trainer_id, u.name AS trainer_name, p.name AS plan_name, ms.end_date, ms.price,
              (SELECT COALESCE(SUM(i.balance),0) FROM invoices i WHERE i.member_id = m.id AND i.status IN ('unpaid','partial')) dues
            FROM members m LEFT JOIN users u ON u.id = m.trainer_id
            LEFT JOIN memberships ms ON ms.id = (SELECT x.id FROM memberships x WHERE x.member_id = m.id AND x.kind = 'membership' AND x.status NOT IN ('cancelled','transferred') ORDER BY x.end_date DESC LIMIT 1)
            LEFT JOIN plans p ON p.id = ms.plan_id
            WHERE m.gym_id = ? AND m.deleted_at IS NULL AND m.churn_score >= 50 ORDER BY m.churn_score DESC LIMIT 100", [$g]);
        foreach ($risk as &$r) {
            $r['action'] = $r['dues'] > 0 || ($r['end_date'] && $r['end_date'] < Util::today()) ? 'pay_link' : ($r['trainer_id'] ? 'trainer_call' : 'whatsapp');
        }
        $high = count(array_filter($risk, fn($r) => $r['churn_score'] >= 75));
        $atRisk = array_filter($risk, fn($r) => $r['churn_score'] >= 60);
        $wonBack = DB::one("SELECT COUNT(DISTINCT x.member_id) sent,
              COUNT(DISTINCT CASE WHEN EXISTS (SELECT 1 FROM attendance_sessions a WHERE a.member_id = x.member_id AND a.in_at > x.created_at AND a.in_at < x.created_at + INTERVAL 14 DAY) THEN x.member_id END) returned
            FROM messages x WHERE x.gym_id = ? AND x.tkey IN ('winback','expired_winback') AND x.created_at > NOW() - INTERVAL 60 DAY AND x.created_at < NOW() - INTERVAL 1 DAY", [$g]);
        $bot = DB::one("SELECT COUNT(DISTINCT to_addr) chats FROM messages WHERE gym_id = ? AND direction = 'in' AND DATE(created_at) = CURDATE()", [$g]);
        $botTrials = (int)DB::val("SELECT COUNT(*) FROM leads WHERE gym_id = ? AND source = 'whatsapp_bot' AND stage = 'trial' AND DATE(updated_at) = CURDATE()", [$g]);
        return [
            'at_risk' => count($atRisk), 'high' => $high, 'medium' => count($atRisk) - $high,
            'renewals_at_risk' => array_sum(array_map(fn($r) => (float)$r['price'], $atRisk)),
            'won_back' => $wonBack, 'bot_today' => ['chats' => (int)$bot['chats'], 'trials' => $botTrials],
            'members' => array_values($risk), 'feed' => $this->feed($g, 6),
            'last_scored' => DB::val("SELECT last_run_at FROM cron_runs WHERE job = 'daily_night'"),
        ];
    }

    /** Plain-language observations computed from the gym's own data. */
    public function feed(int $g, int $limit = 6): array
    {
        $out = [];
        $cap = (int)DB::val('SELECT COALESCE(SUM(capacity),0) FROM branches WHERE gym_id = ? AND is_active = 1', [$g]) ?: 100;
        $peak = DB::one("SELECT HOUR(in_at) h, COUNT(*) / COUNT(DISTINCT DATE(in_at)) avg_n FROM attendance_sessions WHERE gym_id = ? AND member_id IS NOT NULL AND in_at > NOW() - INTERVAL 14 DAY GROUP BY h ORDER BY avg_n DESC LIMIT 1", [$g]);
        if ($peak && $peak['avg_n'] >= $cap * 0.35) {
            $out[] = ['kind' => 'capacity', 'title' => date('g A', strtotime("{$peak['h']}:00")) . ' – ' . date('g A', strtotime(($peak['h'] + 1) . ':00')) . ' is your busiest hour',
                'detail' => 'About ' . round((float)$peak['avg_n']) . ' check-ins in that hour on average. An off-peak plan can move some members to quieter hours.', 'action' => 'Offer the off-peak plan', 'link' => 'plans'];
        }
        $risk = (int)DB::val('SELECT COUNT(*) FROM members WHERE gym_id = ? AND churn_score >= 60 AND deleted_at IS NULL', [$g]);
        if ($risk) $out[] = ['kind' => 'churn', 'title' => "$risk members at churn risk", 'detail' => 'Their visits have dropped or their plan is ending without AutoPay.', 'action' => 'Review and send win-back', 'link' => 'insights'];
        $ren = DB::one("SELECT COUNT(*) n, SUM(autopay) ap FROM memberships WHERE gym_id = ? AND kind = 'membership' AND status = 'active' AND end_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY", [$g]);
        if ($ren['n']) $out[] = ['kind' => 'renewals', 'title' => "{$ren['n']} renewals due this week", 'detail' => (int)$ren['ap'] . ' have UPI AutoPay on. ' . ($ren['n'] - (int)$ren['ap']) . ' need a reminder.', 'action' => 'Review reminders', 'link' => 'campaigns'];
        $best = DB::one("SELECT source, SUM(stage = 'joined') / COUNT(*) rate, COUNT(*) n FROM leads WHERE gym_id = ? AND created_at > NOW() - INTERVAL 30 DAY GROUP BY source HAVING n >= 3 ORDER BY rate DESC LIMIT 1", [$g]);
        if ($best && $best['rate'] > 0) $out[] = ['kind' => 'leads', 'title' => ucfirst(str_replace('_', ' ', $best['source'])) . ' leads convert best', 'detail' => round($best['rate'] * 100) . '% of enquiries from this source joined in the last 30 days.', 'action' => 'Open leads', 'link' => 'leads'];
        $forget = DB::all("SELECT m.name, COUNT(*) n FROM attendance_sessions s JOIN members m ON m.id = s.member_id WHERE s.gym_id = ? AND s.auto_closed = 1 AND s.in_at > NOW() - INTERVAL 14 DAY GROUP BY m.id HAVING n >= 3 ORDER BY n DESC LIMIT 5", [$g]);
        if ($forget) $out[] = ['kind' => 'punch', 'title' => count($forget) . ' members often forget to punch OUT', 'detail' => implode(', ', array_column($forget, 'name')) . '. Their stay time is not counted on those days.', 'action' => 'See sessions', 'link' => 'sessions'];
        $dues = DB::one("SELECT COUNT(DISTINCT member_id) n, COALESCE(SUM(balance),0) amt FROM invoices WHERE gym_id = ? AND status IN ('unpaid','partial') AND due_date < CURDATE() - INTERVAL 7 DAY", [$g]);
        if ($dues['n']) $out[] = ['kind' => 'dues', 'title' => '₹' . number_format((float)$dues['amt']) . " overdue from {$dues['n']} members", 'detail' => 'Overdue for more than a week. A UPI pay link on WhatsApp usually clears these fastest.', 'action' => 'Send pay links', 'link' => 'billing'];
        return array_slice($out, 0, $limit);
    }

    /** Send win-back WhatsApp to selected members (or all at risk). */
    public function winback(Request $req): array
    {
        Auth::require('campaigns.send');
        $ids = array_map('intval', (array)$req->input('member_ids', []));
        $sql = 'SELECT * FROM members WHERE gym_id = ? AND deleted_at IS NULL AND churn_score >= 60';
        if ($ids) $sql = 'SELECT * FROM members WHERE gym_id = ? AND deleted_at IS NULL AND id IN (' . DB::inList($ids) . ')';
        $n = 0;
        foreach (DB::all($sql, [$this->gym()]) as $m) if (Messenger::template($this->gym(), 'winback', $m, [], ['force' => true])) $n++;
        Util::audit('insights.winback', null, null, ['count' => $n]);
        return ['queued' => $n];
    }

    public function rescore(Request $req): array
    {
        Auth::require('insights.view');
        return ['scored' => Churn::run($this->gym())];
    }
}
