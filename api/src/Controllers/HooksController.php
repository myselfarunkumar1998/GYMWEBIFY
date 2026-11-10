<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Response, Util};
use App\Services\{Bot, Http, Notify, Razorpay, Webhooks};

/** Incoming webhooks from Razorpay, WhatsApp Cloud API, Meta Lead Ads and Google Ads lead forms. */
final class HooksController extends Base
{
    private function gymFor(Request $req): int
    {
        $id = $req->id();
        if (!DB::val("SELECT id FROM gyms WHERE id = ? AND status <> 'suspended'", [$id])) throw HttpException::notFound();
        Auth::$gymId = $id;
        return $id;
    }

    public function razorpay(Request $req): array
    {
        $g = $this->gymFor($req);
        return ['event' => Razorpay::webhook($g, $req->raw(), $req->header('x-razorpay-signature'))];
    }

    /** GET: Meta's verification handshake. POST: signed message/status notifications. */
    public function whatsapp(Request $req): Response
    {
        $g = $this->gymFor($req);
        if ($req->method === 'GET') {
            $token = Util::setting('integrations.whatsapp_verify_token');
            if ($token && $req->q('hub_mode') === 'subscribe' && hash_equals((string)$token, (string)$req->q('hub_verify_token'))) return Response::text((string)$req->q('hub_challenge'));
            return Response::text('Forbidden', 403);
        }
        $secret = Util::secret('whatsapp_app_secret');
        $sig = (string)$req->header('x-hub-signature-256');
        if (!$secret || !hash_equals('sha256=' . hash_hmac('sha256', $req->raw(), $secret), $sig)) return Response::text('Bad signature', 403);
        $body = json_decode($req->raw(), true) ?: [];
        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $ch) {
                $v = $ch['value'] ?? [];
                $names = [];
                foreach ($v['contacts'] ?? [] as $c) $names[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? null;
                foreach ($v['messages'] ?? [] as $msg) {
                    $text = $msg['text']['body'] ?? ($msg['button']['text'] ?? ($msg['interactive']['button_reply']['title'] ?? null));
                    if ($text === null) continue;
                    Bot::inbound($g, (string)($msg['from'] ?? ''), (string)$text, $msg['id'] ?? null, $names[$msg['from'] ?? ''] ?? null);
                }
                foreach ($v['statuses'] ?? [] as $st) {
                    $map = ['sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed'];
                    if (isset($map[$st['status'] ?? ''])) {
                        DB::run('UPDATE messages SET status = ?, error = ? WHERE provider_id = ? AND gym_id = ?', [$map[$st['status']], isset($st['errors'][0]['title']) ? mb_substr($st['errors'][0]['title'], 0, 250) : null, $st['id'] ?? '', $g]);
                    }
                }
            }
        }
        return Response::text('OK');
    }

    /** Meta Lead Ads: the webhook carries a leadgen_id; fetch the answers with the page token. */
    public function metaLeads(Request $req): Response
    {
        $g = $this->gymFor($req);
        if ($req->method === 'GET') {
            $token = Util::setting('integrations.meta_verify_token');
            if ($token && hash_equals((string)$token, (string)$req->q('hub_verify_token'))) return Response::text((string)$req->q('hub_challenge'));
            return Response::text('Forbidden', 403);
        }
        $secret = Util::secret('whatsapp_app_secret');
        if (!$secret || !hash_equals('sha256=' . hash_hmac('sha256', $req->raw(), $secret), (string)$req->header('x-hub-signature-256'))) return Response::text('Bad signature', 403);
        $pageToken = Util::secret('meta_page_token');
        foreach ((json_decode($req->raw(), true)['entry'] ?? []) as $e) {
            foreach ($e['changes'] ?? [] as $c) {
                $leadgen = $c['value']['leadgen_id'] ?? null;
                if (!$leadgen || !$pageToken) continue;
                [$code, $res] = Http::json('GET', 'https://graph.facebook.com/v21.0/' . rawurlencode((string)$leadgen) . '?access_token=' . rawurlencode($pageToken));
                if ($code !== 200) continue;
                $f = [];
                foreach ($res['field_data'] ?? [] as $fd) $f[strtolower($fd['name'])] = $fd['values'][0] ?? null;
                $this->createLead($g, $f['full_name'] ?? $f['name'] ?? 'Facebook lead', $f['phone_number'] ?? $f['phone'] ?? '', $f['email'] ?? null, ($c['field'] ?? '') === 'leadgen' ? 'facebook' : 'instagram', $f['what_is_your_goal?'] ?? null);
            }
        }
        return Response::text('OK');
    }

    /** Google Ads lead form webhook: authenticated by the google_key configured on the form. */
    public function googleLeads(Request $req): array
    {
        $g = $this->gymFor($req);
        $body = json_decode($req->raw(), true) ?: [];
        $key = Util::secret('google_lead_key');
        if (!$key || !hash_equals($key, (string)($body['google_key'] ?? ''))) throw HttpException::forbidden('Bad key');
        $f = [];
        foreach ($body['user_column_data'] ?? [] as $col) $f[$col['column_id'] ?? ''] = $col['string_value'] ?? null;
        $this->createLead($g, $f['FULL_NAME'] ?? 'Google lead', $f['PHONE_NUMBER'] ?? '', $f['EMAIL'] ?? null, 'google', null);
        return ['ok' => true];
    }

    private function createLead(int $g, string $name, string $phone, ?string $email, string $source, ?string $interest): void
    {
        $digits = substr(preg_replace('/\D/', '', $phone), -10);
        if (!preg_match('/^[6-9]\d{9}$/', $digits)) return;
        if (DB::val("SELECT id FROM leads WHERE gym_id = ? AND phone = ? AND created_at > NOW() - INTERVAL 30 DAY", [$g, $digits])) return;
        $id = DB::insert('leads', ['gym_id' => $g, 'name' => mb_substr($name, 0, 120), 'phone' => $digits, 'email' => $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'source' => $source, 'interest' => $interest ? mb_substr($interest, 0, 200) : null, 'next_followup_at' => date('Y-m-d H:i:s', time() + 1800)]);
        DB::insert('lead_activities', ['lead_id' => $id, 'kind' => 'ad_lead', 'note' => "Captured from $source ads"]);
        Notify::staff($g, "New $source lead: $name", null, 'leads', 'info', true);
        Webhooks::emit($g, 'lead.created', ['lead_id' => $id, 'source' => $source]);
    }
}
