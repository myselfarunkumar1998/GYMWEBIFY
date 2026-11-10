<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Response, Util, V};
use App\Services\{Billing, Messenger, Razorpay};

final class BillingController extends Base
{
    public function summary(Request $req): array
    {
        $g = $this->gym();
        $month = preg_match('/^\d{4}-\d{2}$/', (string)$req->q('month')) ? $req->q('month') : date('Y-m');
        [$bf] = $this->branchFilter($req, 'i.branch_id');
        $collected = (float)DB::val("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.gym_id = ? AND DATE_FORMAT(p.paid_at, '%Y-%m') = ? $bf", [$g, $month]);
        $gst = DB::one("SELECT COALESCE(SUM(i.cgst),0) cgst, COALESCE(SUM(i.sgst),0) sgst, COALESCE(SUM(i.igst),0) igst FROM invoices i WHERE i.gym_id = ? AND DATE_FORMAT(i.invoice_date, '%Y-%m') = ? AND i.status <> 'cancelled' $bf", [$g, $month]);
        $autopay = (int)DB::val("SELECT COUNT(*) FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.gym_id = ? AND p.mode = 'autopay' AND DATE_FORMAT(p.paid_at, '%Y-%m') = ? $bf", [$g, $month]);
        $overdue = DB::one("SELECT COALESCE(SUM(i.balance),0) amt, COUNT(DISTINCT i.member_id) members FROM invoices i WHERE i.gym_id = ? AND i.status IN ('unpaid','partial') $bf", [$g]);
        $blocked = (int)DB::val("SELECT COUNT(*) FROM members WHERE gym_id = ? AND deleted_at IS NULL AND id IN (SELECT member_id FROM invoices WHERE gym_id = ? AND status IN ('unpaid','partial') AND due_date < CURDATE() - INTERVAL ? DAY)",
            [$g, $g, (int)Util::setting('rules.block_dues_after_days', 15)]);
        $byMode = DB::all("SELECT p.mode, SUM(p.amount) amount FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.gym_id = ? AND DATE_FORMAT(p.paid_at, '%Y-%m') = ? $bf GROUP BY p.mode", [$g, $month]);
        $expenses = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE gym_id = ? AND DATE_FORMAT(expense_date, '%Y-%m') = ?", [$g, $month]);
        return ['month' => $month, 'collected' => $collected, 'gst' => $gst, 'gst_total' => array_sum(array_map('floatval', $gst)), 'autopay_count' => $autopay,
            'overdue' => ['amount' => (float)$overdue['amt'], 'members' => (int)$overdue['members'], 'blocked' => $blocked], 'by_mode' => $byMode, 'expenses' => $expenses,
            'gstin' => Util::gym()['gstin']];
    }

    public function invoices(Request $req): array
    {
        $g = $this->gym();
        $sql = 'SELECT i.id, i.invoice_no, i.invoice_date, i.member_id, i.customer_name, i.kind, i.taxable, i.cgst + i.sgst + i.igst AS gst, i.total, i.paid, i.balance, i.status,
            (SELECT GROUP_CONCAT(DISTINCT p.mode) FROM payments p WHERE p.invoice_id = i.id) AS modes,
            (SELECT GROUP_CONCAT(it.description SEPARATOR ", ") FROM invoice_items it WHERE it.invoice_id = i.id) AS items
            FROM invoices i WHERE i.gym_id = ?';
        $p = [$g];
        [$bf] = $this->branchFilter($req, 'i.branch_id');
        $sql .= $bf;
        if ($s = $req->q('status')) { if (in_array($s, ['unpaid', 'partial', 'paid', 'cancelled'], true)) { $sql .= ' AND i.status = ?'; $p[] = $s; } elseif ($s === 'due') { $sql .= " AND i.status IN ('unpaid','partial')"; } }
        if ($k = $req->q('kind')) { if (in_array($k, ['membership', 'pt', 'store', 'other'], true)) { $sql .= ' AND i.kind = ?'; $p[] = $k; } }
        if ($mid = (int)$req->q('member_id', 0)) { $sql .= ' AND i.member_id = ?'; $p[] = $mid; }
        if ($from = $req->q('from')) { $sql .= ' AND i.invoice_date >= ?'; $p[] = V::check(['d' => $from], ['d' => 'date'])['d']; }
        if ($to = $req->q('to')) { $sql .= ' AND i.invoice_date <= ?'; $p[] = V::check(['d' => $to], ['d' => 'date'])['d']; }
        if ($q = trim((string)$req->q('q', ''))) { $sql .= ' AND (i.invoice_no LIKE ? OR i.customer_name LIKE ?)'; $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_') . '%'; array_push($p, $like, $like); }
        return $this->paged($sql . ' ORDER BY i.id DESC', $p, $req);
    }

    public function show(Request $req): array { return Billing::invoiceFull($this->gym(), $req->id()); }

    public function print(Request $req): Response
    {
        return Response::text(Billing::invoiceHtml($this->gym(), $req->id()), 200, 'text/html');
    }

    /** Custom invoice (other services, PT, etc.). Store sales use StoreController::sale. */
    public function store(Request $req): array
    {
        $d = V::check($req->json(), ['member_id' => 'nullable|int', 'customer_name' => 'nullable|string|max:120', 'customer_gstin' => 'nullable|gstin',
            'items' => 'required|array', 'discount' => 'nullable|num|min:0', 'coupon' => 'nullable|string|max:30', 'due_date' => 'nullable|date', 'notes' => 'nullable|string|max:300',
            'pay_amount' => 'nullable|num|min:0', 'pay_mode' => 'nullable|in:cash,upi,card,netbanking,credit,giftcard', 'pay_reference' => 'nullable|string|max:100']);
        if (!empty($d['member_id'])) $this->memberRow($d['member_id']);
        if (empty($d['member_id']) && empty($d['customer_name'])) throw HttpException::bad('Choose a member or enter a customer name', ['customer_name' => 'Required']);
        $items = [];
        foreach ($d['items'] as $i => $it) {
            $x = V::check((array)$it, ['description' => 'required|string|max:200', 'rate' => 'required|num|min:0', 'qty' => 'nullable|num|min:0.01', 'gst_rate' => 'nullable|in:0,5,12,18,28', 'hsn_sac' => 'nullable|string|max:10']);
            $items[] = $x + ['ref_type' => 'custom'];
        }
        $id = Billing::createInvoice($this->gym(), $items, ['member_id' => $d['member_id'] ?? null, 'customer_name' => $d['customer_name'] ?? null, 'customer_gstin' => $d['customer_gstin'] ?? null,
            'discount' => $d['discount'] ?? 0, 'coupon' => $d['coupon'] ?? null, 'due_date' => $d['due_date'] ?? null, 'notes' => $d['notes'] ?? null, 'kind' => 'other', 'branch_id' => $this->defaultBranch()]);
        if (!empty($d['pay_amount'])) Billing::recordPayment($this->gym(), $id, $d['pay_amount'], $d['pay_mode'] ?? 'cash', $d['pay_reference'] ?? null);
        Util::audit('invoice.create', 'invoice', $id);
        return ['id' => $id];
    }

    public function pay(Request $req): array
    {
        $inv = $this->find('invoices', $req->id(), 'Invoice');
        $d = V::check($req->json(), ['amount' => 'required|num|min:0.01', 'mode' => 'required|in:cash,upi,card,netbanking,credit,giftcard', 'reference' => 'nullable|string|max:100']);
        $pid = Billing::recordPayment($this->gym(), (int)$inv['id'], $d['amount'], $d['mode'], $d['reference'] ?? null);
        Util::audit('payment.record', 'invoice', (int)$inv['id'], $d);
        return ['payment_id' => $pid, 'invoice' => Billing::invoiceFull($this->gym(), (int)$inv['id'])];
    }

    public function cancel(Request $req): array
    {
        $inv = $this->find('invoices', $req->id(), 'Invoice');
        $d = V::check($req->json(), ['reason' => 'required|string|max:200']);
        $r = Billing::cancel($this->gym(), (int)$inv['id'], $d['reason']);
        Util::audit('invoice.cancel', 'invoice', (int)$inv['id'], $d['reason']);
        return $r;
    }

    /** Create a Razorpay payment link and send it on WhatsApp. */
    public function paymentLink(Request $req): array
    {
        $inv = $this->find('invoices', $req->id(), 'Invoice');
        $link = Razorpay::paymentLink($this->gym(), (int)$inv['id']);
        $sent = null;
        if ($inv['member_id'] && $req->input('send', true)) {
            $m = DB::one('SELECT * FROM members WHERE id = ?', [$inv['member_id']]);
            $sent = Messenger::template($this->gym(), 'payment_link', $m, ['amount' => number_format((float)$inv['balance'], 2), 'invoice' => $inv['invoice_no'], 'link' => $link['short_url']], ['force' => true]);
            if ($sent) Messenger::dispatch(1, $sent);
        }
        return ['url' => $link['short_url'], 'message_id' => $sent];
    }

    public function dues(Request $req): array
    {
        $g = $this->gym();
        [$bf] = $this->branchFilter($req, 'i.branch_id');
        return DB::all("SELECT m.id AS member_id, m.name, m.member_code, m.phone, SUM(i.balance) AS balance, MIN(i.due_date) AS oldest_due, COUNT(*) AS invoices,
              DATEDIFF(CURDATE(), MIN(i.due_date)) AS days_overdue, GROUP_CONCAT(i.id) AS invoice_ids,
              (SELECT MAX(x.created_at) FROM messages x WHERE x.member_id = m.id AND x.tkey IN ('dues_reminder','payment_link')) AS last_reminded
            FROM invoices i JOIN members m ON m.id = i.member_id WHERE i.gym_id = ? AND i.status IN ('unpaid','partial') AND m.deleted_at IS NULL $bf
            GROUP BY m.id ORDER BY days_overdue DESC", [$g]);
    }

    public function remindDues(Request $req): array
    {
        $ids = array_map('intval', (array)$req->input('member_ids', []));
        $g = $this->gym();
        $sql = "SELECT i.id, i.invoice_no, i.balance, m.* FROM invoices i JOIN members m ON m.id = i.member_id WHERE i.gym_id = ? AND i.status IN ('unpaid','partial') AND m.deleted_at IS NULL";
        if ($ids) $sql .= ' AND m.id IN (' . DB::inList($ids) . ')';
        $n = 0;
        foreach (DB::all($sql, [$g]) as $r) {
            $url = null;
            try { $url = Razorpay::paymentLink($g, (int)$r['id'])['short_url']; } catch (\Throwable) {}
            $url ??= rtrim((string)\App\Core\Config::get('app_url'), '/') . '/m/' . Util::gym()['slug'] . '/pay';
            if (Messenger::template($g, 'dues_reminder', $r, ['amount' => number_format((float)$r['balance'], 2), 'invoice' => $r['invoice_no'], 'link' => $url], ['force' => true])) $n++;
        }
        Util::audit('dues.remind', null, null, ['count' => $n]);
        return ['queued' => $n];
    }

    public function payments(Request $req): array
    {
        $sql = 'SELECT p.*, i.invoice_no, i.customer_name, u.name AS received_by_name FROM payments p JOIN invoices i ON i.id = p.invoice_id LEFT JOIN users u ON u.id = p.received_by WHERE p.gym_id = ?';
        $prm = [$this->gym()];
        if ($d = $req->q('date')) { $sql .= ' AND DATE(p.paid_at) = ?'; $prm[] = V::check(['d' => $d], ['d' => 'date'])['d']; }
        if ($m = $req->q('mode')) { $sql .= ' AND p.mode = ?'; $prm[] = (string)$m; }
        return $this->paged($sql . ' ORDER BY p.id DESC', $prm, $req);
    }

    public function paymentLinks(Request $req): array
    {
        return DB::all('SELECT pl.*, i.invoice_no, i.customer_name FROM payment_links pl JOIN invoices i ON i.id = pl.invoice_id WHERE pl.gym_id = ? ORDER BY pl.id DESC LIMIT 200', [$this->gym()]);
    }

    public function creditNotes(Request $req): array
    {
        return DB::all('SELECT c.*, i.invoice_no, i.customer_name FROM credit_notes c JOIN invoices i ON i.id = c.invoice_id WHERE c.gym_id = ? ORDER BY c.id DESC LIMIT 200', [$this->gym()]);
    }

    // ---- Expenses ----

    public function expenses(Request $req): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string)$req->q('month')) ? $req->q('month') : date('Y-m');
        return DB::all("SELECT e.*, b.name AS branch_name FROM expenses e LEFT JOIN branches b ON b.id = e.branch_id WHERE e.gym_id = ? AND DATE_FORMAT(e.expense_date, '%Y-%m') = ? ORDER BY e.expense_date DESC", [$this->gym(), $month]);
    }

    public function saveExpense(Request $req): array
    {
        $d = V::check($req->json(), ['category' => 'required|string|max:60', 'amount' => 'required|num|min:0.01', 'expense_date' => 'required|date', 'note' => 'nullable|string|max:255', 'branch_id' => 'nullable|int']);
        $this->assertBranch($d['branch_id'] ?? null);
        if ($id = $req->id()) { $this->find('expenses', $id, 'Expense'); DB::update('expenses', $d, ['id' => $id, 'gym_id' => $this->gym()]); return ['id' => $id]; }
        return ['id' => DB::insert('expenses', $d + ['gym_id' => $this->gym(), 'created_by' => Auth::staffId()])];
    }

    public function deleteExpense(Request $req): array
    {
        $e = $this->find('expenses', $req->id(), 'Expense');
        DB::run('DELETE FROM expenses WHERE id = ? AND gym_id = ?', [$e['id'], $this->gym()]);
        return ['ok' => true];
    }

    // ---- GST & accounting exports ----

    private function month(Request $req): string
    {
        $m = (string)$req->q('month', date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) throw HttpException::bad('Use month format YYYY-MM');
        return $m;
    }

    public function gstReport(Request $req): array|Response
    {
        Auth::require('reports.finance');
        $m = $this->month($req);
        $rows = DB::all("SELECT invoice_no, invoice_date, customer_name, customer_gstin, place_of_supply, taxable, cgst, sgst, igst, total, status FROM invoices
            WHERE gym_id = ? AND DATE_FORMAT(invoice_date, '%Y-%m') = ? ORDER BY invoice_date, id", [$this->gym(), $m]);
        $byRate = DB::all("SELECT it.gst_rate, it.hsn_sac, SUM(it.amount) AS gross, COUNT(*) line_count FROM invoice_items it JOIN invoices i ON i.id = it.invoice_id
            WHERE i.gym_id = ? AND DATE_FORMAT(i.invoice_date, '%Y-%m') = ? AND i.status <> 'cancelled' GROUP BY it.gst_rate, it.hsn_sac", [$this->gym(), $m]);
        $active = array_filter($rows, fn($r) => $r['status'] !== 'cancelled');
        $tot = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'total' => 0];
        foreach ($active as $r) foreach ($tot as $k => $_) $tot[$k] += (float)$r[$k];
        if ($req->q('format') === 'csv') {
            return Response::file(Util::csv(array_map(fn($r) => array_values($r), $rows), ['Invoice', 'Date', 'Customer', 'Customer GSTIN', 'Place of supply', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total', 'Status']), 'text/csv', "gst-$m.csv");
        }
        return ['month' => $m, 'gstin' => Util::gym()['gstin'], 'totals' => array_map(fn($v) => round($v, 2), $tot), 'b2b' => array_values(array_filter($active, fn($r) => $r['customer_gstin'])),
            'b2c_count' => count(array_filter($active, fn($r) => !$r['customer_gstin'])), 'by_rate' => $byRate, 'invoices' => $rows,
            'credit_notes' => DB::all("SELECT c.note_no, c.amount, c.reason, i.invoice_no, c.created_at FROM credit_notes c JOIN invoices i ON i.id = c.invoice_id WHERE c.gym_id = ? AND DATE_FORMAT(c.created_at, '%Y-%m') = ?", [$this->gym(), $m])];
    }

    /** Tally Prime XML: one Sales voucher per invoice with GST ledgers. Import via Gateway › Import › Vouchers. */
    public function tally(Request $req): Response
    {
        Auth::require('reports.finance');
        $m = $this->month($req);
        $g = Util::gym();
        $ledgers = Util::setting('tally', [], null) + ['sales' => 'Membership Sales', 'cgst' => 'Output CGST', 'sgst' => 'Output SGST', 'igst' => 'Output IGST', 'party' => 'Members'];
        $x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $vouchers = '';
        foreach (DB::all("SELECT * FROM invoices WHERE gym_id = ? AND DATE_FORMAT(invoice_date, '%Y-%m') = ? AND status <> 'cancelled' ORDER BY id", [$this->gym(), $m]) as $i) {
            $d = str_replace('-', '', $i['invoice_date']);
            $party = $i['customer_name'] ?: $ledgers['party'];
            $entries = '<ALLLEDGERENTRIES.LIST><LEDGERNAME>' . $x($ledgers['party']) . '</LEDGERNAME><ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE><AMOUNT>-' . $i['total'] . '</AMOUNT></ALLLEDGERENTRIES.LIST>'
                . '<ALLLEDGERENTRIES.LIST><LEDGERNAME>' . $x($ledgers['sales']) . '</LEDGERNAME><ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE><AMOUNT>' . $i['taxable'] . '</AMOUNT></ALLLEDGERENTRIES.LIST>';
            foreach (['cgst', 'sgst', 'igst'] as $t) if ((float)$i[$t] > 0) $entries .= '<ALLLEDGERENTRIES.LIST><LEDGERNAME>' . $x($ledgers[$t]) . '</LEDGERNAME><ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE><AMOUNT>' . $i[$t] . '</AMOUNT></ALLLEDGERENTRIES.LIST>';
            $vouchers .= '<TALLYMESSAGE xmlns:UDF="TallyUDF"><VOUCHER VCHTYPE="Sales" ACTION="Create"><DATE>' . $d . '</DATE><VOUCHERTYPENAME>Sales</VOUCHERTYPENAME><VOUCHERNUMBER>' . $x($i['invoice_no'])
                . '</VOUCHERNUMBER><PARTYLEDGERNAME>' . $x($ledgers['party']) . '</PARTYLEDGERNAME><NARRATION>' . $x($party) . '</NARRATION><PLACEOFSUPPLY>' . $x($i['place_of_supply']) . '</PLACEOFSUPPLY>' . $entries . '</VOUCHER></TALLYMESSAGE>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER><BODY><IMPORTDATA><REQUESTDESC><REPORTNAME>Vouchers</REPORTNAME><STATICVARIABLES><SVCURRENTCOMPANY>'
            . $x($g['legal_name'] ?: $g['name']) . '</SVCURRENTCOMPANY></STATICVARIABLES></REQUESTDESC><REQUESTDATA>' . $vouchers . '</REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';
        Util::audit('export.tally', null, null, $m);
        return Response::file($xml, 'application/xml', "tally-sales-$m.xml");
    }

    /** Zoho Books invoice import CSV. */
    public function zoho(Request $req): Response
    {
        Auth::require('reports.finance');
        $m = $this->month($req);
        $rows = DB::all("SELECT i.invoice_no, i.invoice_date, i.customer_name, i.customer_gstin, i.place_of_supply, it.description, it.qty, it.rate, it.gst_rate, it.hsn_sac, i.status
            FROM invoices i JOIN invoice_items it ON it.invoice_id = i.id WHERE i.gym_id = ? AND DATE_FORMAT(i.invoice_date, '%Y-%m') = ? AND i.status <> 'cancelled' ORDER BY i.id", [$this->gym(), $m]);
        $out = array_map(fn($r) => [$r['invoice_no'], $r['invoice_date'], $r['customer_name'] ?: 'Walk-in', $r['customer_gstin'], $r['place_of_supply'], $r['description'], $r['qty'], $r['rate'], 'GST' . (int)$r['gst_rate'], $r['hsn_sac'], $r['status'] === 'paid' ? 'Paid' : 'Sent'], $rows);
        return Response::file(Util::csv($out, ['Invoice Number', 'Invoice Date', 'Customer Name', 'GST Identification Number (GSTIN)', 'Place of Supply', 'Item Name', 'Quantity', 'Item Price', 'Item Tax', 'HSN/SAC', 'Invoice Status']), 'text/csv', "zoho-invoices-$m.csv");
    }
}
