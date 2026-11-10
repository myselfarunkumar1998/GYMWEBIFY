<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Config, DB, HttpException, Request, Response, Upload, Util, V};
use App\Services\{Http, Messenger, Razorpay};

final class SettingsController extends Base
{
    /** Integration fields: secret ones are encrypted at rest and never returned in full. */
    public const INTEGRATIONS = [
        'whatsapp_phone_id' => false, 'whatsapp_token' => true, 'whatsapp_app_secret' => true, 'whatsapp_verify_token' => false,
        'razorpay_key_id' => false, 'razorpay_key_secret' => true, 'razorpay_webhook_secret' => true,
        'sms_url' => false, 'sms_key' => true, 'anthropic_key' => true,
        'meta_verify_token' => false, 'meta_page_token' => true, 'google_lead_key' => true,
        'strava_client_id' => false, 'strava_client_secret' => true,
    ];

    public function index(Request $req): array
    {
        $g = Util::gym();
        $s = $g['settings'];
        $integ = [];
        foreach (self::INTEGRATIONS as $k => $secret) {
            $v = $s['integrations'][$k] ?? null;
            $integ[$k] = $secret ? ($v ? '••••' . substr((string)Util::decrypt($v), -4) : null) : $v;
        }
        $base = rtrim((string)Config::get('app_url'), '/');
        unset($s['integrations']);
        return [
            'gym' => array_intersect_key($g, array_flip(['id', 'name', 'slug', 'legal_name', 'gstin', 'state_code', 'address', 'phone', 'email', 'logo_url', 'default_language', 'subscription_plan', 'subscription_until'])),
            'settings' => $s, 'integrations' => Auth::can('settings.manage') ? $integ : null,
            'status' => ['whatsapp' => Messenger::whatsappConfigured((int)$g['id']), 'razorpay' => Razorpay::configured((int)$g['id']), 'sms' => !empty($integ['sms_url']),
                'ai' => !empty($integ['anthropic_key']) || (bool)Config::get('anthropic.api_key'), 'push' => \App\Services\Push::enabled()],
            'urls' => ['website' => "$base/g/{$g['slug']}", 'member_app' => "$base/m/{$g['slug']}", 'razorpay_webhook' => "$base/api/hooks/razorpay/{$g['id']}",
                'whatsapp_webhook' => "$base/api/hooks/whatsapp/{$g['id']}", 'meta_leads_webhook' => "$base/api/hooks/meta-leads/{$g['id']}",
                'google_leads_webhook' => "$base/api/hooks/google-leads/{$g['id']}", 'adms' => parse_url($base, PHP_URL_HOST), 'strava_callback' => "$base/api/m/strava/callback"],
            'branches' => DB::all('SELECT * FROM branches WHERE gym_id = ? ORDER BY id', [$g['id']]),
        ];
    }

    private function saveSettings(array $s): void
    {
        DB::update('gyms', ['settings' => json_encode($s, JSON_UNESCAPED_UNICODE)], ['id' => $this->gym()]);
        Util::forgetGym();
    }

    public function updateGym(Request $req): array
    {
        Auth::require('settings.manage');
        $d = V::check($req->json(), ['name' => 'string|max:150', 'legal_name' => 'nullable|string|max:200', 'gstin' => 'nullable|gstin', 'state_code' => 'nullable|string|max:2',
            'address' => 'nullable|string|max:400', 'phone' => 'nullable|phone', 'email' => 'nullable|email', 'default_language' => 'nullable|in:en,ta']);
        if (!empty($d['gstin'])) $d['state_code'] = substr($d['gstin'], 0, 2);
        DB::update('gyms', $d, ['id' => $this->gym()]);
        Util::forgetGym();
        Util::audit('settings.gym', 'gym', $this->gym(), array_keys($d));
        return ['ok' => true];
    }

    public function updateRules(Request $req): array
    {
        Auth::require('settings.manage');
        $in = $req->json();
        $r = V::check((array)($in['rules'] ?? []), ['grace_days' => 'nullable|int|min:0|max:60', 'joining_fee' => 'nullable|num|min:0', 'freeze_min_days' => 'nullable|int|min:1|max:90',
            'freeze_fee' => 'nullable|num|min:0', 'transfer_fee' => 'nullable|num|min:0', 'referral_credit' => 'nullable|num|min:0', 'block_dues_after_days' => 'nullable|int|min:0|max:365',
            'trial_days' => 'nullable|int|min:0|max:30', 'dues_reminder_every_days' => 'nullable|int|min:1|max:30', 'review_after_days' => 'nullable|int|min:1|max:365',
            'emi_parts_yearly' => 'nullable|int|min:1|max:12', 'late_cancel_hours' => 'nullable|int|min:0|max:48', 'winback_offer' => 'nullable|string|max:100', 'renewal_reminder_days' => 'nullable|array']);
        if (isset($r['renewal_reminder_days'])) $r['renewal_reminder_days'] = array_values(array_unique(array_filter(array_map('intval', $r['renewal_reminder_days']), fn($x) => $x > 0 && $x <= 60)));
        $top = V::check($in, ['invoice_prefix' => 'nullable|string|max:10', 'prices_include_gst' => 'nullable|bool', 'google_review_url' => 'nullable|url', 'waiver_text' => 'nullable|string|max:5000', 'website_about' => 'nullable|string|max:2000']);
        $s = Util::gym()['settings'];
        $s['rules'] = array_merge($s['rules'] ?? [], array_filter($r, fn($v) => $v !== null));
        foreach (['invoice_prefix', 'prices_include_gst', 'google_review_url', 'waiver_text'] as $k) if (array_key_exists($k, $top)) $s[$k] = $top[$k];
        if (isset($top['invoice_prefix'])) $s['invoice_prefix'] = preg_replace('/[^A-Z0-9-]/', '', strtoupper($top['invoice_prefix'])) ?: 'INV';
        if (array_key_exists('website_about', $top)) $s['website']['about'] = $top['website_about'];
        if (isset($in['devices']) && is_array($in['devices'])) $s['devices'] = V::check($in['devices'], ['allowed_group' => 'required|int|min:1|max:99', 'blocked_group' => 'required|int|min:1|max:99']);
        $this->saveSettings($s);
        Util::audit('settings.rules', 'gym', $this->gym());
        return ['ok' => true];
    }

    public function updateIntegrations(Request $req): array
    {
        Auth::require('settings.manage');
        $s = Util::gym()['settings'];
        $changed = [];
        foreach (self::INTEGRATIONS as $k => $secret) {
            if (!array_key_exists($k, $req->json())) continue;
            $v = $req->input($k);
            if ($v === null || $v === '') { unset($s['integrations'][$k]); $changed[] = $k; continue; }
            $v = trim((string)$v);
            if (str_starts_with($v, '••••')) continue; // masked value echoed back unchanged
            if (mb_strlen($v) > 2000) throw HttpException::bad('Value too long', [$k => 'Too long']);
            if ($k === 'sms_url' && !preg_match('#^https://#', $v)) throw HttpException::bad('SMS URL must start with https://', [$k => 'Use https://']);
            $s['integrations'][$k] = $secret ? Util::encrypt($v) : $v;
            $changed[] = $k;
        }
        $this->saveSettings($s);
        Util::audit('settings.integrations', 'gym', $this->gym(), $changed);
        return ['ok' => true, 'changed' => $changed];
    }

    public function testIntegration(Request $req): array
    {
        Auth::require('settings.manage');
        $g = $this->gym();
        switch ($req->params['kind'] ?? '') {
            case 'whatsapp':
                if (!Messenger::whatsappConfigured($g)) return ['ok' => false, 'message' => 'Add the phone number ID and access token first.'];
                [$code, $res] = Http::json('GET', 'https://graph.facebook.com/v21.0/' . rawurlencode((string)Util::setting('integrations.whatsapp_phone_id')) . '?fields=display_phone_number,verified_name', null, ['Authorization: Bearer ' . Util::secret('whatsapp_token')]);
                return $code === 200 ? ['ok' => true, 'message' => 'Connected: ' . ($res['verified_name'] ?? '') . ' ' . ($res['display_phone_number'] ?? '')] : ['ok' => false, 'message' => $res['error']['message'] ?? "HTTP $code"];
            case 'razorpay':
                if (!Razorpay::configured($g)) return ['ok' => false, 'message' => 'Add the key ID and key secret first.'];
                [$code, $res] = Http::json('GET', 'https://api.razorpay.com/v1/payments?count=1', null, ['Authorization: Basic ' . base64_encode(Util::setting('integrations.razorpay_key_id') . ':' . Util::secret('razorpay_key_secret'))]);
                return $code === 200 ? ['ok' => true, 'message' => 'Razorpay keys work.'] : ['ok' => false, 'message' => $res['error']['description'] ?? "HTTP $code"];
            case 'ai':
                $r = \App\Services\Bot::reply($g, 'What are your timings?');
                return ['ok' => true, 'message' => 'Sample reply: ' . $r['reply']];
            default:
                throw HttpException::notFound();
        }
    }

    public function uploadLogo(Request $req): array
    {
        Auth::require('settings.manage');
        $path = Upload::store($this->gym(), $req->files['file'] ?? $req->input('file'), 1_000_000, ['image/png', 'image/jpeg', 'image/webp']);
        DB::update('gyms', ['logo_url' => '/api/public/logo/' . $this->gym() . '?v=' . time()], ['id' => $this->gym()]);
        $s = Util::gym()['settings']; $s['logo_path'] = $path; $this->saveSettings($s);
        return ['ok' => true];
    }

    public static function logo(Request $req): Response
    {
        $g = (int)$req->params['id'];
        $path = Util::setting('logo_path', null, $g);
        return Upload::stream($g, $path);
    }

    // ---- Branches ----

    public function saveBranch(Request $req): array
    {
        Auth::require('branches.manage');
        $d = V::check($req->json(), ['name' => 'required|string|max:120', 'address' => 'nullable|string|max:400', 'phone' => 'nullable|phone', 'open_time' => 'nullable|time', 'close_time' => 'nullable|time',
            'staffed_from' => 'nullable|time', 'staffed_to' => 'nullable|time', 'capacity' => 'nullable|int|min:1|max:5000', 'is_active' => 'nullable|bool']);
        if ($id = $req->id()) { $this->find('branches', $id, 'Branch'); DB::update('branches', $d, ['id' => $id, 'gym_id' => $this->gym()]); }
        else $id = DB::insert('branches', $d + ['gym_id' => $this->gym()]);
        Util::audit('branch.save', 'branch', $id);
        return ['id' => $id];
    }

    // ---- API keys & webhooks ----

    public function apiKeys(Request $req): array
    {
        Auth::require('settings.manage');
        return ['keys' => DB::all('SELECT id, name, key_prefix, last_used_at, created_at FROM api_keys WHERE gym_id = ?', [$this->gym()]),
            'webhooks' => DB::all('SELECT id, url, events, is_active, created_at, (SELECT COUNT(*) FROM webhook_events e WHERE e.webhook_id = w.id AND e.status = \'failed\') failed FROM webhooks w WHERE gym_id = ?', [$this->gym()]),
            'events' => \App\Services\Webhooks::EVENTS];
    }

    public function createApiKey(Request $req): array
    {
        Auth::require('settings.manage');
        $name = V::check($req->json(), ['name' => 'required|string|max:80'])['name'];
        $key = 'gk_' . Auth::newToken();
        DB::insert('api_keys', ['gym_id' => $this->gym(), 'name' => $name, 'key_prefix' => substr($key, 0, 8), 'key_hash' => hash('sha256', $key)]);
        Util::audit('apikey.create', null, null, $name);
        return ['key' => $key, 'note' => 'Copy this key now. It will not be shown again.'];
    }

    public function deleteApiKey(Request $req): array
    {
        Auth::require('settings.manage');
        DB::run('DELETE FROM api_keys WHERE id = ? AND gym_id = ?', [$req->id(), $this->gym()]);
        return ['ok' => true];
    }

    /** Block webhooks to private networks (SSRF). */
    public static function assertPublicUrl(string $url): void
    {
        if (!preg_match('#^https://#i', $url)) throw HttpException::bad('Use an https:// URL', ['url' => 'Use https://']);
        $host = parse_url($url, PHP_URL_HOST);
        $ips = $host ? (gethostbynamel($host) ?: []) : [];
        if (!$ips) throw HttpException::bad('That host could not be found', ['url' => 'Host not found']);
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw HttpException::bad('Private network addresses are not allowed', ['url' => 'Not allowed']);
        }
    }

    public function saveWebhook(Request $req): array
    {
        Auth::require('settings.manage');
        $d = V::check($req->json(), ['url' => 'required|url|max:300', 'events' => 'required|array', 'is_active' => 'nullable|bool']);
        self::assertPublicUrl($d['url']);
        $events = array_values(array_intersect((array)$d['events'], array_merge(\App\Services\Webhooks::EVENTS, ['*'])));
        if (!$events) throw HttpException::bad('Choose at least one event', ['events' => 'Required']);
        $row = ['url' => $d['url'], 'events' => implode(',', $events), 'is_active' => $d['is_active'] ?? 1];
        if ($id = $req->id()) { $this->find('webhooks', $id, 'Webhook'); DB::update('webhooks', $row, ['id' => $id]); return ['id' => $id]; }
        $secret = bin2hex(random_bytes(32));
        $id = DB::insert('webhooks', $row + ['gym_id' => $this->gym(), 'secret' => $secret]);
        return ['id' => $id, 'secret' => $secret, 'note' => 'Verify deliveries with HMAC-SHA256 of the body using this secret (header X-Gym-Signature).'];
    }

    public function deleteWebhook(Request $req): array
    {
        Auth::require('settings.manage');
        $w = $this->find('webhooks', $req->id(), 'Webhook');
        DB::run('DELETE FROM webhook_events WHERE webhook_id = ?', [$w['id']]);
        DB::run('DELETE FROM webhooks WHERE id = ?', [$w['id']]);
        return ['ok' => true];
    }

    public function audit(Request $req): array
    {
        Auth::require('settings.manage');
        return $this->paged("SELECT a.*, COALESCE(u.name, m.name) AS actor_name FROM audit_log a LEFT JOIN users u ON a.actor_type = 'staff' AND u.id = a.actor_id
            LEFT JOIN members m ON a.actor_type = 'member' AND m.id = a.actor_id WHERE a.gym_id = ? ORDER BY a.id DESC", [$this->gym()], $req);
    }

    // ---- Notifications & push ----

    public function notifications(Request $req): array
    {
        return ['items' => DB::all("SELECT * FROM notifications WHERE gym_id = ? AND audience = 'staff' ORDER BY id DESC LIMIT 50", [$this->gym()]),
            'unread' => (int)DB::val("SELECT COUNT(*) FROM notifications WHERE gym_id = ? AND audience = 'staff' AND read_at IS NULL", [$this->gym()])];
    }

    public function readNotifications(Request $req): array
    {
        DB::run("UPDATE notifications SET read_at = NOW() WHERE gym_id = ? AND audience = 'staff' AND read_at IS NULL", [$this->gym()]);
        return ['ok' => true];
    }

    public function pushSubscribe(Request $req): array
    {
        $sub = $req->input('subscription');
        $endpoint = is_array($sub) ? ($sub['endpoint'] ?? '') : '';
        if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 1000) throw HttpException::bad('Invalid subscription');
        DB::run('DELETE FROM staff_push_subscriptions WHERE user_id = ? AND endpoint = ?', [Auth::staffId(), $endpoint]);
        DB::insert('staff_push_subscriptions', ['user_id' => Auth::staffId(), 'endpoint' => $endpoint]);
        return ['ok' => true];
    }

    /** Backup: full JSON export of the gym's data (owner only). */
    public function backup(Request $req): Response
    {
        Auth::require('settings.manage');
        $g = $this->gym();
        $out = ['gym' => Util::gym(), 'exported_at' => date('c')];
        unset($out['gym']['settings']['integrations']);
        foreach (['branches', 'plans', 'members', 'memberships', 'invoices', 'payments', 'credit_notes', 'expenses', 'leads', 'classes', 'class_bookings', 'pt_appointments', 'products',
                     'attendance_sessions', 'assessments', 'workout_plans', 'diet_plans', 'coupons', 'gift_cards', 'campaigns', 'devices'] as $t) {
            $out[$t] = DB::all("SELECT * FROM `$t` WHERE gym_id = ?", [$g]);
        }
        $out['users'] = DB::all('SELECT id, name, email, phone, role, title, branch_id, salary, status, created_at FROM users WHERE gym_id = ?', [$g]);
        foreach ($out['members'] as &$m) unset($m['qr_secret'], $m['push_subscription'], $m['strava_tokens']);
        Util::audit('backup.download', 'gym', $g);
        return Response::file(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'application/json', 'gym-backup-' . date('Y-m-d') . '.json');
    }
}
