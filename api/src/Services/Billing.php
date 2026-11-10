<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, DB, HttpException, Util};

/**
 * GST invoicing. Prices are GST-exclusive unless the gym turns on prices_include_gst.
 * Intra-state supply splits tax into CGST + SGST; inter-state uses IGST.
 */
final class Billing
{
    public static function financialYear(string $date): string
    {
        $y = (int)substr($date, 0, 4);
        $m = (int)substr($date, 5, 2);
        $start = $m >= 4 ? $y : $y - 1;
        return $start . '-' . substr((string)($start + 1), 2);
    }

    private static function nextNumber(int $gymId, string $prefix, string $date, string $table, string $col): string
    {
        DB::one('SELECT id FROM gyms WHERE id = ? FOR UPDATE', [$gymId]);
        $fy = self::financialYear($date);
        $base = $prefix . '/' . $fy . '/';
        $last = DB::val("SELECT MAX(CAST(SUBSTRING($col, ?) AS UNSIGNED)) FROM $table WHERE gym_id = ? AND $col LIKE ?", [strlen($base) + 1, $gymId, $base . '%']);
        return $base . str_pad((string)(((int)$last) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array $items each: description, rate, qty?, gst_rate?, hsn_sac?, ref_type?, ref_id?
     * @param array $opt member_id, branch_id, kind, discount, coupon, customer_name, customer_gstin, place_of_supply, due_date, notes, date
     */
    public static function createInvoice(int $gymId, array $items, array $opt = []): int
    {
        if (!$items) throw HttpException::bad('Invoice needs at least one item');
        return DB::tx(function () use ($gymId, $items, $opt) {
            $gym = Util::gym($gymId);
            $inclusive = (bool)($gym['settings']['prices_include_gst'] ?? false);
            $date = $opt['date'] ?? Util::today();
            $member = !empty($opt['member_id']) ? DB::one('SELECT * FROM members WHERE id = ? AND gym_id = ?', [$opt['member_id'], $gymId]) : null;
            if (!empty($opt['member_id']) && !$member) throw HttpException::notFound('Member not found');
            $pos = $opt['place_of_supply'] ?? ($member['state_code'] ?? null) ?? $gym['state_code'];
            $intra = $pos === $gym['state_code'];
            $gstRegistered = !empty($gym['gstin']);

            $lines = [];
            $subtotal = 0.0;
            foreach ($items as $it) {
                $qty = (float)($it['qty'] ?? 1);
                $rate = (float)$it['rate'];
                $gstRate = $gstRegistered ? (float)($it['gst_rate'] ?? 18) : 0.0;
                if ($inclusive && $gstRate > 0) $rate = $rate / (1 + $gstRate / 100);
                $amount = Util::money($rate * $qty);
                $subtotal += $amount;
                $lines[] = ['description' => mb_substr((string)$it['description'], 0, 200), 'hsn_sac' => $it['hsn_sac'] ?? '999723', 'qty' => $qty,
                    'rate' => Util::money($rate), 'amount' => $amount, 'gst_rate' => $gstRate, 'ref_type' => $it['ref_type'] ?? null, 'ref_id' => $it['ref_id'] ?? null];
            }
            $subtotal = Util::money($subtotal);

            $discount = (float)($opt['discount'] ?? 0);
            $couponCode = null;
            if (!empty($opt['coupon'])) {
                [$cd, $couponCode] = self::applyCoupon($gymId, (string)$opt['coupon'], $subtotal - $discount);
                $discount += $cd;
            }
            $discount = Util::money(min(max($discount, 0), $subtotal));

            // Spread the discount across lines by value, then tax each line at its own rate.
            $cgst = $sgst = $igst = 0.0;
            foreach ($lines as $l) {
                $share = $subtotal > 0 ? $l['amount'] / $subtotal : 0;
                $taxableLine = $l['amount'] - $discount * $share;
                $tax = $taxableLine * $l['gst_rate'] / 100;
                if ($intra) { $cgst += $tax / 2; $sgst += $tax / 2; } else { $igst += $tax; }
            }
            $taxable = Util::money($subtotal - $discount);
            $cgst = Util::money($cgst); $sgst = Util::money($sgst); $igst = Util::money($igst);
            $total = Util::money($taxable + $cgst + $sgst + $igst);

            $no = self::nextNumber($gymId, $gym['settings']['invoice_prefix'] ?? 'INV', $date, 'invoices', 'invoice_no');
            $id = DB::insert('invoices', [
                'gym_id' => $gymId,
                'branch_id' => $opt['branch_id'] ?? ($member['branch_id'] ?? null),
                'invoice_no' => $no,
                'member_id' => $member['id'] ?? null,
                'customer_name' => $opt['customer_name'] ?? ($member['name'] ?? null),
                'customer_gstin' => $opt['customer_gstin'] ?? null,
                'place_of_supply' => $pos,
                'invoice_date' => $date,
                'due_date' => $opt['due_date'] ?? $date,
                'kind' => $opt['kind'] ?? 'membership',
                'subtotal' => $subtotal, 'discount' => $discount, 'taxable' => $taxable,
                'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'total' => $total,
                'paid' => 0, 'balance' => $total, 'status' => $total > 0 ? 'unpaid' : 'paid',
                'coupon_code' => $couponCode,
                'notes' => $opt['notes'] ?? null,
                'created_by' => Auth::staffId(),
            ]);
            foreach ($lines as $l) DB::insert('invoice_items', $l + ['invoice_id' => $id]);
            if ($couponCode) DB::run('UPDATE coupons SET used_count = used_count + 1 WHERE gym_id = ? AND code = ?', [$gymId, $couponCode]);
            return $id;
        });
    }

    /** @return array{0: float, 1: string} discount amount and normalised code */
    public static function applyCoupon(int $gymId, string $code, float $base): array
    {
        $c = DB::one('SELECT * FROM coupons WHERE gym_id = ? AND code = ? AND is_active = 1', [$gymId, strtoupper(trim($code))]);
        $today = Util::today();
        if (!$c || ($c['valid_from'] && $c['valid_from'] > $today) || ($c['valid_to'] && $c['valid_to'] < $today) || ($c['max_uses'] !== null && $c['used_count'] >= $c['max_uses'])) {
            throw HttpException::bad('This coupon is not valid', ['coupon' => 'Invalid or expired coupon']);
        }
        $d = $c['kind'] === 'percent' ? $base * (float)$c['value'] / 100 : (float)$c['value'];
        return [Util::money(min($d, $base)), $c['code']];
    }

    public static function recordPayment(int $gymId, int $invoiceId, float $amount, string $mode, ?string $reference = null, ?string $gatewayId = null): int
    {
        return DB::tx(function () use ($gymId, $invoiceId, $amount, $mode, $reference, $gatewayId) {
            $inv = DB::one('SELECT * FROM invoices WHERE id = ? AND gym_id = ? FOR UPDATE', [$invoiceId, $gymId]);
            if (!$inv) throw HttpException::notFound('Invoice not found');
            if ($inv['status'] === 'cancelled') throw HttpException::bad('This invoice is cancelled');
            if ($gatewayId && DB::val('SELECT id FROM payments WHERE gateway_payment_id = ?', [$gatewayId])) return 0; // idempotent webhook
            $amount = Util::money($amount);
            if ($amount <= 0) throw HttpException::bad('Amount must be more than zero', ['amount' => 'Must be more than zero']);
            if ($amount > (float)$inv['balance'] + 0.009) throw HttpException::bad('Amount is more than the balance due (₹' . $inv['balance'] . ')', ['amount' => 'More than balance']);

            if ($mode === 'credit') {
                $m = DB::one('SELECT credit_balance FROM members WHERE id = ? FOR UPDATE', [$inv['member_id']]);
                if (!$m || (float)$m['credit_balance'] < $amount) throw HttpException::bad('Not enough referral credit');
                DB::run('UPDATE members SET credit_balance = credit_balance - ? WHERE id = ?', [$amount, $inv['member_id']]);
                DB::insert('member_credits', ['gym_id' => $gymId, 'member_id' => $inv['member_id'], 'amount' => -$amount, 'reason' => 'Used on ' . $inv['invoice_no']]);
            }
            if ($mode === 'giftcard') {
                $gc = DB::one('SELECT * FROM gift_cards WHERE gym_id = ? AND code = ? FOR UPDATE', [$gymId, strtoupper((string)$reference)]);
                if (!$gc || (float)$gc['balance'] < $amount || ($gc['valid_to'] && $gc['valid_to'] < Util::today())) throw HttpException::bad('Gift card is not valid or has too little balance');
                DB::run('UPDATE gift_cards SET balance = balance - ? WHERE id = ?', [$amount, $gc['id']]);
            }

            $pid = DB::insert('payments', [
                'gym_id' => $gymId, 'invoice_id' => $invoiceId, 'member_id' => $inv['member_id'], 'amount' => $amount, 'mode' => $mode,
                'reference' => $reference ? mb_substr($reference, 0, 100) : null, 'gateway_payment_id' => $gatewayId, 'received_by' => Auth::staffId(),
            ]);
            $paid = Util::money((float)$inv['paid'] + $amount);
            $balance = Util::money((float)$inv['total'] - $paid);
            DB::update('invoices', ['paid' => $paid, 'balance' => $balance, 'status' => $balance <= 0.009 ? 'paid' : 'partial'], ['id' => $invoiceId]);

            if ($inv['member_id']) {
                Access::reevaluate($gymId, (int)$inv['member_id']);
                $m = DB::one('SELECT * FROM members WHERE id = ?', [$inv['member_id']]);
                Messenger::template($gymId, 'payment_receipt', $m, ['amount' => number_format($amount, 2), 'invoice' => $inv['invoice_no'], 'balance' => number_format($balance, 2)]);
            }
            Webhooks::emit($gymId, 'payment.received', ['invoice_no' => $inv['invoice_no'], 'amount' => $amount, 'mode' => $mode, 'member_id' => $inv['member_id']]);
            return $pid;
        });
    }

    public static function cancel(int $gymId, int $invoiceId, string $reason): array
    {
        return DB::tx(function () use ($gymId, $invoiceId, $reason) {
            $inv = DB::one('SELECT * FROM invoices WHERE id = ? AND gym_id = ? FOR UPDATE', [$invoiceId, $gymId]);
            if (!$inv) throw HttpException::notFound('Invoice not found');
            if ($inv['status'] === 'cancelled') throw HttpException::bad('Already cancelled');
            $noteNo = self::nextNumber($gymId, 'CN', Util::today(), 'credit_notes', 'note_no');
            DB::insert('credit_notes', ['gym_id' => $gymId, 'note_no' => $noteNo, 'invoice_id' => $invoiceId, 'amount' => $inv['total'], 'reason' => mb_substr($reason, 0, 200), 'created_by' => Auth::staffId()]);
            DB::update('invoices', ['status' => 'cancelled', 'balance' => 0], ['id' => $invoiceId]);
            DB::run("UPDATE memberships SET status = 'cancelled' WHERE invoice_id = ? AND gym_id = ?", [$invoiceId, $gymId]);
            // Return stock for store items.
            foreach (DB::all("SELECT * FROM invoice_items WHERE invoice_id = ? AND ref_type = 'product'", [$invoiceId]) as $it) {
                DB::run('UPDATE products SET stock = stock + ? WHERE id = ? AND gym_id = ?', [(int)$it['qty'], $it['ref_id'], $gymId]);
                DB::insert('stock_moves', ['gym_id' => $gymId, 'product_id' => $it['ref_id'], 'qty' => (int)$it['qty'], 'kind' => 'return', 'ref' => $noteNo, 'created_by' => Auth::staffId()]);
            }
            if ($inv['member_id']) Access::reevaluate($gymId, (int)$inv['member_id']);
            return ['credit_note' => $noteNo, 'refund_due' => (float)$inv['paid']];
        });
    }

    public static function invoiceFull(int $gymId, int $id): array
    {
        $inv = DB::one('SELECT i.*, m.member_code, m.phone, m.address AS member_address, b.name AS branch_name, b.address AS branch_address
            FROM invoices i LEFT JOIN members m ON m.id = i.member_id LEFT JOIN branches b ON b.id = i.branch_id WHERE i.id = ? AND i.gym_id = ?', [$id, $gymId]);
        if (!$inv) throw HttpException::notFound('Invoice not found');
        $inv['items'] = DB::all('SELECT description, hsn_sac, qty, rate, amount, gst_rate FROM invoice_items WHERE invoice_id = ?', [$id]);
        $inv['payments'] = DB::all('SELECT p.amount, p.mode, p.reference, p.paid_at, u.name AS received_by FROM payments p LEFT JOIN users u ON u.id = p.received_by WHERE p.invoice_id = ? ORDER BY p.id', [$id]);
        $inv['credit_notes'] = DB::all('SELECT note_no, amount, reason, created_at FROM credit_notes WHERE invoice_id = ?', [$id]);
        return $inv;
    }

    /** Printable HTML invoice (members save it as PDF from the browser). */
    public static function invoiceHtml(int $gymId, int $id): string
    {
        $inv = self::invoiceFull($gymId, $id);
        $g = Util::gym($gymId);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($inv['items'] as $it) {
            $rows .= '<tr><td>' . $e($it['description']) . '</td><td>' . $e($it['hsn_sac']) . '</td><td class="r">' . $e(rtrim(rtrim($it['qty'], '0'), '.')) . '</td><td class="r">' . number_format((float)$it['rate'], 2) . '</td><td class="r">' . $e($it['gst_rate']) . '%</td><td class="r">' . number_format((float)$it['amount'], 2) . '</td></tr>';
        }
        $tax = (float)$inv['igst'] > 0
            ? '<tr><td>IGST</td><td class="r">' . number_format((float)$inv['igst'], 2) . '</td></tr>'
            : '<tr><td>CGST</td><td class="r">' . number_format((float)$inv['cgst'], 2) . '</td></tr><tr><td>SGST</td><td class="r">' . number_format((float)$inv['sgst'], 2) . '</td></tr>';
        $title = $g['gstin'] ? 'Tax Invoice' : 'Invoice';
        $status = strtoupper($inv['status']);
        return '<!doctype html><html><head><meta charset="utf-8"><title>' . $e($inv['invoice_no']) . '</title><style>
body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:32px;font-size:13px}h1{font-size:20px;margin:0}table{width:100%;border-collapse:collapse;margin-top:16px}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}.r{text-align:right}.grid{display:flex;justify-content:space-between;gap:24px}.tot{width:300px;margin-left:auto}
.badge{display:inline-block;padding:2px 8px;border:1px solid #111;border-radius:4px;font-size:11px}@media print{button{display:none}}</style></head><body>
<button onclick="window.print()" style="float:right;padding:8px 16px">Print / Save PDF</button>
<div class="grid"><div><h1>' . $e($g['legal_name'] ?: $g['name']) . '</h1><div>' . $e($inv['branch_address'] ?: $g['address']) . '</div>' . ($g['gstin'] ? '<div>GSTIN: ' . $e($g['gstin']) . '</div>' : '') . '<div>' . $e($g['phone']) . '</div></div>
<div class="r"><h1>' . $title . '</h1><div>No: ' . $e($inv['invoice_no']) . '</div><div>Date: ' . $e(date('d M Y', strtotime($inv['invoice_date']))) . '</div><div>Place of supply: ' . $e($inv['place_of_supply']) . '</div><div class="badge">' . $status . '</div></div></div>
<p><b>Bill to:</b> ' . $e($inv['customer_name']) . ($inv['member_code'] ? ' (' . $e($inv['member_code']) . ')' : '') . ($inv['customer_gstin'] ? ' · GSTIN ' . $e($inv['customer_gstin']) : '') . '</p>
<table><thead><tr><th>Item</th><th>SAC/HSN</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">GST</th><th class="r">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table>
<table class="tot"><tr><td>Subtotal</td><td class="r">' . number_format((float)$inv['subtotal'], 2) . '</td></tr>' . ((float)$inv['discount'] > 0 ? '<tr><td>Discount</td><td class="r">-' . number_format((float)$inv['discount'], 2) . '</td></tr>' : '') . '
<tr><td>Taxable value</td><td class="r">' . number_format((float)$inv['taxable'], 2) . '</td></tr>' . $tax . '<tr><td><b>Total</b></td><td class="r"><b>₹' . number_format((float)$inv['total'], 2) . '</b></td></tr>
<tr><td>Paid</td><td class="r">' . number_format((float)$inv['paid'], 2) . '</td></tr><tr><td>Balance</td><td class="r">' . number_format((float)$inv['balance'], 2) . '</td></tr></table>
<p style="margin-top:32px;color:#555">This is a computer-generated invoice.</p></body></html>';
    }
}
