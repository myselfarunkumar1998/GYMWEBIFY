<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Config, DB, HttpException, Request, Response, Util, V};
use App\Services\{Billing, Messenger, Notify, Webhooks};

/** Unauthenticated endpoints: the gym's free website/booking page, trial requests, demo payments. */
final class PublicController extends Base
{
    private function gymBySlug(string $slug): array
    {
        $g = DB::one("SELECT * FROM gyms WHERE slug = ? AND status <> 'suspended'", [$slug]);
        if (!$g) throw HttpException::notFound('Gym not found');
        Auth::$gymId = (int)$g['id'];
        return $g;
    }

    public function show(Request $req): array
    {
        $g = $this->gymBySlug($req->params['slug']);
        $id = (int)$g['id'];
        $settings = json_decode($g['settings'] ?? '{}', true) ?: [];
        return [
            'name' => $g['name'], 'slug' => $g['slug'], 'phone' => $g['phone'], 'email' => $g['email'], 'address' => $g['address'], 'logo_url' => $g['logo_url'],
            'default_language' => $g['default_language'], 'about' => $settings['website']['about'] ?? null, 'trial_days' => $settings['rules']['trial_days'] ?? 3,
            'branches' => DB::all('SELECT id, name, address, phone, open_time, close_time FROM branches WHERE gym_id = ? AND is_active = 1', [$id]),
            'plans' => DB::all("SELECT id, name, kind, duration_days, price, description, access_start, access_end FROM plans WHERE gym_id = ? AND is_active = 1 ORDER BY kind, price", [$id]),
            'classes' => DB::all('SELECT c.name, c.day_of_week, c.start_time, c.duration_min, c.room, u.name AS coach FROM classes c LEFT JOIN users u ON u.id = c.coach_id WHERE c.gym_id = ? AND c.is_active = 1 ORDER BY c.day_of_week, c.start_time', [$id]),
            'google_review_url' => $settings['google_review_url'] ?? null,
        ];
    }

    public function trial(Request $req): array
    {
        $g = $this->gymBySlug($req->params['slug']);
        $d = V::check($req->json(), ['name' => 'required|string|max:120', 'phone' => 'required|phone', 'email' => 'nullable|email',
            'branch_id' => 'nullable|int', 'interest' => 'nullable|string|max:200', 'trial_date' => 'nullable|date', 'website' => 'nullable|string|max:10']);
        if (!empty($d['website'])) return ['ok' => true]; // honeypot: bots fill hidden fields
        $gymId = (int)$g['id'];
        if (!empty($d['branch_id']) && !DB::val('SELECT id FROM branches WHERE id = ? AND gym_id = ?', [$d['branch_id'], $gymId])) $d['branch_id'] = null;
        $existing = DB::one("SELECT id FROM leads WHERE gym_id = ? AND phone = ? AND stage NOT IN ('joined','lost')", [$gymId, $d['phone']]);
        $trialAt = !empty($d['trial_date']) && $d['trial_date'] >= Util::today() ? $d['trial_date'] . ' 07:00:00' : null;
        $code = Util::randomCode(6);
        if ($existing) {
            DB::update('leads', ['trial_at' => $trialAt, 'stage' => $trialAt ? 'trial' : 'new', 'trial_pass_code' => $code], ['id' => $existing['id']]);
            $leadId = (int)$existing['id'];
        } else {
            $leadId = DB::insert('leads', ['gym_id' => $gymId, 'branch_id' => $d['branch_id'] ?? null, 'name' => $d['name'], 'phone' => $d['phone'], 'email' => $d['email'] ?? null,
                'source' => 'website', 'interest' => $d['interest'] ?? null, 'stage' => $trialAt ? 'trial' : 'new', 'trial_at' => $trialAt, 'trial_pass_code' => $code,
                'next_followup_at' => date('Y-m-d H:i:s', time() + 3600)]);
            Webhooks::emit($gymId, 'lead.created', ['lead_id' => $leadId, 'source' => 'website']);
        }
        DB::insert('lead_activities', ['lead_id' => $leadId, 'kind' => 'web_form', 'note' => 'Booked from website' . ($trialAt ? ' for ' . $d['trial_date'] : '')]);
        if ($trialAt) Messenger::template($gymId, 'trial_booked', null, ['name' => strtok($d['name'], ' '), 'date' => date('d M', strtotime($trialAt)), 'code' => $code], ['phone' => $d['phone'], 'lead_id' => $leadId, 'language' => $g['default_language']]);
        Notify::staff($gymId, 'New website enquiry: ' . $d['name'], $d['interest'] ?? null, 'leads');
        return ['ok' => true, 'trial_pass' => $trialAt ? $code : null];
    }

    /** Demo-mode stand-in for a Razorpay payment page. Disabled outside demo mode. */
    public function demoPay(Request $req): array
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$req->id()]);
        if (!$inv || !Util::isDemoGym((int)$inv['gym_id']) || \App\Services\Razorpay::configured((int)$inv['gym_id'])) throw HttpException::notFound();
        Auth::$gymId = (int)$inv['gym_id'];
        if ($req->method === 'POST' && (float)$inv['balance'] > 0) {
            Billing::recordPayment((int)$inv['gym_id'], (int)$inv['id'], (float)$inv['balance'], 'online', 'DEMO-UPI', 'demo_' . $inv['id'] . '_' . time());
            DB::run("UPDATE payment_links SET status = 'paid' WHERE invoice_id = ?", [$inv['id']]);
            $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$req->id()]);
        }
        $g = Util::gym((int)$inv['gym_id']);
        return ['gym' => $g['name'], 'invoice_no' => $inv['invoice_no'], 'customer' => $inv['customer_name'], 'total' => (float)$inv['total'], 'balance' => (float)$inv['balance'], 'status' => $inv['status']];
    }

    public function health(Request $req): array
    {
        DB::val('SELECT 1');
        return ['ok' => true, 'time' => date('c')];
    }
}
