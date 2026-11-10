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
        $home = self::homeDir();
        if ($home) {
            // One config per site folder, kept above public_html so it is never web-reachable.
            $list[] = $home . '/gymapp-config-' . self::siteKey() . '.php';
        }
        $list[] = APP_ROOT . '/config.php';
        return $list;
    }

    /** The account home: the folder that contains public_html (e.g. /home2/cpuser). */
    public static function homeDir(): ?string
    {
        $d = APP_ROOT;
        while ($d !== '/' && $d !== '.' && basename($d) !== 'public_html') $d = dirname($d);
        return basename($d) === 'public_html' ? dirname($d) : null;
    }

    /** Short name of the site folder, so several installs on one account keep separate configs. */
    public static function siteKey(): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', basename(dirname(APP_ROOT))) ?: 'site';
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
