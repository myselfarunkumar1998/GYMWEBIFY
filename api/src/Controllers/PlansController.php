<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{DB, HttpException, Request, Util, V};

final class PlansController extends Base
{
    private const RULES = [
        'name' => 'required|string|max:120', 'kind' => 'required|in:membership,pt,addon', 'duration_days' => 'required|int|min:1|max:3660',
        'price' => 'required|num|min:0', 'sessions_count' => 'nullable|int|min:0|max:1000', 'max_members' => 'nullable|int|min:1|max:10',
        'freeze_days_allowed' => 'nullable|int|min:0|max:365', 'access_start' => 'nullable|time', 'access_end' => 'nullable|time',
        'all_branches' => 'nullable|bool', 'gst_rate' => 'nullable|num|min:0|max:28', 'sac_code' => 'nullable|string|max:10',
        'description' => 'nullable|string|max:300', 'is_active' => 'nullable|bool',
    ];

    public function index(Request $req): array
    {
        return DB::all("SELECT p.*, (SELECT COUNT(DISTINCT ms.member_id) FROM memberships ms WHERE ms.plan_id = p.id AND ms.status IN ('active','frozen')) AS active_members
            FROM plans p WHERE p.gym_id = ? " . ($req->q('active') ? 'AND p.is_active = 1 ' : '') . 'ORDER BY p.is_active DESC, p.kind, p.price', [$this->gym()]);
    }

    public function store(Request $req): array
    {
        $d = V::check($req->json(), self::RULES);
        $d['gym_id'] = $this->gym();
        $id = DB::insert('plans', $d);
        Util::audit('plan.create', 'plan', $id, $d['name']);
        return ['id' => $id];
    }

    public function update(Request $req): array
    {
        $p = $this->find('plans', $req->id(), 'Plan');
        $d = V::check($req->json(), array_map(fn($r) => str_replace('required|', '', $r), self::RULES));
        DB::update('plans', $d, ['id' => $p['id']]);
        Util::audit('plan.update', 'plan', (int)$p['id']);
        return ['ok' => true];
    }

    public function destroy(Request $req): array
    {
        $p = $this->find('plans', $req->id(), 'Plan');
        if (DB::val('SELECT id FROM memberships WHERE plan_id = ? LIMIT 1', [$p['id']])) {
            DB::update('plans', ['is_active' => 0], ['id' => $p['id']]);
            return ['ok' => true, 'archived' => true];
        }
        DB::run('DELETE FROM plans WHERE id = ? AND gym_id = ?', [$p['id'], $this->gym()]);
        return ['ok' => true];
    }

    public function coupons(Request $req): array { return DB::all('SELECT * FROM coupons WHERE gym_id = ? ORDER BY is_active DESC, id DESC', [$this->gym()]); }

    public function saveCoupon(Request $req): array
    {
        $d = V::check($req->json(), ['code' => 'required|string|max:30', 'kind' => 'required|in:percent,flat', 'value' => 'required|num|min:0',
            'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date', 'max_uses' => 'nullable|int|min:1', 'is_active' => 'nullable|bool']);
        $d['code'] = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $d['code']));
        if ($d['kind'] === 'percent' && $d['value'] > 100) throw HttpException::bad('Percent must be 100 or less', ['value' => 'Max 100']);
        if ($id = $req->id()) {
            $this->find('coupons', $id, 'Coupon');
            DB::update('coupons', $d, ['id' => $id, 'gym_id' => $this->gym()]);
            return ['id' => $id];
        }
        if (DB::val('SELECT id FROM coupons WHERE gym_id = ? AND code = ?', [$this->gym(), $d['code']])) throw HttpException::bad('Code already exists', ['code' => 'Already exists']);
        return ['id' => DB::insert('coupons', $d + ['gym_id' => $this->gym()])];
    }

    public function giftCards(Request $req): array { return DB::all('SELECT * FROM gift_cards WHERE gym_id = ? ORDER BY id DESC', [$this->gym()]); }

    public function createGiftCard(Request $req): array
    {
        $d = V::check($req->json(), ['amount' => 'required|num|min:1|max:200000', 'buyer_name' => 'nullable|string|max:120', 'valid_to' => 'nullable|date',
            'pay_mode' => 'nullable|in:cash,upi,card']);
        $code = 'GC' . Util::randomCode(8);
        $invoiceId = \App\Services\Billing::createInvoice($this->gym(), [['description' => 'Gift card ' . $code, 'rate' => $d['amount'], 'gst_rate' => 0, 'hsn_sac' => '999723', 'ref_type' => 'giftcard']],
            ['kind' => 'other', 'customer_name' => $d['buyer_name'] ?? 'Walk-in']);
        \App\Services\Billing::recordPayment($this->gym(), $invoiceId, $d['amount'], $d['pay_mode'] ?? 'cash');
        $id = DB::insert('gift_cards', ['gym_id' => $this->gym(), 'code' => $code, 'amount' => $d['amount'], 'balance' => $d['amount'], 'buyer_name' => $d['buyer_name'] ?? null, 'valid_to' => $d['valid_to'] ?? date('Y-m-d', strtotime('+1 year'))]);
        return ['id' => $id, 'code' => $code, 'invoice_id' => $invoiceId];
    }
}
