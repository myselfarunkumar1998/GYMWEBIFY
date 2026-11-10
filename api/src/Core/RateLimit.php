<?php
declare(strict_types=1);

namespace App\Core;

/** Fixed-window limiter stored in MySQL (shared hosting has no Redis). */
final class RateLimit
{
    public static bool $disabled = false;

    public static function hit(string $key, int $max, int $window): void
    {
        if (self::$disabled) return;
        $key = substr($key, 0, 190);
        $now = time();
        $row = DB::one('SELECT hits, window_start FROM rate_limits WHERE rl_key = ?', [$key]);
        if (!$row || $row['window_start'] + $window <= $now) {
            DB::run('REPLACE INTO rate_limits (rl_key, hits, window_start) VALUES (?, 1, ?)', [$key, $now]);
            return;
        }
        if ((int)$row['hits'] >= $max) {
            $retry = $row['window_start'] + $window - $now;
            throw new HttpException(429, "Too many attempts. Try again in " . max(1, (int)ceil($retry / 60)) . " minute(s).", [], 'rate_limited');
        }
        DB::run('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
    }

    public static function clear(string $key): void
    {
        DB::run('DELETE FROM rate_limits WHERE rl_key = ?', [substr($key, 0, 190)]);
    }

    public static function gc(): void
    {
        DB::run('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
    }
}
