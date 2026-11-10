<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Outgoing webhooks: queued per subscriber, signed with HMAC-SHA256, retried with back-off by cron. */
final class Webhooks
{
    public const EVENTS = ['member.created', 'member.updated', 'payment.received', 'checkin', 'lead.created', 'membership.expired'];

    public static function emit(int $gymId, string $event, array $data): void
    {
        $hooks = DB::all('SELECT id, events FROM webhooks WHERE gym_id = ? AND is_active = 1', [$gymId]);
        foreach ($hooks as $h) {
            $events = array_map('trim', explode(',', $h['events']));
            if (!in_array($event, $events, true) && !in_array('*', $events, true)) continue;
            DB::insert('webhook_events', ['webhook_id' => $h['id'], 'event' => $event,
                'payload' => json_encode(['event' => $event, 'gym_id' => $gymId, 'created_at' => date('c'), 'data' => $data], JSON_UNESCAPED_UNICODE)]);
        }
    }

    public static function deliver(int $limit = 50): int
    {
        $rows = DB::all("SELECT e.*, w.url, w.secret FROM webhook_events e JOIN webhooks w ON w.id = e.webhook_id
            WHERE e.status = 'pending' AND e.next_attempt_at <= NOW() ORDER BY e.id LIMIT " . (int)$limit);
        $n = 0;
        foreach ($rows as $r) {
            $sig = hash_hmac('sha256', $r['payload'], $r['secret']);
            [$code, $body] = Http::request('POST', $r['url'], ['Content-Type: application/json', 'X-Gym-Event: ' . $r['event'], 'X-Gym-Signature: sha256=' . $sig], $r['payload'], 8);
            $attempts = (int)$r['attempts'] + 1;
            if ($code >= 200 && $code < 300) {
                DB::run("UPDATE webhook_events SET status = 'delivered', attempts = ? WHERE id = ?", [$attempts, $r['id']]);
                $n++;
            } else {
                $status = $attempts >= 6 ? 'failed' : 'pending';
                DB::run('UPDATE webhook_events SET status = ?, attempts = ?, last_error = ?, next_attempt_at = NOW() + INTERVAL ? MINUTE WHERE id = ?',
                    [$status, $attempts, "HTTP $code", 2 ** $attempts, $r['id']]);
            }
        }
        return $n;
    }
}
