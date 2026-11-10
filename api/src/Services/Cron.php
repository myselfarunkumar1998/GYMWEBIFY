<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Config, DB, RateLimit, Util};

/**
 * One entry point for cPanel cron ("every 5 minutes"). Each job keeps its own schedule in cron_runs.
 */
final class Cron
{
    private static function due(string $job, int $everySeconds, ?string $atTime = null): bool
    {
        $last = DB::val('SELECT last_run_at FROM cron_runs WHERE job = ?', [$job]);
        if ($atTime) {
            if (date('H:i:s') < $atTime) return false;
            if ($last && substr((string)$last, 0, 10) === date('Y-m-d')) return false;
        } elseif ($last && strtotime((string)$last) > time() - $everySeconds) {
            return false;
        }
        DB::run('REPLACE INTO cron_runs (job, last_run_at) VALUES (?, NOW())', [$job]);
        return true;
    }

    public static function run(bool $force = false): array
    {
        $log = [];
        $lock = DB::val("SELECT GET_LOCK('gymapp_cron', 0)");
        if (!$lock) return ['skipped' => 'another cron run is active'];
        try {
            $log['sessions_auto_closed'] = Attendance::autoCloseDue();
            $log['messages_sent'] = Messenger::dispatch(200);
            $log['webhooks_delivered'] = Webhooks::deliver(50);

            $log['gyms_auto_suspended'] = Subscriptions::suspendLapsed();
            $gyms = DB::all("SELECT id FROM gyms WHERE status <> 'suspended'");
            if ($force || self::due('hourly', 3300)) {
                foreach ($gyms as $g) {
                    $id = (int)$g['id'];
                    self::asGym($id);
                    $log["gym$id.memberships"] = Memberships::maintain($id);
                    $log["gym$id.class_reminders"] = self::classReminders($id);
                    $log["gym$id.class_no_shows"] = self::markNoShows($id);
                    $log["gym$id.lead_followups"] = self::leadFollowups($id);
                }
                RateLimit::gc();
                DB::run('DELETE FROM auth_sessions WHERE expires_at < NOW()');
                DB::run('DELETE FROM otp_codes WHERE expires_at < NOW() - INTERVAL 1 DAY');
            }
            if ($force || self::due('daily_morning', 0, Config::get('cron.morning_time', '08:00:00'))) {
                $log['subscription_reminders'] = Subscriptions::remind();
                foreach ($gyms as $g) {
                    $id = (int)$g['id'];
                    self::asGym($id);
                    $log["gym$id.renewal_reminders"] = self::renewalReminders($id);
                    $log["gym$id.dues_reminders"] = self::duesReminders($id);
                    $log["gym$id.birthdays"] = self::birthdays($id);
                    $log["gym$id.reviews"] = self::reviewRequests($id);
                    $log["gym$id.pt_low"] = self::ptLow($id);
                    $log["gym$id.expired_winback"] = self::expiredWinback($id);
                    $log["gym$id.alerts"] = self::ownerAlerts($id);
                }
            }
            if ($force || self::due('daily_night', 0, Config::get('cron.night_time', '23:30:00'))) {
                foreach ($gyms as $g) {
                    self::asGym((int)$g['id']);
                    $log["gym{$g['id']}.churn_scored"] = Churn::run((int)$g['id']);
                }
            }
            // Send what this run just queued (reminders, birthdays) without waiting for the next run.
            $log['messages_sent'] += Messenger::dispatch(200);
        } finally {
            DB::val("SELECT RELEASE_LOCK('gymapp_cron')");
            Auth::reset();
        }
        return $log;
    }

    private static function asGym(int $id): void
    {
        Auth::reset();
        Auth::$gymId = $id;
        Util::forgetGym();
    }

    private static function renewLink(array $m): string
    {
        return rtrim((string)Config::get('app_url'), '/') . '/m/' . Util::gym((int)$m['gym_id'])['slug'] . '/renew';
    }

    public static function renewalReminders(int $gymId): int
    {
        $days = (array)Util::setting('rules.renewal_reminder_days', [7, 3, 1], $gymId);
        $n = 0;
        foreach ($days as $d) {
            $date = Util::addDays(Util::today(), (int)$d);
            $rows = DB::all("SELECT m.*, ms.end_date, p.name AS plan_name FROM memberships ms JOIN members m ON m.id = ms.member_id JOIN plans p ON p.id = ms.plan_id
                WHERE ms.gym_id = ? AND ms.kind = 'membership' AND ms.status = 'active' AND ms.end_date = ? AND ms.autopay = 0 AND m.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM memberships x WHERE x.member_id = ms.member_id AND x.kind = 'membership' AND x.status = 'upcoming')", [$gymId, $date]);
            foreach ($rows as $r) {
                if (Messenger::template($gymId, 'renewal_reminder', $r, ['plan' => $r['plan_name'], 'date' => date('d M', strtotime($r['end_date'])), 'link' => self::renewLink($r)])) $n++;
                Notify::member($gymId, (int)$r['id'], 'Your plan ends on ' . date('d M', strtotime($r['end_date'])), 'Renew now to keep your access.', 'renew');
            }
        }
        return $n;
    }

    public static function duesReminders(int $gymId): int
    {
        $every = max(1, (int)Util::setting('rules.dues_reminder_every_days', 3, $gymId));
        $rows = DB::all("SELECT i.id AS invoice_id, i.invoice_no, i.balance, i.due_date, m.* FROM invoices i JOIN members m ON m.id = i.member_id
            WHERE i.gym_id = ? AND i.status IN ('unpaid','partial') AND i.due_date <= CURDATE() AND MOD(DATEDIFF(CURDATE(), i.due_date), ?) = 0 AND m.deleted_at IS NULL", [$gymId, $every]);
        $n = 0;
        foreach ($rows as $r) {
            $link = self::renewLink($r);
            try { $link = Razorpay::paymentLink($gymId, (int)$r['invoice_id'])['short_url'] ?? $link; } catch (\Throwable) {}
            if (Messenger::template($gymId, 'dues_reminder', $r, ['amount' => number_format((float)$r['balance'], 2), 'invoice' => $r['invoice_no'], 'link' => $link])) $n++;
        }
        return $n;
    }

    public static function birthdays(int $gymId): int
    {
        $rows = DB::all("SELECT * FROM members WHERE gym_id = ? AND deleted_at IS NULL AND dob IS NOT NULL AND DATE_FORMAT(dob, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')", [$gymId]);
        foreach ($rows as $r) Messenger::template($gymId, 'birthday', $r);
        return count($rows);
    }

    public static function reviewRequests(int $gymId): int
    {
        $url = Util::setting('google_review_url', null, $gymId);
        if (!$url) return 0;
        $days = (int)Util::setting('rules.review_after_days', 30, $gymId);
        $rows = DB::all('SELECT * FROM members WHERE gym_id = ? AND deleted_at IS NULL AND DATE(created_at) = ?', [$gymId, Util::addDays(Util::today(), -$days)]);
        foreach ($rows as $r) Messenger::template($gymId, 'review_request', $r, ['link' => $url]);
        return count($rows);
    }

    public static function ptLow(int $gymId): int
    {
        $rows = DB::all("SELECT m.*, (ms.sessions_total - ms.sessions_used) AS left_count FROM memberships ms JOIN members m ON m.id = ms.member_id
            WHERE ms.gym_id = ? AND ms.kind = 'pt' AND ms.status = 'active' AND ms.sessions_total > 0 AND ms.sessions_total - ms.sessions_used = 2", [$gymId]);
        foreach ($rows as $r) Messenger::template($gymId, 'pt_low', $r, ['count' => $r['left_count']]);
        return count($rows);
    }

    public static function expiredWinback(int $gymId): int
    {
        $rows = DB::all("SELECT m.* FROM members m JOIN memberships ms ON ms.member_id = m.id WHERE m.gym_id = ? AND m.deleted_at IS NULL AND ms.kind = 'membership'
            GROUP BY m.id HAVING MAX(ms.end_date) = ?", [$gymId, Util::addDays(Util::today(), -14)]);
        foreach ($rows as $r) {
            Messenger::template($gymId, 'expired_winback', $r, ['offer' => Util::setting('rules.winback_offer', 'no joining fee', $gymId), 'link' => self::renewLink($r)]);
            Webhooks::emit($gymId, 'membership.expired', ['member_id' => (int)$r['id']]);
        }
        return count($rows);
    }

    public static function classReminders(int $gymId): int
    {
        $from = date('H:i:s'); $to = date('H:i:s', time() + 3 * 3600);
        $rows = DB::all("SELECT b.id, c.name AS class_name, c.start_time, m.* FROM class_bookings b JOIN classes c ON c.id = b.class_id JOIN members m ON m.id = b.member_id
            WHERE b.gym_id = ? AND b.class_date = CURDATE() AND b.status = 'booked' AND c.start_time BETWEEN ? AND ?
            AND NOT EXISTS (SELECT 1 FROM messages x WHERE x.member_id = m.id AND x.tkey = 'class_reminder' AND DATE(x.created_at) = CURDATE())", [$gymId, $from, $to]);
        foreach ($rows as $r) Messenger::template($gymId, 'class_reminder', $r, ['class' => $r['class_name'], 'time' => date('g:i A', strtotime($r['start_time']))]);
        return count($rows);
    }

    /** Bookings for classes that ended over an hour ago with no check-in become no-shows (fee applies if set). */
    public static function markNoShows(int $gymId): int
    {
        $rows = DB::all("SELECT b.*, c.no_show_fee, c.name AS class_name, c.start_time, c.duration_min FROM class_bookings b JOIN classes c ON c.id = b.class_id
            WHERE b.gym_id = ? AND b.status = 'booked' AND TIMESTAMP(b.class_date, c.start_time) + INTERVAL (c.duration_min + 60) MINUTE < NOW()", [$gymId]);
        $n = 0;
        foreach ($rows as $b) {
            $attended = DB::val('SELECT id FROM attendance_sessions WHERE gym_id = ? AND member_id = ? AND DATE(in_at) = ?', [$gymId, $b['member_id'], $b['class_date']]);
            DB::update('class_bookings', ['status' => $attended ? 'attended' : 'no_show'], ['id' => $b['id']]);
            if (!$attended && (float)$b['no_show_fee'] > 0) {
                Billing::createInvoice($gymId, [['description' => "No-show fee: {$b['class_name']} on " . date('d M', strtotime($b['class_date'])), 'rate' => (float)$b['no_show_fee'], 'gst_rate' => 18, 'ref_type' => 'no_show']], ['member_id' => (int)$b['member_id'], 'kind' => 'other']);
            }
            if (!$attended) $n++;
        }
        return $n;
    }

    /** AI receptionist: one automatic WhatsApp follow-up for new leads nobody has contacted in 24h. */
    public static function leadFollowups(int $gymId): int
    {
        $rows = DB::all("SELECT l.* FROM leads l WHERE l.gym_id = ? AND l.stage = 'new' AND l.created_at < NOW() - INTERVAL 1 DAY AND l.created_at > NOW() - INTERVAL 3 DAY
            AND NOT EXISTS (SELECT 1 FROM messages x WHERE x.lead_id = l.id AND x.tkey = 'lead_followup')", [$gymId]);
        foreach ($rows as $l) {
            Messenger::template($gymId, 'lead_followup', null, ['name' => strtok($l['name'], ' ')], ['phone' => $l['phone'], 'lead_id' => (int)$l['id']]);
            DB::insert('lead_activities', ['lead_id' => $l['id'], 'kind' => 'auto_followup', 'note' => 'Automatic WhatsApp follow-up sent']);
        }
        $due = (int)DB::val("SELECT COUNT(*) FROM leads WHERE gym_id = ? AND stage IN ('new','trial','follow_up') AND next_followup_at < NOW()", [$gymId]);
        if ($due > 0) Notify::staff($gymId, "$due lead follow-ups are overdue", null, 'leads', 'warning');
        return count($rows);
    }

    public static function ownerAlerts(int $gymId): int
    {
        $n = 0;
        $low = DB::all('SELECT name, stock FROM products WHERE gym_id = ? AND is_active = 1 AND track_stock = 1 AND stock <= reorder_level', [$gymId]);
        if ($low) { Notify::staff($gymId, count($low) . ' products are low on stock', implode(', ', array_column($low, 'name')), 'store', 'warning'); $n++; }
        $risk = (int)DB::val('SELECT COUNT(*) FROM members WHERE gym_id = ? AND churn_score >= 60 AND deleted_at IS NULL', [$gymId]);
        if ($risk) { Notify::staff($gymId, "$risk members at churn risk", 'Open AI insights to send a win-back.', 'insights', 'warning'); $n++; }
        $missed = (int)DB::val('SELECT COUNT(*) FROM attendance_sessions WHERE gym_id = ? AND auto_closed = 1 AND DATE(in_at) = CURDATE() - INTERVAL 1 DAY', [$gymId]);
        if ($missed) { Notify::staff($gymId, "$missed missed OUT punches yesterday", 'Sessions were auto-closed at closing time.', 'sessions'); $n++; }
        return $n;
    }
}
