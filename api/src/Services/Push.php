<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Web Push without a payload: the service worker wakes up and fetches the latest notification
 * from the API. That avoids payload encryption while still using standard VAPID authentication.
 */
final class Push
{
    public static function enabled(): bool
    {
        return (bool)Config::get('vapid.private_pem') && (bool)Config::get('vapid.public');
    }

    public static function send(string $subscriptionJson): bool
    {
        if (!self::enabled()) return false;
        $sub = json_decode($subscriptionJson, true);
        $endpoint = is_array($sub) ? ($sub['endpoint'] ?? null) : $subscriptionJson;
        if (!$endpoint || !preg_match('#^https://#', $endpoint)) return false;
        $aud = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $jwt = self::vapidJwt($aud);
        if (!$jwt) return false;
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['TTL: 86400', 'Content-Length: 0', 'Urgency: normal', 'Authorization: vapid t=' . $jwt . ', k=' . Config::get('vapid.public')],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    private static function vapidJwt(string $aud): ?string
    {
        $h = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $p = self::b64(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => Config::get('vapid.subject', 'mailto:admin@example.com')]));
        $key = openssl_pkey_get_private((string)Config::get('vapid.private_pem'));
        if (!$key || !openssl_sign("$h.$p", $der, $key, OPENSSL_ALGO_SHA256)) return null;
        return "$h.$p." . self::b64(self::derToRaw($der));
    }

    /** Convert an ASN.1 DER ECDSA signature to the 64-byte r||s form JWT expects. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $v = substr($der, $pos + 2, $len);
            $parts[] = str_pad(ltrim($v, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $parts[0] . $parts[1];
    }

    /** Generate a VAPID key pair (used by the installer). */
    public static function generateKeys(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($k, $pem);
        $d = openssl_pkey_get_details($k);
        $pub = "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        return ['public' => self::b64($pub), 'private_pem' => $pem];
    }
}
