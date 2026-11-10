<?php
declare(strict_types=1);

namespace App\Core;

final class Middleware
{
    /**
     * opts:
     *  auth  => 'staff' (default) | 'member' | 'none' | 'platform'
     *  perm  => permission string required for staff routes
     *  csrf  => false to skip the custom-header check (webhooks, devices)
     *  rate  => [bucket, max, windowSeconds]
     *  apikey => true to also accept "Authorization: Bearer gk_..." API keys
     */
    public static function run(Request $req, array $opts): void
    {
        $auth = $opts['auth'] ?? 'staff';

        if (($opts['csrf'] ?? true) && !in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            self::csrf($req, (bool)($opts['apikey'] ?? false));
        }

        if (isset($opts['rate'])) {
            [$bucket, $max, $win] = $opts['rate'];
            RateLimit::hit($bucket . ':' . $req->ip(), $max, $win);
        }

        switch ($auth) {
            case 'none':
                return;
            case 'member':
                if (!Auth::loadMember()) throw HttpException::unauth();
                return;
            case 'platform':
                if (!Auth::loadStaff()) throw HttpException::unauth();
                Auth::require('platform');
                return;
            default:
                $ok = Auth::loadStaff();
                if (!$ok && ($opts['apikey'] ?? false)) {
                    $h = (string)$req->header('authorization');
                    if (str_starts_with($h, 'Bearer ')) $ok = Auth::loadApiKey(substr($h, 7));
                }
                if (!$ok) throw HttpException::unauth();
                if (Auth::role() === 'platform_admin') throw HttpException::forbidden('Platform admins use the platform console');
                if (isset($opts['perm'])) Auth::require($opts['perm']);
        }
    }

    /**
     * CSRF defence for cookie auth: browsers cannot send a custom header cross-site without a CORS
     * preflight, which this API never approves. Also reject a mismatching Origin outright.
     */
    private static function csrf(Request $req, bool $apiKeyAllowed): void
    {
        if ($apiKeyAllowed && str_starts_with((string)$req->header('authorization'), 'Bearer gk_')) return;
        if ($req->header('x-requested-with') !== 'gymapp') throw new HttpException(403, 'Missing request header', [], 'csrf');
        $origin = $req->header('origin');
        $app = Config::get('app_url');
        if ($origin && $app) {
            $o = parse_url($origin, PHP_URL_HOST);
            $a = parse_url($app, PHP_URL_HOST);
            if ($o !== $a && !in_array($origin, (array)Config::get('extra_origins', []), true)) throw new HttpException(403, 'Cross-origin request blocked', [], 'csrf');
        }
    }
}
