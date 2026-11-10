<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Config, DB, Util};

/**
 * Message queue for WhatsApp (Meta Cloud API), SMS (HTTP gateway), email and push.
 * Everything is queued first and sent by the cron dispatcher, so a slow provider never blocks a request.
 */
final class Messenger
{
    public const DEFAULTS = [
        'welcome' => ['en' => 'Welcome to {gym}, {name}! Your member ID is {code}. Download the app: {app_link}', 'ta' => '{gym}-க்கு வரவேற்கிறோம், {name}! உங்கள் உறுப்பினர் எண் {code}. செயலி: {app_link}'],
        'otp' => ['en' => '{code} is your {gym} login code. It expires in 10 minutes. Do not share it.', 'ta' => '{code} என்பது உங்கள் {gym} உள்நுழைவு குறியீடு. 10 நிமிடங்களில் காலாவதியாகும். பகிர வேண்டாம்.'],
        'payment_receipt' => ['en' => 'Hi {name}, we received ₹{amount} for invoice {invoice}. Balance: ₹{balance}. Thank you!', 'ta' => 'வணக்கம் {name}, {invoice} இன்வாய்ஸுக்கு ₹{amount} பெற்றோம். மீதம்: ₹{balance}. நன்றி!'],
        'renewal_reminder' => ['en' => 'Hi {name}, your {plan} plan at {gym} ends on {date}. Renew now: {link}', 'ta' => 'வணக்கம் {name}, {gym}-ல் உங்கள் {plan} திட்டம் {date} அன்று முடிகிறது. இப்போதே புதுப்பிக்க: {link}'],
        'dues_reminder' => ['en' => 'Hi {name}, ₹{amount} is pending on invoice {invoice}. Pay by UPI: {link}', 'ta' => 'வணக்கம் {name}, {invoice} இன்வாய்ஸில் ₹{amount} நிலுவையில் உள்ளது. UPI மூலம் செலுத்த: {link}'],
        'expired_winback' => ['en' => 'We miss you at {gym}, {name}! Come back this week and get {offer}. Renew: {link}', 'ta' => '{gym}-ல் உங்களை மிஸ் செய்கிறோம், {name}! இந்த வாரம் திரும்பி வந்தால் {offer}. புதுப்பிக்க: {link}'],
        'winback' => ['en' => 'Hi {name}, we have not seen you at {gym} for a while. Your trainer has a fresh plan ready. See you this week?', 'ta' => 'வணக்கம் {name}, {gym}-ல் சில நாட்களாக உங்களைக் காணவில்லை. உங்கள் பயிற்சியாளர் புதிய திட்டம் தயார் செய்துள்ளார். இந்த வாரம் வருவீர்களா?'],
        'birthday' => ['en' => 'Happy birthday, {name}! 🎂 Have a great year from all of us at {gym}.', 'ta' => 'பிறந்தநாள் வாழ்த்துக்கள், {name}! 🎂 {gym} குழுவினரின் அன்பான வாழ்த்துக்கள்.'],
        'class_reminder' => ['en' => 'Reminder: {class} at {time} today. See you at {gym}!', 'ta' => 'நினைவூட்டல்: இன்று {time} மணிக்கு {class}. {gym}-ல் சந்திப்போம்!'],
        'waitlist_promoted' => ['en' => 'Good news {name}! A spot opened in {class} on {date} at {time}. You are booked.', 'ta' => 'நல்ல செய்தி {name}! {date} {time} {class}-ல் இடம் கிடைத்தது. உங்கள் முன்பதிவு உறுதி.'],
        'trial_booked' => ['en' => 'Hi {name}, your free trial at {gym} is booked for {date}. Show this pass at the desk: {code}', 'ta' => 'வணக்கம் {name}, {gym}-ல் உங்கள் இலவச டிரையல் {date} அன்று பதிவு செய்யப்பட்டது. மேசையில் இந்த பாஸைக் காட்டவும்: {code}'],
        'lead_followup' => ['en' => 'Hi {name}, thanks for your interest in {gym}. Would you like to book a free trial this week? Reply YES and we will set it up.', 'ta' => 'வணக்கம் {name}, {gym} மீது ஆர்வம் காட்டியதற்கு நன்றி. இந்த வாரம் இலவச டிரையல் பதிவு செய்யலாமா? YES என பதில் அனுப்பவும்.'],
        'review_request' => ['en' => 'Hi {name}, enjoying {gym}? A quick Google review helps us a lot: {link}', 'ta' => 'வணக்கம் {name}, {gym} பிடித்திருக்கிறதா? ஒரு Google மதிப்பாய்வு எங்களுக்கு உதவும்: {link}'],
        'pt_low' => ['en' => 'Hi {name}, only {count} PT sessions left in your pack. Renew to keep your streak going.', 'ta' => 'வணக்கம் {name}, உங்கள் PT பேக்கில் {count} அமர்வுகள் மட்டுமே மீதம். தொடர புதுப்பிக்கவும்.'],
        'payment_link' => ['en' => 'Hi {name}, pay ₹{amount} for {invoice} securely by UPI or card: {link}', 'ta' => 'வணக்கம் {name}, {invoice}-க்கு ₹{amount} UPI அல்லது கார்டு மூலம் செலுத்த: {link}'],
    ];

    public static function seedTemplates(int $gymId): void
    {
        foreach (self::DEFAULTS as $key => $langs) {
            foreach ($langs as $lang => $body) {
                DB::run('INSERT IGNORE INTO message_templates (gym_id, tkey, language, body) VALUES (?,?,?,?)', [$gymId, $key, $lang, $body]);
            }
        }
    }

    public static function render(string $body, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0], $body);
    }

    /** Render a gym template in the member's language and queue it on WhatsApp. */
    public static function template(int $gymId, string $tkey, ?array $member, array $vars = [], array $opt = []): ?int
    {
        $phone = $opt['phone'] ?? ($member['phone'] ?? null);
        if (!$phone) return null;
        $auto = Util::setting('automations.' . $tkey, true, $gymId);
        if ($auto === false && empty($opt['force'])) return null;
        $lang = $opt['language'] ?? ($member['language'] ?? Util::gym($gymId)['default_language']);
        $t = DB::one('SELECT * FROM message_templates WHERE gym_id = ? AND tkey = ? AND language = ? AND is_active = 1', [$gymId, $tkey, $lang])
            ?? DB::one('SELECT * FROM message_templates WHERE gym_id = ? AND tkey = ? AND is_active = 1 LIMIT 1', [$gymId, $tkey]);
        $bodyTpl = $t['body'] ?? (self::DEFAULTS[$tkey][$lang] ?? self::DEFAULTS[$tkey]['en'] ?? null);
        if (!$bodyTpl) return null;
        $gym = Util::gym($gymId);
        $vars += ['gym' => $gym['name'], 'name' => $member ? strtok($member['name'], ' ') : '', 'code' => $member['member_code'] ?? '',
            'app_link' => rtrim((string)Config::get('app_url'), '/') . '/m/' . $gym['slug']];
        $payload = null;
        if (!empty($t['wa_template_name'])) {
            preg_match_all('/\{([a-z_]+)\}/', $bodyTpl, $mm);
            $payload = ['template' => $t['wa_template_name'], 'language' => $lang === 'ta' ? 'ta' : 'en', 'params' => array_map(fn($k) => (string)($vars[$k] ?? ''), $mm[1])];
        }
        return self::queue($gymId, 'whatsapp', $phone, self::render($bodyTpl, $vars), [
            'member_id' => $member['id'] ?? null, 'lead_id' => $opt['lead_id'] ?? null, 'tkey' => $tkey, 'payload' => $payload,
            'campaign_id' => $opt['campaign_id'] ?? null, 'send_after' => $opt['send_after'] ?? null,
        ]);
    }

    public static function queue(int $gymId, string $channel, string $to, string $body, array $meta = []): int
    {
        return DB::insert('messages', [
            'gym_id' => $gymId, 'channel' => $channel, 'to_addr' => mb_substr($to, 0, 150), 'body' => $body,
            'member_id' => $meta['member_id'] ?? null, 'lead_id' => $meta['lead_id'] ?? null, 'user_id' => $meta['user_id'] ?? null,
            'campaign_id' => $meta['campaign_id'] ?? null, 'tkey' => $meta['tkey'] ?? null,
            'payload' => isset($meta['payload']) ? json_encode($meta['payload'], JSON_UNESCAPED_UNICODE) : null,
            'send_after' => $meta['send_after'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /** Send queued messages. Called by cron and, for OTP, immediately. */
    public static function dispatch(int $limit = 100, ?int $onlyId = null): int
    {
        $rows = $onlyId
            ? DB::all("SELECT * FROM messages WHERE id = ? AND status = 'queued'", [$onlyId])
            : DB::all("SELECT * FROM messages WHERE status = 'queued' AND direction = 'out' AND send_after <= NOW() AND attempts < 3 ORDER BY id LIMIT " . (int)$limit);
        $sent = 0;
        foreach ($rows as $m) {
            DB::run('UPDATE messages SET attempts = attempts + 1 WHERE id = ?', [$m['id']]);
            try {
                [$ok, $providerId, $err] = match ($m['channel']) {
                    'whatsapp' => self::sendWhatsApp($m),
                    'sms' => self::sendSms($m),
                    'email' => self::sendEmail($m),
                    'push' => [Push::send($m['to_addr']), null, 'Push failed'],
                };
            } catch (\Throwable $e) {
                [$ok, $providerId, $err] = [false, null, mb_substr($e->getMessage(), 0, 250)];
            }
            if ($ok) {
                DB::run("UPDATE messages SET status = 'sent', sent_at = NOW(), provider_id = ?, error = NULL WHERE id = ?", [$providerId, $m['id']]);
                $sent++;
            } else {
                $final = (int)$m['attempts'] + 1 >= 3 || str_contains((string)$err, 'not connected');
                DB::run('UPDATE messages SET status = ?, error = ?, send_after = NOW() + INTERVAL 10 MINUTE WHERE id = ?', [$final ? 'failed' : 'queued', mb_substr((string)$err, 0, 250), $m['id']]);
            }
        }
        return $sent;
    }

    public static function whatsappConfigured(int $gymId): bool
    {
        return (bool)Util::secret('whatsapp_token', $gymId) && (bool)Util::setting('integrations.whatsapp_phone_id', null, $gymId);
    }

    /** @return array{0: bool, 1: ?string, 2: ?string} */
    private static function sendWhatsApp(array $m): array
    {
        $gymId = (int)$m['gym_id'];
        if (!self::whatsappConfigured($gymId)) {
            if (Util::isDemoGym($gymId)) return [true, 'simulated', null];
            return [false, null, 'WhatsApp is not connected'];
        }
        $to = '91' . substr(preg_replace('/\D/', '', $m['to_addr']), -10);
        $payload = $m['payload'] ? json_decode($m['payload'], true) : null;
        $data = ['messaging_product' => 'whatsapp', 'to' => $to];
        if ($payload && !empty($payload['template'])) {
            $data['type'] = 'template';
            $data['template'] = ['name' => $payload['template'], 'language' => ['code' => $payload['language']],
                'components' => [['type' => 'body', 'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => $p], $payload['params'])]]];
        } else {
            $data['type'] = 'text';
            $data['text'] = ['body' => $m['body'], 'preview_url' => true];
        }
        $phoneId = Util::setting('integrations.whatsapp_phone_id', null, $gymId);
        [$code, $res] = Http::json('POST', "https://graph.facebook.com/v21.0/$phoneId/messages", $data, ['Authorization: Bearer ' . Util::secret('whatsapp_token', $gymId)]);
        if ($code >= 200 && $code < 300) return [true, $res['messages'][0]['id'] ?? null, null];
        return [false, null, 'WhatsApp error: ' . ($res['error']['message'] ?? "HTTP $code")];
    }

    /** Generic SMS gateway: URL template with {to} and {msg} placeholders (works with MSG91, Fast2SMS, etc.). */
    private static function sendSms(array $m): array
    {
        $tpl = Util::setting('integrations.sms_url', null, (int)$m['gym_id']);
        if (!$tpl) return Util::isDemoGym((int)$m['gym_id']) ? [true, 'simulated', null] : [false, null, 'SMS gateway is not connected'];
        $key = Util::secret('sms_key', (int)$m['gym_id']) ?? '';
        $url = strtr($tpl, ['{to}' => rawurlencode('91' . substr($m['to_addr'], -10)), '{msg}' => rawurlencode($m['body']), '{key}' => rawurlencode($key)]);
        [$code, $body] = Http::request('GET', $url);
        return $code >= 200 && $code < 300 ? [true, null, null] : [false, null, "SMS gateway HTTP $code"];
    }

    private static function sendEmail(array $m): array
    {
        if (!filter_var($m['to_addr'], FILTER_VALIDATE_EMAIL)) return [false, null, 'Invalid email'];
        $gym = Util::gym((int)$m['gym_id']);
        $from = Config::get('mail_from', 'no-reply@' . (parse_url((string)Config::get('app_url'), PHP_URL_HOST) ?: 'localhost'));
        $payload = $m['payload'] ? json_decode($m['payload'], true) : [];
        $subject = $payload['subject'] ?? $gym['name'];
        $headers = 'From: ' . mb_encode_mimeheader($gym['name']) . " <$from>\r\nReply-To: " . ($gym['email'] ?: $from) . "\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
        if (Util::isDemoGym((int)$m['gym_id']) && !Config::get('mail_enabled')) return [true, 'simulated', null];
        $ok = mail($m['to_addr'], mb_encode_mimeheader($subject), $m['body'], $headers);
        return [$ok, null, $ok ? null : 'mail() failed'];
    }
}
