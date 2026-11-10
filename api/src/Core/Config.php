<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $c = [];
    private static ?string $path = null;

    /** Config lives outside the web root when possible: ~/gymapp-config.php next to public_html. */
    public static function candidates(): array
    {
        $env = getenv('GYMAPP_CONFIG');
        $list = [];
        if ($env) $list[] = $env;
        $list[] = dirname(APP_ROOT, 2) . '/gymapp-config.php'; // ~/gymapp-config.php when api is public_html/api
        $list[] = APP_ROOT . '/config.php';
        return $list;
    }

    public static function load(): void
    {
        foreach (self::candidates() as $p) {
            if (is_file($p)) {
                self::$c = require $p;
                self::$path = $p;
                return;
            }
        }
        self::$c = [];
    }

    public static function isInstalled(): bool
    {
        return self::$path !== null && !empty(self::$c['db']['name']);
    }

    public static function path(): ?string { return self::$path; }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cur = self::$c;
        foreach (explode('.', $key) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
            $cur = $cur[$k];
        }
        return $cur;
    }

    public static function set(array $c): void { self::$c = $c; }
}
