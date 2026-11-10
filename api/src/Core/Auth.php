<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cookie sessions. The cookie carries a 256-bit random token; only its SHA-256 is stored.
 * Staff and members use separate cookies so an owner can test the member app on the same device.
 */
final class Auth
{
    public const STAFF_COOKIE = 'gs_staff';
    public const MEMBER_COOKIE = 'gs_member';

    public static ?array $staff = null;
    public static ?array $member = null;
    public static ?int $gymId = null;
    public static ?int $apiKeyId = null;

    /** Test hook: cookies supplied by the in-process test client. */
    public static array $cookieJar = [];

    public const PERMS = [
        'owner' => ['*'],
        'manager' => ['dashboard.view', 'members.view', 'members.write', 'members.delete', 'plans.manage', 'billing.view', 'billing.collect',
            'billing.manage', 'sessions.view', 'sessions.manage', 'leads.view', 'leads.write', 'classes.view', 'classes.manage', 'pt.manage',
            'staff.view', 'store.view', 'store.sell', 'store.manage', 'campaigns.view', 'campaigns.send', 'reports.view', 'reports.finance',
            'devices.manage', 'settings.view', 'workouts.manage', 'insights.view'],
        'front_desk' => ['dashboard.view', 'members.view', 'members.write', 'billing.view', 'billing.collect', 'sessions.view', 'sessions.manage',
            'leads.view', 'leads.write', 'classes.view', 'pt.manage', 'store.view', 'store.sell'],
        'trainer' => ['dashboard.view', 'members.view', 'sessions.view', 'classes.view', 'pt.manage', 'workouts.manage', 'leads.view'],
        'staff' => ['dashboard.view'],
        'platform_admin' => ['platform'],
    ];

    public static function reset(): void
    {
        self::$staff = self::$member = null;
        self::$gymId = self::$apiKeyId = null;
    }

    private static function cookie(string $name): ?string
    {
        $v = Response::$testMode ? (self::$cookieJar[$name] ?? null) : ($_COOKIE[$name] ?? null);
        return is_string($v) && preg_match('/^[A-Za-z0-9_-]{43}$/', $v) ? $v : null;
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function startSession(string $type, int $subjectId, ?int $gymId, Request $req): void
    {
        $token = self::newToken();
        $days = $type === 'member' ? 60 : 7;
        DB::insert('auth_sessions', [
            'token_hash' => hash('sha256', $token),
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'gym_id' => $gymId,
            'ip' => $req->ip(),
            'user_agent' => $req->userAgent(),
            'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
        ]);
        Response::setCookie($type === 'member' ? self::MEMBER_COOKIE : self::STAFF_COOKIE, $token, time() + $days * 86400);
    }

    public static function endSession(string $type): void
    {
        $name = $type === 'member' ? self::MEMBER_COOKIE : self::STAFF_COOKIE;
        $t = self::cookie($name);
        if ($t) DB::run('DELETE FROM auth_sessions WHERE token_hash = ?', [hash('sha256', $t)]);
        Response::setCookie($name, '', time() - 3600);
    }

    public static function endAllSessions(string $type, int $subjectId): void
    {
        DB::run('DELETE FROM auth_sessions WHERE subject_type = ? AND subject_id = ?', [$type, $subjectId]);
    }

    private static function lookup(string $type): ?array
    {
        $t = self::cookie($type === 'member' ? self::MEMBER_COOKIE : self::STAFF_COOKIE);
        if (!$t) return null;
        $s = DB::one('SELECT * FROM auth_sessions WHERE token_hash = ? AND subject_type = ? AND expires_at > NOW()', [hash('sha256', $t), $type]);
        if (!$s) return null;
        if (strtotime($s['last_seen_at']) < time() - 300) {
            DB::run('UPDATE auth_sessions SET last_seen_at = NOW() WHERE id = ?', [$s['id']]);
        }
        return $s;
    }

    public static function loadStaff(): ?array
    {
        $s = self::lookup('staff');
        if (!$s) return null;
        $u = DB::one("SELECT u.*, g.status AS gym_status FROM users u LEFT JOIN gyms g ON g.id = u.gym_id WHERE u.id = ? AND u.status = 'active'", [$s['subject_id']]);
        if (!$u) return null;
        if ($u['role'] !== 'platform_admin' && $u['gym_status'] === 'suspended') throw HttpException::forbidden('This gym account is suspended. Contact support.');
        unset($u['password_hash']);
        self::$staff = $u;
        self::$gymId = $u['gym_id'] !== null ? (int)$u['gym_id'] : null;
        return $u;
    }

    public static function loadMember(): ?array
    {
        $s = self::lookup('member');
        if (!$s) return null;
        $m = DB::one('SELECT m.* FROM members m JOIN gyms g ON g.id = m.gym_id WHERE m.id = ? AND m.deleted_at IS NULL AND g.status <> \'suspended\'', [$s['subject_id']]);
        if (!$m) return null;
        self::$member = $m;
        self::$gymId = (int)$m['gym_id'];
        return $m;
    }

    public static function loadApiKey(string $key): bool
    {
        if (!preg_match('/^gk_[A-Za-z0-9_-]{40,}$/', $key)) return false;
        $row = DB::one('SELECT * FROM api_keys WHERE key_hash = ?', [hash('sha256', $key)]);
        if (!$row) return false;
        DB::run('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?', [$row['id']]);
        self::$gymId = (int)$row['gym_id'];
        self::$apiKeyId = (int)$row['id'];
        // API keys act with manager-level read/write on members, billing and sessions.
        self::$staff = ['id' => null, 'gym_id' => $row['gym_id'], 'role' => 'manager', 'branch_id' => null, 'name' => 'API: ' . $row['name']];
        return true;
    }

    public static function can(string $perm): bool
    {
        if (!self::$staff) return false;
        $perms = self::PERMS[self::$staff['role']] ?? [];
        return in_array('*', $perms, true) ? $perm !== 'platform' : in_array($perm, $perms, true);
    }

    public static function require(string $perm): void
    {
        if (!self::can($perm)) throw HttpException::forbidden();
    }

    public static function gymId(): int
    {
        if (self::$gymId === null) throw HttpException::unauth();
        return self::$gymId;
    }

    public static function staffId(): ?int { return isset(self::$staff['id']) ? (int)self::$staff['id'] : null; }
    public static function role(): ?string { return self::$staff['role'] ?? null; }

    /** Staff tied to one branch only see that branch; owners and unassigned managers see all. */
    public static function branchLimit(): ?int
    {
        if (!self::$staff || self::$staff['role'] === 'owner') return null;
        return self::$staff['branch_id'] !== null ? (int)self::$staff['branch_id'] : null;
    }

    public static function hashPassword(string $p): string
    {
        return password_hash($p, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    public static function checkPasswordStrength(string $p): ?string
    {
        if (strlen($p) < 10) return 'Use at least 10 characters';
        if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/[0-9]/', $p)) return 'Use letters and numbers';
        return null;
    }
}
