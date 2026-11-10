<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** In-app notifications for staff (owner app feed) and members, plus optional web push. */
final class Notify
{
    public static function staff(int $gymId, string $title, ?string $body = null, ?string $link = null, string $severity = 'info', bool $push = false): void
    {
        // Avoid duplicates of the same alert within 10 minutes.
        $dupe = DB::val("SELECT id FROM notifications WHERE gym_id = ? AND audience = 'staff' AND title = ? AND created_at > NOW() - INTERVAL 10 MINUTE", [$gymId, mb_substr($title, 0, 150)]);
        if ($dupe) return;
        DB::insert('notifications', ['gym_id' => $gymId, 'audience' => 'staff', 'title' => mb_substr($title, 0, 150), 'body' => $body ? mb_substr($body, 0, 500) : null, 'link' => $link, 'severity' => $severity]);
        if ($push) {
            foreach (DB::all("SELECT s.endpoint FROM staff_push_subscriptions s JOIN users u ON u.id = s.user_id WHERE u.gym_id = ? AND u.role IN ('owner','manager')", [$gymId]) as $s) {
                Push::send($s['endpoint']);
            }
        }
    }

    public static function member(int $gymId, int $memberId, string $title, ?string $body = null, ?string $link = null, bool $push = true): void
    {
        DB::insert('notifications', ['gym_id' => $gymId, 'audience' => 'member', 'member_id' => $memberId, 'title' => mb_substr($title, 0, 150), 'body' => $body ? mb_substr($body, 0, 500) : null, 'link' => $link]);
        if ($push) {
            $sub = DB::val('SELECT push_subscription FROM members WHERE id = ?', [$memberId]);
            if ($sub) Push::send($sub);
        }
    }
}
