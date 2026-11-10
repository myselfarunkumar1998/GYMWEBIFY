<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Config, DB, Util};

/**
 * WhatsApp enquiry bot. Answers plans, timings and location, and books free trials (creating a lead).
 * If the gym adds an Anthropic API key it uses Claude for natural replies, grounded in the gym's own facts;
 * otherwise it uses keyword rules. Anything it cannot handle is handed to staff.
 */
final class Bot
{
    public static function facts(int $gymId): string
    {
        $g = Util::gym($gymId);
        $plans = DB::all("SELECT name, price, duration_days FROM plans WHERE gym_id = ? AND is_active = 1 AND kind = 'membership' ORDER BY price", [$gymId]);
        $branches = DB::all('SELECT name, address, open_time, close_time FROM branches WHERE gym_id = ? AND is_active = 1', [$gymId]);
        $lines = ["Gym: {$g['name']}", 'Phone: ' . ($g['phone'] ?: 'n/a')];
        foreach ($branches as $b) $lines[] = "Branch {$b['name']}: {$b['address']}, open " . date('g:i A', strtotime($b['open_time'])) . ' – ' . date('g:i A', strtotime($b['close_time']));
        foreach ($plans as $p) $lines[] = "Plan {$p['name']}: ₹" . number_format((float)$p['price']) . " + GST for {$p['duration_days']} days";
        $lines[] = 'Free trial: ' . Util::setting('rules.trial_days', 3, $gymId) . ' days.';
        return implode("\n", $lines);
    }

    /** @return array{reply: string, intent: string} */
    public static function reply(int $gymId, string $text, string $lang = 'en'): array
    {
        $t = mb_strtolower(trim($text));
        $key = Util::secret('anthropic_key', $gymId) ?? Config::get('anthropic.api_key');
        if ($key) {
            $r = self::claude($gymId, $text, (string)$key, $lang);
            if ($r) return $r;
        }
        $ta = $lang === 'ta';
        if (preg_match('/\b(yes|ok|trial|free|book|join)\b|டிரையல்|சேர/u', $t)) {
            return ['reply' => $ta ? 'சரி! உங்கள் இலவச டிரையல் நாளையும் நேரத்தையும் அனுப்பவும் (எ.கா. "நாளை 7 AM"). எங்கள் குழு உறுதிப்படுத்தும்.' : 'Great! Send the day and time you would like for your free trial (for example "tomorrow 7 AM") and our team will confirm.', 'intent' => 'trial'];
        }
        if (preg_match('/price|fee|cost|plan|rate|charges|கட்டணம்|விலை/u', $t)) {
            $plans = DB::all("SELECT name, price FROM plans WHERE gym_id = ? AND is_active = 1 AND kind = 'membership' ORDER BY price LIMIT 6", [$gymId]);
            $list = implode("\n", array_map(fn($p) => "• {$p['name']}: ₹" . number_format((float)$p['price']) . ' + GST', $plans));
            return ['reply' => ($ta ? "எங்கள் திட்டங்கள்:\n" : "Our plans:\n") . $list . ($ta ? "\nஇலவச டிரையல் வேண்டுமா? YES என அனுப்பவும்." : "\nWant a free trial? Reply YES."), 'intent' => 'pricing'];
        }
        if (preg_match('/time|timing|open|close|hours|நேரம்/u', $t)) {
            $b = DB::all('SELECT name, open_time, close_time FROM branches WHERE gym_id = ? AND is_active = 1', [$gymId]);
            $list = implode("\n", array_map(fn($x) => "• {$x['name']}: " . date('g:i A', strtotime($x['open_time'])) . ' – ' . date('g:i A', strtotime($x['close_time'])), $b));
            return ['reply' => ($ta ? "திறந்திருக்கும் நேரம்:\n" : "Opening hours:\n") . $list, 'intent' => 'timings'];
        }
        if (preg_match('/where|address|location|map|எங்கே|முகவரி/u', $t)) {
            $b = DB::all('SELECT name, address FROM branches WHERE gym_id = ? AND is_active = 1', [$gymId]);
            return ['reply' => implode("\n", array_map(fn($x) => "• {$x['name']}: {$x['address']}", $b)), 'intent' => 'location'];
        }
        return ['reply' => $ta ? 'நன்றி! எங்கள் குழு விரைவில் பதில் அளிக்கும். விலை, நேரம் அல்லது இலவச டிரையல் பற்றி கேட்கலாம்.' : 'Thanks! Our team will reply shortly. You can also ask about prices, timings or a free trial.', 'intent' => 'handoff'];
    }

    private static function claude(int $gymId, string $text, string $key, string $lang): ?array
    {
        $system = "You are the WhatsApp assistant for a gym in India. Reply in " . ($lang === 'ta' ? 'Tamil' : 'the customer\'s language (English or Tamil)') .
            ", in at most 3 short sentences. Only use these facts; if something is not listed, say staff will reply. Encourage booking a free trial.\n" .
            "End your reply with one tag on its own line: [intent:pricing|timings|location|trial|handoff].\n\nFacts:\n" . self::facts($gymId);
        [$code, $res] = Http::json('POST', 'https://api.anthropic.com/v1/messages', [
            'model' => Config::get('anthropic.model', 'claude-haiku-5-5'), 'max_tokens' => 300, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => mb_substr($text, 0, 1000)]],
        ], ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01']);
        if ($code !== 200) return null;
        $out = trim((string)($res['content'][0]['text'] ?? ''));
        if ($out === '') return null;
        $intent = 'handoff';
        if (preg_match('/\[intent:([a-z]+)\]\s*$/', $out, $m)) { $intent = $m[1]; $out = trim(preg_replace('/\[intent:[a-z]+\]\s*$/', '', $out)); }
        return ['reply' => $out, 'intent' => $intent];
    }

    /** Handle one inbound WhatsApp text from the Cloud API webhook. */
    public static function inbound(int $gymId, string $fromPhone, string $text, ?string $providerId, ?string $profileName): void
    {
        $phone = substr(preg_replace('/\D/', '', $fromPhone), -10);
        if ($providerId && DB::val('SELECT id FROM messages WHERE provider_id = ?', [$providerId])) return; // duplicate delivery
        $member = DB::one('SELECT * FROM members WHERE gym_id = ? AND phone = ? AND deleted_at IS NULL', [$gymId, $phone]);
        $lead = $member ? null : DB::one("SELECT * FROM leads WHERE gym_id = ? AND phone = ? AND stage NOT IN ('joined','lost') ORDER BY id DESC LIMIT 1", [$gymId, $phone]);
        if (!$member && !$lead) {
            $leadId = DB::insert('leads', ['gym_id' => $gymId, 'name' => mb_substr($profileName ?: 'WhatsApp enquiry', 0, 120), 'phone' => $phone, 'source' => 'whatsapp_bot',
                'interest' => mb_substr($text, 0, 200), 'stage' => 'new', 'next_followup_at' => date('Y-m-d H:i:s', time() + 3600)]);
            $lead = DB::one('SELECT * FROM leads WHERE id = ?', [$leadId]);
            Webhooks::emit($gymId, 'lead.created', ['lead_id' => $leadId, 'source' => 'whatsapp_bot']);
        }
        DB::insert('messages', ['gym_id' => $gymId, 'direction' => 'in', 'channel' => 'whatsapp', 'to_addr' => $phone, 'body' => mb_substr($text, 0, 4000),
            'status' => 'received', 'provider_id' => $providerId, 'member_id' => $member['id'] ?? null, 'lead_id' => $lead['id'] ?? null]);
        if ($lead) DB::insert('lead_activities', ['lead_id' => $lead['id'], 'kind' => 'whatsapp_in', 'note' => mb_substr($text, 0, 500)]);

        if (!Util::setting('automations.whatsapp_bot', true, $gymId)) return;
        $lang = preg_match('/[\x{0B80}-\x{0BFF}]/u', $text) ? 'ta' : ($member['language'] ?? 'en');
        $r = self::reply($gymId, $text, $lang);
        Messenger::queue($gymId, 'whatsapp', $phone, $r['reply'], ['member_id' => $member['id'] ?? null, 'lead_id' => $lead['id'] ?? null, 'tkey' => 'bot']);
        if ($lead) {
            DB::insert('lead_activities', ['lead_id' => $lead['id'], 'kind' => 'bot_reply', 'note' => mb_substr($r['intent'] . ': ' . $r['reply'], 0, 500)]);
            if ($r['intent'] === 'trial' || $r['intent'] === 'handoff') {
                DB::update('leads', ['next_followup_at' => date('Y-m-d H:i:s', time() + 1800)], ['id' => $lead['id']]);
                Notify::staff($gymId, 'WhatsApp lead needs a reply: ' . $lead['name'], mb_substr($text, 0, 200), 'leads');
            }
        }
    }
}
