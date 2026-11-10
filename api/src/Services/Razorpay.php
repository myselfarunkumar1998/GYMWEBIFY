<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Config, DB, HttpException, Util};

/**
 * Razorpay: payment links (WhatsApp collection), Orders + Checkout (member app),
 * Subscriptions (UPI AutoPay renewals) and signed webhooks.
 */
final class Razorpay
{
    private const API = 'https://api.razorpay.com/v1';

    public static function configured(int $gymId): bool
    {
        return (bool)Util::setting('integrations.razorpay_key_id', null, $gymId) && (bool)Util::secret('razorpay_key_secret', $gymId);
    }

    private static function auth(int $gymId): array
    {
        if (!self::configured($gymId)) throw HttpException::bad('Online payments are not set up. Add Razorpay keys in Settings › Integrations.');
        return ['Authorization: Basic ' . base64_encode(Util::setting('integrations.razorpay_key_id', null, $gymId) . ':' . Util::secret('razorpay_key_secret', $gymId))];
    }

    private static function call(int $gymId, string $method, string $path, ?array $data = null): array
    {
        [$code, $res] = Http::json($method, self::API . $path, $data, self::auth($gymId));
        if ($code < 200 || $code >= 300) throw new HttpException(502, 'Payment gateway error: ' . ($res['error']['description'] ?? "HTTP $code"));
        return $res;
    }

    public static function paymentLink(int $gymId, int $invoiceId): array
    {
        $inv = DB::one('SELECT i.*, m.name, m.phone, m.email FROM invoices i LEFT JOIN members m ON m.id = i.member_id WHERE i.id = ? AND i.gym_id = ?', [$invoiceId, $gymId]);
        if (!$inv) throw HttpException::notFound('Invoice not found');
        if ((float)$inv['balance'] <= 0) throw HttpException::bad('Nothing is due on this invoice');
        $existing = DB::one("SELECT * FROM payment_links WHERE invoice_id = ? AND status = 'created' AND amount = ? ORDER BY id DESC LIMIT 1", [$invoiceId, $inv['balance']]);
        if ($existing && $existing['short_url']) return $existing;

        if (!self::configured($gymId) && Config::get('demo_mode')) {
            $url = rtrim((string)Config::get('app_url'), '/') . '/pay/demo/' . $invoiceId;
            $id = DB::insert('payment_links', ['gym_id' => $gymId, 'invoice_id' => $invoiceId, 'gateway_link_id' => 'demo_' . $invoiceId . '_' . time(), 'short_url' => $url, 'amount' => $inv['balance']]);
            return DB::one('SELECT * FROM payment_links WHERE id = ?', [$id]);
        }
        $res = self::call($gymId, 'POST', '/payment_links', [
            'amount' => (int)round((float)$inv['balance'] * 100), 'currency' => 'INR', 'accept_partial' => false,
            'reference_id' => 'inv_' . $invoiceId . '_' . time(), 'description' => 'Invoice ' . $inv['invoice_no'],
            'customer' => array_filter(['name' => $inv['name'] ?? $inv['customer_name'], 'contact' => $inv['phone'] ? '+91' . $inv['phone'] : null, 'email' => $inv['email'] ?? null]),
            'notify' => ['sms' => false, 'email' => false], 'reminder_enable' => false,
            'notes' => ['gym_id' => (string)$gymId, 'invoice_id' => (string)$invoiceId],
            'expire_by' => time() + 7 * 86400,
        ]);
        $id = DB::insert('payment_links', ['gym_id' => $gymId, 'invoice_id' => $invoiceId, 'gateway_link_id' => $res['id'], 'short_url' => $res['short_url'], 'amount' => $inv['balance']]);
        return DB::one('SELECT * FROM payment_links WHERE id = ?', [$id]);
    }

    /** Create a Checkout order for the member app. */
    public static function order(int $gymId, int $invoiceId): array
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id = ? AND gym_id = ?', [$invoiceId, $gymId]);
        if (!$inv || (float)$inv['balance'] <= 0) throw HttpException::bad('Nothing is due on this invoice');
        if (!self::configured($gymId)) {
            if (Config::get('demo_mode')) return ['demo' => true, 'invoice_id' => $invoiceId, 'amount' => (float)$inv['balance']];
            throw HttpException::bad('Online payments are not set up at this gym yet. Please pay at the front desk.');
        }
        $res = self::call($gymId, 'POST', '/orders', ['amount' => (int)round((float)$inv['balance'] * 100), 'currency' => 'INR', 'receipt' => 'inv_' . $invoiceId,
            'notes' => ['gym_id' => (string)$gymId, 'invoice_id' => (string)$invoiceId]]);
        return ['order_id' => $res['id'], 'amount' => $res['amount'], 'key_id' => Util::setting('integrations.razorpay_key_id', null, $gymId), 'invoice_id' => $invoiceId];
    }

    /** Verify Checkout's handler response, then record the payment. */
    public static function verifyCheckout(int $gymId, int $invoiceId, string $orderId, string $paymentId, string $signature): void
    {
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, (string)Util::secret('razorpay_key_secret', $gymId));
        if (!hash_equals($expected, $signature)) throw HttpException::bad('Payment could not be verified');
        $order = self::call($gymId, 'GET', '/orders/' . rawurlencode($orderId));
        if (($order['notes']['invoice_id'] ?? null) !== (string)$invoiceId) throw HttpException::bad('Payment does not match this invoice');
        Billing::recordPayment($gymId, $invoiceId, $order['amount_paid'] / 100, 'online', $orderId, $paymentId);
    }

    /** UPI AutoPay via Razorpay Subscriptions. */
    public static function subscribe(int $gymId, int $membershipId): array
    {
        $ms = DB::one('SELECT ms.*, p.name, p.price, p.duration_days, m.name AS member_name, m.phone FROM memberships ms JOIN plans p ON p.id = ms.plan_id JOIN members m ON m.id = ms.member_id WHERE ms.id = ? AND ms.gym_id = ?', [$membershipId, $gymId]);
        if (!$ms) throw HttpException::notFound('Membership not found');
        if (!self::configured($gymId)) {
            if (Config::get('demo_mode')) { DB::update('memberships', ['autopay' => 1], ['id' => $membershipId]); return ['demo' => true]; }
            throw HttpException::bad('AutoPay needs Razorpay keys in Settings › Integrations.');
        }
        $months = max(1, (int)round($ms['duration_days'] / 30));
        $gst = 1 + 0.18;
        $plan = self::call($gymId, 'POST', '/plans', ['period' => 'monthly', 'interval' => $months,
            'item' => ['name' => $ms['name'], 'amount' => (int)round((float)$ms['price'] * $gst * 100), 'currency' => 'INR']]);
        $sub = self::call($gymId, 'POST', '/subscriptions', ['plan_id' => $plan['id'], 'total_count' => 24, 'customer_notify' => 1,
            'start_at' => max(time() + 600, strtotime($ms['end_date'] . ' 06:00:00')),
            'notes' => ['gym_id' => (string)$gymId, 'membership_id' => (string)$membershipId]]);
        DB::update('memberships', ['razorpay_subscription_id' => $sub['id']], ['id' => $membershipId]);
        return ['short_url' => $sub['short_url'] ?? null, 'subscription_id' => $sub['id']];
    }

    /** Webhook: verify X-Razorpay-Signature with the gym's webhook secret. */
    public static function webhook(int $gymId, string $raw, ?string $signature): string
    {
        $secret = Util::secret('razorpay_webhook_secret', $gymId);
        if (!$secret || !$signature || !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) throw HttpException::forbidden('Bad signature');
        $e = json_decode($raw, true);
        $event = $e['event'] ?? '';
        if ($event === 'payment_link.paid') {
            $link = $e['payload']['payment_link']['entity'] ?? [];
            $pay = $e['payload']['payment']['entity'] ?? [];
            $row = DB::one('SELECT * FROM payment_links WHERE gateway_link_id = ? AND gym_id = ?', [$link['id'] ?? '', $gymId]);
            if ($row) {
                DB::update('payment_links', ['status' => 'paid'], ['id' => $row['id']]);
                Billing::recordPayment($gymId, (int)$row['invoice_id'], ($pay['amount'] ?? 0) / 100, 'online', $link['id'], $pay['id'] ?? null);
            }
        } elseif ($event === 'subscription.charged') {
            $sub = $e['payload']['subscription']['entity'] ?? [];
            $pay = $e['payload']['payment']['entity'] ?? [];
            $ms = DB::one("SELECT * FROM memberships WHERE razorpay_subscription_id = ? AND gym_id = ? ORDER BY id DESC LIMIT 1", [$sub['id'] ?? '', $gymId]);
            if ($ms && !DB::val('SELECT id FROM payments WHERE gateway_payment_id = ?', [$pay['id'] ?? ''])) {
                $r = Memberships::sell($gymId, (int)$ms['member_id'], (int)$ms['plan_id'], ['autopay' => true, 'joining_fee' => false]);
                DB::update('memberships', ['razorpay_subscription_id' => $sub['id']], ['id' => $r['membership_id']]);
                $inv = DB::one('SELECT total FROM invoices WHERE id = ?', [$r['invoice_id']]);
                Billing::recordPayment($gymId, $r['invoice_id'], min((float)$inv['total'], ($pay['amount'] ?? 0) / 100), 'autopay', $sub['id'], $pay['id'] ?? null);
            }
        } elseif ($event === 'subscription.cancelled' || $event === 'subscription.halted') {
            $sub = $e['payload']['subscription']['entity'] ?? [];
            DB::run('UPDATE memberships SET autopay = 0 WHERE razorpay_subscription_id = ? AND gym_id = ?', [$sub['id'] ?? '', $gymId]);
        }
        return $event;
    }
}
