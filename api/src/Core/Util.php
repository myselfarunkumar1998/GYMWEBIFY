<?php
declare(strict_types=1);

namespace App\Core;

final class Util
{
    public static function audit(string $action, ?string $entity = null, ?int $entityId = null, array|string|null $details = null): void
    {
        $actorType = Auth::$staff ? 'staff' : (Auth::$member ? 'member' : 'system');
        $actorId = Auth::$staff['id'] ?? Auth::$member['id'] ?? null;
        DB::insert('audit_log', [
            'gym_id' => Auth::$gymId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'details' => is_array($details) ? substr(json_encode($details, JSON_UNESCAPED_UNICODE), 0, 1000) : $details,
            'ip' => (($h = Config::get('client_ip_header')) && !empty($_SERVER[$h])) ? substr((string)$_SERVER[$h], 0, 45) : ($_SERVER['REMOTE_ADDR'] ?? null),
        ]);
    }

    public static function money(float $v): float { return round($v + 0.0, 2); }

    public static function today(): string { return date('Y-m-d'); }

    public static function addDays(string $date, int $days): string
    {
        return date('Y-m-d', strtotime(sprintf('%s %+d days', $date, $days)));
    }

    public static function daysBetween(string $a, string $b): int
    {
        return (int)round((strtotime($b) - strtotime($a)) / 86400);
    }

    private static array $gymCache = [];

    public static function gym(?int $id = null): array
    {
        $id ??= Auth::gymId();
        if (!isset(self::$gymCache[$id])) {
            $g = DB::one('SELECT * FROM gyms WHERE id = ?', [$id]);
            if (!$g) throw HttpException::notFound('Gym not found');
            $g['settings'] = json_decode($g['settings'] ?? '{}', true) ?: [];
            self::$gymCache[$id] = $g;
        }
        return self::$gymCache[$id];
    }

    public static function forgetGym(): void { self::$gymCache = []; }

    /**
     * Demo behaviour (on-screen OTP, simulated WhatsApp, demo payments) is allowed only for a gym
     * marked as a demo AND when the site config allows demo mode. Real gyms never get it.
     */
    public static function isDemoGym(?int $gymId = null): bool
    {
        if (!Config::get('demo_mode')) return false;
        try { return !empty(self::gym($gymId)['settings']['is_demo']); } catch (\Throwable) { return false; }
    }

    public static function setting(string $key, mixed $default = null, ?int $gymId = null): mixed
    {
        $s = self::gym($gymId)['settings'];
        foreach (explode('.', $key) as $k) {
            if (!is_array($s) || !array_key_exists($k, $s)) return $default;
            $s = $s[$k];
        }
        return $s;
    }

    /** Encrypt integration secrets at rest with the app key (libsodium). */
    public static function encrypt(string $plain): string
    {
        $key = sodium_crypto_generichash(Config::get('app_key'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    public static function decrypt(?string $enc): ?string
    {
        if (!$enc || !str_starts_with($enc, 'enc:')) return $enc;
        $raw = base64_decode(substr($enc, 4), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $key = sodium_crypto_generichash(Config::get('app_key'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        return $p === false ? null : $p;
    }

    /** Gym integration secret, decrypted. */
    public static function secret(string $key, ?int $gymId = null): ?string
    {
        $v = self::setting('integrations.' . $key, null, $gymId);
        return $v ? self::decrypt($v) : null;
    }

    public static function randomCode(int $len = 8, string $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
    {
        $s = '';
        for ($i = 0; $i < $len; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $s;
    }

    public static function maskPhone(string $p): string
    {
        return strlen($p) >= 10 ? substr($p, 0, 2) . 'XXX XX' . substr($p, -3) : $p;
    }

    public static function csv(array $rows, array $headers): string
    {
        $f = fopen('php://temp', 'r+');
        fputcsv($f, $headers);
        foreach ($rows as $r) {
            // Neutralise spreadsheet formula injection.
            $vals = array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v) ? "'" . $v : $v, array_values($r));
            fputcsv($f, $vals);
        }
        rewind($f);
        $s = stream_get_contents($f);
        fclose($f);
        return "\xEF\xBB\xBF" . $s;
    }

    public static function minutesLabel(?int $m): string
    {
        if ($m === null) return '—';
        return $m >= 60 ? intdiv($m, 60) . 'h ' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT) . 'm' : $m . 'm';
    }
}
