<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use App\Core\Util;

/** Gym subscriptions to the software: reminders before "Paid till", automatic suspension after it plus grace. */
final class Subscriptions
{
    public static function graceDays(): int
    {
        return max(0, (int)Config::get('subscription_grace_days', 3));
    }

    /** Suspend gyms whose paid-till date plus the grace days has passed. The demo gym is never suspended. */
    public static function suspendLapsed(): int
    {
        $rows = DB::all("SELECT id, name, settings FROM gyms WHERE status IN ('active','trial') AND subscription_until IS NOT NULL
            AND subscription_until < CURDATE() - INTERVAL ? DAY", [self::graceDays()]);
        $n = 0;
        foreach ($rows as $g) {
            $settings = json_decode($g['settings'] ?? '{}', true) ?: [];
            if (!empty($settings['is_demo'])) continue;
            DB::run("UPDATE gyms SET status = 'suspended', settings = JSON_SET(COALESCE(settings, JSON_OBJECT()), '$.auto_suspended', TRUE) WHERE id = ?", [$g['id']]);
            DB::run('DELETE FROM auth_sessions WHERE gym_id = ?', [$g['id']]);
            DB::insert('audit_log', ['gym_id' => (int)$g['id'], 'actor_type' => 'system', 'action' => 'platform.gym_auto_suspended', 'entity' => 'gym', 'entity_id' => (int)$g['id'], 'details' => 'Paid-till date passed']);
            $n++;
        }
        if ($n) Util::forgetGym();
        return $n;
    }

    /** Email the gym's owners 7, 3 and 1 days before the paid-till date, and on the day. */
    public static function remind(): int
    {
        $rows = DB::all("SELECT g.id, g.name, g.subscription_until, DATEDIFF(g.subscription_until, CURDATE()) AS days_left, u.id AS user_id, u.name AS owner_name, u.email
            FROM gyms g JOIN users u ON u.gym_id = g.id AND u.role = 'owner' AND u.status = 'active'
            WHERE g.status IN ('active','trial') AND g.subscription_until IS NOT NULL AND DATEDIFF(g.subscription_until, CURDATE()) IN (7, 3, 1, 0)");
        $n = 0;
        foreach ($rows as $r) {
            if (!$r['email']) continue;
            $settings = Util::gym((int)$r['id'])['settings'];
            if (!empty($settings['is_demo'])) continue;
            $end = date('j M Y', strtotime($r['subscription_until']));
            $off = date('j M Y', strtotime($r['subscription_until'] . ' +' . (self::graceDays() + 1) . ' days'));
            $when = (int)$r['days_left'] === 0 ? 'today' : 'in ' . $r['days_left'] . ' day' . ((int)$r['days_left'] === 1 ? '' : 's') . " ($end)";
            $body = "Hi {$r['owner_name']},\n\nYour GymOS subscription for {$r['name']} ends $when.\n"
                . "Please renew to keep the software, member app and door access running. If it is not renewed, the account will be suspended on $off.\n\n"
                . 'Sign in: ' . rtrim((string)Config::get('app_url'), '/') . "/login\n\nThank you,\nGymOS";
            Messenger::queue((int)$r['id'], 'email', $r['email'], $body, ['user_id' => (int)$r['user_id'], 'payload' => ['subject' => "GymOS subscription ends $when"]]);
            $n++;
        }
        return $n;
    }
}
