<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, DB, HttpException, Util};

final class Memberships
{
    /**
     * Sell a new plan or renewal. Renewals start the day after the current plan of the same kind ends.
     * opt: start_date, discount, coupon, autopay, pay_amount, pay_mode, pay_reference, joining_fee (bool, default auto)
     * @return array{membership_id:int, invoice_id:int, payment_id:?int}
     */
    public static function sell(int $gymId, int $memberId, int $planId, array $opt = []): array
    {
        return DB::tx(function () use ($gymId, $memberId, $planId, $opt) {
            $member = DB::one('SELECT * FROM members WHERE id = ? AND gym_id = ? AND deleted_at IS NULL FOR UPDATE', [$memberId, $gymId]);
            if (!$member) throw HttpException::notFound('Member not found');
            $plan = DB::one('SELECT * FROM plans WHERE id = ? AND gym_id = ? AND is_active = 1', [$planId, $gymId]);
            if (!$plan) throw HttpException::bad('Choose a valid plan', ['plan_id' => 'Invalid plan']);

            $current = DB::one("SELECT * FROM memberships WHERE gym_id = ? AND member_id = ? AND kind = ? AND status IN ('active','frozen','upcoming') ORDER BY end_date DESC LIMIT 1",
                [$gymId, $memberId, $plan['kind']]);
            $start = $opt['start_date'] ?? null;
            if (!$start) $start = ($current && $current['end_date'] >= Util::today()) ? Util::addDays($current['end_date'], 1) : Util::today();
            $end = Util::addDays($start, (int)$plan['duration_days'] - 1);

            $first = (int)DB::val("SELECT COUNT(*) FROM memberships WHERE gym_id = ? AND member_id = ? AND kind = 'membership' AND status <> 'cancelled'", [$gymId, $memberId]) === 0;
            $items = [['description' => $plan['name'] . ' (' . date('d M Y', strtotime($start)) . ' – ' . date('d M Y', strtotime($end)) . ')',
                'rate' => (float)$plan['price'], 'gst_rate' => (float)$plan['gst_rate'], 'hsn_sac' => $plan['sac_code'], 'ref_type' => 'plan', 'ref_id' => $planId]];
            $joinFee = (float)Util::setting('rules.joining_fee', 0, $gymId);
            if ($plan['kind'] === 'membership' && $first && $joinFee > 0 && ($opt['joining_fee'] ?? true)) {
                $items[] = ['description' => 'Joining fee', 'rate' => $joinFee, 'gst_rate' => 18, 'hsn_sac' => '999723', 'ref_type' => 'joining_fee'];
            }
            $invoiceId = Billing::createInvoice($gymId, $items, [
                'member_id' => $memberId, 'kind' => $plan['kind'] === 'pt' ? 'pt' : 'membership',
                'discount' => $opt['discount'] ?? 0, 'coupon' => $opt['coupon'] ?? null, 'date' => $opt['invoice_date'] ?? null,
            ]);
            $status = $start > Util::today() ? 'upcoming' : ($end < Util::today() ? 'expired' : 'active');
            $msId = DB::insert('memberships', [
                'gym_id' => $gymId, 'member_id' => $memberId, 'plan_id' => $planId, 'kind' => $plan['kind'],
                'start_date' => $start, 'end_date' => $end, 'price' => $plan['price'], 'status' => $status,
                'sessions_total' => (int)$plan['sessions_count'], 'autopay' => !empty($opt['autopay']) ? 1 : 0,
                'invoice_id' => $invoiceId, 'created_by' => Auth::staffId(),
            ]);

            if ($first && $plan['kind'] === 'membership' && $member['referred_by']) {
                $credit = (float)Util::setting('rules.referral_credit', 0, $gymId);
                if ($credit > 0) {
                    foreach ([(int)$member['referred_by'], $memberId] as $mid) {
                        DB::run('UPDATE members SET credit_balance = credit_balance + ? WHERE id = ? AND gym_id = ?', [$credit, $mid, $gymId]);
                        DB::insert('member_credits', ['gym_id' => $gymId, 'member_id' => $mid, 'amount' => $credit, 'reason' => 'Referral: ' . $member['name']]);
                    }
                }
            }

            $paymentId = null;
            if (!empty($opt['pay_amount']) && (float)$opt['pay_amount'] > 0) {
                $paymentId = Billing::recordPayment($gymId, $invoiceId, (float)$opt['pay_amount'], $opt['pay_mode'] ?? 'cash', $opt['pay_reference'] ?? null);
            }
            Access::reevaluate($gymId, $memberId);
            Util::audit('membership.sell', 'membership', $msId, ['plan' => $plan['name'], 'invoice_id' => $invoiceId]);
            return ['membership_id' => $msId, 'invoice_id' => $invoiceId, 'payment_id' => $paymentId];
        });
    }

    private static function load(int $gymId, int $id): array
    {
        $ms = DB::one('SELECT ms.*, p.freeze_days_allowed, p.name AS plan_name, p.duration_days FROM memberships ms JOIN plans p ON p.id = ms.plan_id WHERE ms.id = ? AND ms.gym_id = ?', [$id, $gymId]);
        if (!$ms) throw HttpException::notFound('Membership not found');
        return $ms;
    }

    public static function freeze(int $gymId, int $id, string $from, int $days): array
    {
        return DB::tx(function () use ($gymId, $id, $from, $days) {
            $ms = self::load($gymId, $id);
            if (!in_array($ms['status'], ['active', 'upcoming'], true)) throw HttpException::bad('Only an active plan can be frozen');
            $minDays = (int)Util::setting('rules.freeze_min_days', 7, $gymId);
            if ($days < $minDays) throw HttpException::bad("Freeze must be at least $minDays days", ['days' => "At least $minDays days"]);
            $left = (int)$ms['freeze_days_allowed'] - (int)$ms['frozen_days_used'];
            if ($days > $left) throw HttpException::bad("This plan allows $left more freeze days", ['days' => "At most $left days"]);
            if ($from < Util::today()) throw HttpException::bad('Freeze cannot start in the past', ['from' => 'Must be today or later']);
            $to = Util::addDays($from, $days - 1);
            DB::update('memberships', [
                'freeze_start' => $from, 'freeze_end' => $to, 'frozen_days_used' => (int)$ms['frozen_days_used'] + $days,
                'end_date' => Util::addDays($ms['end_date'], $days),
                'status' => $from <= Util::today() ? 'frozen' : $ms['status'],
            ], ['id' => $id]);
            $fee = (float)Util::setting('rules.freeze_fee', 0, $gymId);
            $invoiceId = null;
            if ($fee > 0) {
                $invoiceId = Billing::createInvoice($gymId, [['description' => "Freeze fee ($days days)", 'rate' => $fee, 'gst_rate' => 18, 'ref_type' => 'freeze']], ['member_id' => (int)$ms['member_id'], 'kind' => 'other']);
            }
            Access::reevaluate($gymId, (int)$ms['member_id']);
            Util::audit('membership.freeze', 'membership', $id, ['from' => $from, 'days' => $days]);
            return ['freeze_end' => $to, 'invoice_id' => $invoiceId];
        });
    }

    public static function unfreeze(int $gymId, int $id): void
    {
        DB::tx(function () use ($gymId, $id) {
            $ms = self::load($gymId, $id);
            if ($ms['status'] !== 'frozen' && !$ms['freeze_end']) throw HttpException::bad('Plan is not frozen');
            // Give back unused freeze days.
            $unused = $ms['freeze_end'] >= Util::today() ? Util::daysBetween(max(Util::today(), $ms['freeze_start']), $ms['freeze_end']) + 1 : 0;
            if ($ms['freeze_start'] > Util::today()) $unused = Util::daysBetween($ms['freeze_start'], $ms['freeze_end']) + 1;
            DB::update('memberships', [
                'status' => 'active', 'freeze_start' => null, 'freeze_end' => null,
                'frozen_days_used' => max(0, (int)$ms['frozen_days_used'] - $unused),
                'end_date' => Util::addDays($ms['end_date'], -$unused),
            ], ['id' => $id]);
            Access::reevaluate($gymId, (int)$ms['member_id']);
            Util::audit('membership.unfreeze', 'membership', $id);
        });
    }

    public static function extend(int $gymId, int $id, int $days, string $reason): void
    {
        $ms = self::load($gymId, $id);
        DB::update('memberships', ['end_date' => Util::addDays($ms['end_date'], $days), 'status' => $ms['status'] === 'expired' ? 'active' : $ms['status']], ['id' => $id]);
        Access::reevaluate($gymId, (int)$ms['member_id']);
        Util::audit('membership.extend', 'membership', $id, ['days' => $days, 'reason' => $reason]);
    }

    /** Upgrade: credit the unused value of the current plan against the new plan. */
    public static function upgrade(int $gymId, int $id, int $newPlanId, array $pay = []): array
    {
        return DB::tx(function () use ($gymId, $id, $newPlanId, $pay) {
            $ms = self::load($gymId, $id);
            if (!in_array($ms['status'], ['active', 'frozen'], true)) throw HttpException::bad('Only an active plan can be upgraded');
            $plan = DB::one('SELECT * FROM plans WHERE id = ? AND gym_id = ? AND is_active = 1', [$newPlanId, $gymId]);
            if (!$plan || $plan['kind'] !== $ms['kind']) throw HttpException::bad('Choose a valid plan of the same type');
            $totalDays = max(1, Util::daysBetween($ms['start_date'], $ms['end_date']) + 1);
            $remaining = max(0, Util::daysBetween(Util::today(), $ms['end_date']) + 1);
            $credit = Util::money((float)$ms['price'] * $remaining / $totalDays);
            $due = Util::money(max(0, (float)$plan['price'] - $credit));
            $start = Util::today();
            $end = Util::addDays($start, (int)$plan['duration_days'] - 1);
            $invoiceId = Billing::createInvoice($gymId, [[
                'description' => "Upgrade to {$plan['name']} (credit ₹$credit from {$ms['plan_name']})", 'rate' => $due,
                'gst_rate' => (float)$plan['gst_rate'], 'hsn_sac' => $plan['sac_code'], 'ref_type' => 'plan', 'ref_id' => $newPlanId,
            ]], ['member_id' => (int)$ms['member_id'], 'kind' => 'membership']);
            DB::update('memberships', ['status' => 'cancelled', 'end_date' => Util::addDays($start, -1), 'notes' => 'Upgraded'], ['id' => $id]);
            $newId = DB::insert('memberships', [
                'gym_id' => $gymId, 'member_id' => $ms['member_id'], 'plan_id' => $newPlanId, 'kind' => $plan['kind'],
                'start_date' => $start, 'end_date' => $end, 'price' => $plan['price'], 'status' => 'active',
                'sessions_total' => (int)$plan['sessions_count'], 'invoice_id' => $invoiceId, 'created_by' => Auth::staffId(), 'notes' => 'Upgrade from #' . $id,
            ]);
            if (!empty($pay['amount'])) Billing::recordPayment($gymId, $invoiceId, (float)$pay['amount'], $pay['mode'] ?? 'cash', $pay['reference'] ?? null);
            Access::reevaluate($gymId, (int)$ms['member_id']);
            Util::audit('membership.upgrade', 'membership', $newId, ['from' => $id, 'credit' => $credit]);
            return ['membership_id' => $newId, 'invoice_id' => $invoiceId, 'credit' => $credit, 'due' => $due];
        });
    }

    public static function transfer(int $gymId, int $id, int $toMemberId): array
    {
        return DB::tx(function () use ($gymId, $id, $toMemberId) {
            $ms = self::load($gymId, $id);
            if (!in_array($ms['status'], ['active', 'upcoming'], true)) throw HttpException::bad('Only an active plan can be transferred');
            if ((int)$ms['member_id'] === $toMemberId) throw HttpException::bad('Choose a different member');
            $to = DB::one('SELECT id FROM members WHERE id = ? AND gym_id = ? AND deleted_at IS NULL', [$toMemberId, $gymId]);
            if (!$to) throw HttpException::notFound('Member not found');
            $start = max(Util::today(), $ms['start_date']);
            DB::update('memberships', ['status' => 'transferred', 'end_date' => Util::addDays($start, -1), 'notes' => 'Transferred to member #' . $toMemberId], ['id' => $id]);
            $newId = DB::insert('memberships', [
                'gym_id' => $gymId, 'member_id' => $toMemberId, 'plan_id' => $ms['plan_id'], 'kind' => $ms['kind'],
                'start_date' => $start, 'end_date' => $ms['end_date'], 'price' => 0, 'status' => 'active',
                'sessions_total' => (int)$ms['sessions_total'] - (int)$ms['sessions_used'], 'created_by' => Auth::staffId(), 'notes' => 'Transfer from #' . $id,
            ]);
            $fee = (float)Util::setting('rules.transfer_fee', 0, $gymId);
            $invoiceId = $fee > 0 ? Billing::createInvoice($gymId, [['description' => 'Membership transfer fee', 'rate' => $fee, 'gst_rate' => 18, 'ref_type' => 'transfer']], ['member_id' => $toMemberId, 'kind' => 'other']) : null;
            Access::reevaluate($gymId, (int)$ms['member_id']);
            Access::reevaluate($gymId, $toMemberId);
            Util::audit('membership.transfer', 'membership', $newId, ['from_membership' => $id]);
            return ['membership_id' => $newId, 'invoice_id' => $invoiceId];
        });
    }

    /** Daily status maintenance; returns counts. */
    public static function maintain(int $gymId): array
    {
        $today = Util::today();
        $n = [];
        $n['activated'] = DB::run("UPDATE memberships SET status = 'active' WHERE gym_id = ? AND status = 'upcoming' AND start_date <= ?", [$gymId, $today])->rowCount();
        $n['frozen'] = DB::run("UPDATE memberships SET status = 'frozen' WHERE gym_id = ? AND status = 'active' AND freeze_start IS NOT NULL AND freeze_start <= ? AND freeze_end >= ?", [$gymId, $today, $today])->rowCount();
        $n['unfrozen'] = DB::run("UPDATE memberships SET status = 'active', freeze_start = NULL, freeze_end = NULL WHERE gym_id = ? AND status = 'frozen' AND freeze_end < ?", [$gymId, $today])->rowCount();
        $n['expired'] = DB::run("UPDATE memberships SET status = 'expired' WHERE gym_id = ? AND status IN ('active') AND end_date < ?", [$gymId, $today])->rowCount();
        $n['pt_used_up'] = DB::run("UPDATE memberships SET status = 'expired' WHERE gym_id = ? AND kind = 'pt' AND status = 'active' AND sessions_total > 0 AND sessions_used >= sessions_total", [$gymId])->rowCount();
        foreach (DB::all('SELECT id FROM members WHERE gym_id = ? AND deleted_at IS NULL', [$gymId]) as $m) {
            Access::reevaluate($gymId, (int)$m['id']);
        }
        return $n;
    }

    /** SQL fragment: the member's current membership-plan status, used in lists. */
    public static function statusFor(?array $ms, int $graceDays): string
    {
        if (!$ms) return 'no_plan';
        if ($ms['status'] === 'frozen') return 'frozen';
        $today = Util::today();
        if ($ms['status'] === 'upcoming') return 'upcoming';
        if ($ms['end_date'] >= $today) return 'active';
        if (Util::addDays($ms['end_date'], $graceDays) >= $today) return 'grace';
        return 'expired';
    }
}
