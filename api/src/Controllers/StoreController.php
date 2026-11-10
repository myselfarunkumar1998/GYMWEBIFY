<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Util, V};
use App\Services\Billing;

final class StoreController extends Base
{
    private const RULES = ['name' => 'required|string|max:120', 'category' => 'required|in:supplement,merchandise,locker,service,other', 'sku' => 'nullable|string|max:40',
        'hsn' => 'nullable|string|max:10', 'price' => 'required|num|min:0', 'cost' => 'nullable|num|min:0', 'gst_rate' => 'nullable|in:0,5,12,18,28',
        'reorder_level' => 'nullable|int|min:0', 'track_stock' => 'nullable|bool', 'sell_online' => 'nullable|bool', 'is_active' => 'nullable|bool', 'branch_id' => 'nullable|int'];

    public function index(Request $req): array
    {
        $g = $this->gym();
        $items = DB::all("SELECT p.*, (SELECT COALESCE(-SUM(qty),0) FROM stock_moves s WHERE s.product_id = p.id AND s.kind = 'sale' AND s.created_at > NOW() - INTERVAL 30 DAY) AS sold_30d
            FROM products p WHERE p.gym_id = ? ORDER BY p.is_active DESC, p.category, p.name", [$g]);
        $m = date('Y-m');
        $sales = DB::one("SELECT COALESCE(SUM(i.total),0) total, COUNT(*) n FROM invoices i WHERE i.gym_id = ? AND i.kind = 'store' AND i.status <> 'cancelled' AND DATE_FORMAT(i.invoice_date, '%Y-%m') = ?", [$g, $m]);
        $margin = (float)DB::val("SELECT COALESCE(SUM(it.amount - it.qty * p.cost),0) FROM invoice_items it JOIN invoices i ON i.id = it.invoice_id JOIN products p ON p.id = it.ref_id
            WHERE i.gym_id = ? AND it.ref_type = 'product' AND i.status <> 'cancelled' AND DATE_FORMAT(i.invoice_date, '%Y-%m') = ?", [$g, $m]);
        return ['items' => $items, 'month_sales' => (float)$sales['total'], 'month_orders' => (int)$sales['n'], 'month_margin' => $margin,
            'low_stock' => count(array_filter($items, fn($p) => $p['is_active'] && $p['track_stock'] && $p['stock'] <= $p['reorder_level'])),
            'stock_value' => array_sum(array_map(fn($p) => max(0, (int)$p['stock']) * (float)$p['cost'], $items)),
            'recent' => DB::all("SELECT i.id, i.invoice_no, i.customer_name, i.total, i.status, i.created_at, (SELECT GROUP_CONCAT(description SEPARATOR ', ') FROM invoice_items WHERE invoice_id = i.id) items
                FROM invoices i WHERE i.gym_id = ? AND i.kind = 'store' ORDER BY i.id DESC LIMIT 15", [$g])];
    }

    public function save(Request $req): array
    {
        Auth::require('store.manage');
        $d = V::check($req->json(), $req->id() ? array_map(fn($r) => str_replace('required|', '', $r), self::RULES) : self::RULES);
        $this->assertBranch($d['branch_id'] ?? null);
        if ($id = $req->id()) { $this->find('products', $id, 'Product'); DB::update('products', $d, ['id' => $id, 'gym_id' => $this->gym()]); return ['id' => $id]; }
        $id = DB::insert('products', $d + ['gym_id' => $this->gym()]);
        if ($qty = (int)$req->input('opening_stock', 0)) $this->move($id, $qty, 'purchase', 'Opening stock');
        return ['id' => $id];
    }

    private function move(int $productId, int $qty, string $kind, ?string $ref): void
    {
        DB::run('UPDATE products SET stock = stock + ? WHERE id = ? AND gym_id = ?', [$qty, $productId, $this->gym()]);
        DB::insert('stock_moves', ['gym_id' => $this->gym(), 'product_id' => $productId, 'qty' => $qty, 'kind' => $kind, 'ref' => $ref, 'created_by' => Auth::staffId()]);
    }

    public function stock(Request $req): array
    {
        Auth::require('store.manage');
        $p = $this->find('products', $req->id(), 'Product');
        $d = V::check($req->json(), ['qty' => 'required|int|min:-10000|max:10000', 'kind' => 'required|in:purchase,adjust', 'ref' => 'nullable|string|max:60', 'cost' => 'nullable|num|min:0']);
        if ($d['qty'] === 0) throw HttpException::bad('Quantity cannot be zero');
        if ($d['kind'] === 'purchase' && $d['qty'] < 0) throw HttpException::bad('Purchase must be positive');
        $this->move((int)$p['id'], $d['qty'], $d['kind'], $d['ref'] ?? null);
        if (!empty($d['cost'])) DB::update('products', ['cost' => $d['cost']], ['id' => $p['id']]);
        if ($d['kind'] === 'purchase' && !empty($d['cost'])) {
            DB::insert('expenses', ['gym_id' => $this->gym(), 'category' => 'Store purchase', 'amount' => round($d['cost'] * $d['qty'], 2), 'expense_date' => Util::today(), 'note' => $p['name'] . ' × ' . $d['qty'], 'created_by' => Auth::staffId()]);
        }
        Util::audit('stock.' . $d['kind'], 'product', (int)$p['id'], $d['qty']);
        return ['stock' => (int)DB::val('SELECT stock FROM products WHERE id = ?', [$p['id']])];
    }

    public function moves(Request $req): array
    {
        $p = $this->find('products', $req->id(), 'Product');
        return DB::all('SELECT s.*, u.name AS by_name FROM stock_moves s LEFT JOIN users u ON u.id = s.created_by WHERE s.product_id = ? ORDER BY s.id DESC LIMIT 100', [$p['id']]);
    }

    /** POS sale: creates a store invoice, takes stock out and records payment. Shared with member app orders. */
    public static function sell(int $gymId, array $items, ?int $memberId, ?string $customerName, array $pay = []): int
    {
        return DB::tx(function () use ($gymId, $items, $memberId, $customerName, $pay) {
            $lines = [];
            foreach ($items as $it) {
                $pid = (int)($it['product_id'] ?? 0);
                $qty = (int)($it['qty'] ?? 1);
                if ($qty < 1 || $qty > 100) throw HttpException::bad('Invalid quantity');
                $p = DB::one('SELECT * FROM products WHERE id = ? AND gym_id = ? AND is_active = 1 FOR UPDATE', [$pid, $gymId]);
                if (!$p) throw HttpException::bad('Product not available');
                if ($p['track_stock'] && $p['stock'] < $qty) throw HttpException::bad("Only {$p['stock']} left of {$p['name']}");
                $lines[] = ['description' => $p['name'], 'rate' => (float)$p['price'], 'qty' => $qty, 'gst_rate' => (float)$p['gst_rate'], 'hsn_sac' => $p['hsn'] ?: '21069099', 'ref_type' => 'product', 'ref_id' => (int)$p['id']];
            }
            $inv = Billing::createInvoice($gymId, $lines, ['member_id' => $memberId, 'customer_name' => $customerName, 'kind' => 'store']);
            $no = DB::val('SELECT invoice_no FROM invoices WHERE id = ?', [$inv]);
            foreach ($lines as $l) {
                if (DB::val('SELECT track_stock FROM products WHERE id = ?', [$l['ref_id']])) {
                    DB::run('UPDATE products SET stock = stock - ? WHERE id = ?', [$l['qty'], $l['ref_id']]);
                    DB::insert('stock_moves', ['gym_id' => $gymId, 'product_id' => $l['ref_id'], 'qty' => -$l['qty'], 'kind' => 'sale', 'ref' => $no, 'created_by' => Auth::staffId()]);
                }
            }
            if (!empty($pay['amount'])) Billing::recordPayment($gymId, $inv, (float)$pay['amount'], $pay['mode'] ?? 'cash', $pay['reference'] ?? null);
            return $inv;
        });
    }

    public function sale(Request $req): array
    {
        $d = V::check($req->json(), ['items' => 'required|array', 'member_id' => 'nullable|int', 'customer_name' => 'nullable|string|max:120',
            'pay_mode' => 'nullable|in:cash,upi,card,credit', 'pay_reference' => 'nullable|string|max:100', 'pay_now' => 'nullable|bool']);
        if (!empty($d['member_id'])) $this->memberRow($d['member_id']);
        $inv = self::sell($this->gym(), $d['items'], $d['member_id'] ?? null, $d['customer_name'] ?? ($d['member_id'] ? null : 'Walk-in'));
        if ($d['pay_now'] ?? true) {
            $total = (float)DB::val('SELECT total FROM invoices WHERE id = ?', [$inv]);
            Billing::recordPayment($this->gym(), $inv, $total, $d['pay_mode'] ?? 'cash', $d['pay_reference'] ?? null);
        }
        Util::audit('store.sale', 'invoice', $inv);
        return ['invoice_id' => $inv];
    }
}
