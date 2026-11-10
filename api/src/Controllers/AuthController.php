<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Config, DB, HttpException, RateLimit, Request, Response, Util, V};
use App\Services\Messenger;

final class AuthController extends Base
{
    public function login(Request $req): array
    {
        $d = V::check($req->json(), ['email' => 'required|email|max:150', 'password' => 'required|string|max:200']);
        RateLimit::hit('login-email:' . $d['email'], 10, 900);
        $u = DB::one('SELECT * FROM users WHERE email = ?', [$d['email']]);
        // Same work and the same message whether or not the account exists.
        $hash = $u['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$WG9sc1kuaG1XcC42UTdyVg$oUacxRsyRE2GvihY5RKPrMGLk9lRZfl7h2DSpGJNgB0';
        $ok = password_verify($d['password'], $hash);
        if (!$u || $u['status'] !== 'active') throw new HttpException(401, 'Email or password is wrong', [], 'bad_credentials');
        if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
            throw new HttpException(423, 'Account locked after too many attempts. Try again after ' . date('g:i A', strtotime($u['locked_until'])) . ' or reset your password.', [], 'locked');
        }
        if (!$ok) {
            $fails = (int)$u['failed_logins'] + 1;
            DB::update('users', ['failed_logins' => $fails, 'locked_until' => $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null], ['id' => $u['id']]);
            Auth::$gymId = $u['gym_id'] ? (int)$u['gym_id'] : null;
            Util::audit('auth.failed', 'user', (int)$u['id']);
            throw new HttpException(401, 'Email or password is wrong', [], 'bad_credentials');
        }
        if ($u['gym_id']) {
            $g = DB::one('SELECT status FROM gyms WHERE id = ?', [$u['gym_id']]);
            if ($g['status'] === 'suspended') throw HttpException::forbidden('This gym account is suspended. Contact support.');
        }
        if (password_needs_rehash($u['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            DB::update('users', ['password_hash' => Auth::hashPassword($d['password'])], ['id' => $u['id']]);
        }
        DB::update('users', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')], ['id' => $u['id']]);
        Auth::startSession('staff', (int)$u['id'], $u['gym_id'] ? (int)$u['gym_id'] : null, $req);
        Auth::$gymId = $u['gym_id'] ? (int)$u['gym_id'] : null;
        Auth::$staff = $u;
        Util::audit('auth.login', 'user', (int)$u['id']);
        return $this->me($req);
    }

    public function logout(Request $req): Response
    {
        Auth::endSession('staff');
        return Response::ok();
    }

    public function me(Request $req): array
    {
        $u = Auth::$staff ?? Auth::loadStaff();
        if (!$u) throw HttpException::unauth();
        $gym = $u['gym_id'] ? Util::gym((int)$u['gym_id']) : null;
        $perms = Auth::PERMS[$u['role']] ?? [];
        return [
            'user' => ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'branch_id' => $u['branch_id'] ? (int)$u['branch_id'] : null, 'language' => $u['language'] ?? 'en'],
            'permissions' => $perms,
            'gym' => $gym ? ['id' => (int)$gym['id'], 'name' => $gym['name'], 'slug' => $gym['slug'], 'logo_url' => $gym['logo_url'], 'gstin' => $gym['gstin'], 'default_language' => $gym['default_language'], 'status' => $gym['status']] : null,
            'branches' => $gym ? DB::all('SELECT id, name, capacity FROM branches WHERE gym_id = ? AND is_active = 1 ORDER BY id', [$gym['id']]) : [],
            'rules' => $gym ? ['joining_fee' => (float)($gym['settings']['rules']['joining_fee'] ?? 0), 'prices_include_gst' => (bool)($gym['settings']['prices_include_gst'] ?? false),
                'grace_days' => (int)($gym['settings']['rules']['grace_days'] ?? 3), 'freeze_min_days' => (int)($gym['settings']['rules']['freeze_min_days'] ?? 7), 'waiver_text' => $gym['settings']['waiver_text'] ?? ''] : null,
            'demo_mode' => (bool)Config::get('demo_mode'),
            'vapid_public' => Config::get('vapid.public'),
        ];
    }

    public function changePassword(Request $req): Response
    {
        $d = V::check($req->json(), ['current' => 'required|string', 'password' => 'required|string|max:200']);
        $u = DB::one('SELECT * FROM users WHERE id = ?', [Auth::staffId()]);
        if (!password_verify($d['current'], $u['password_hash'])) throw HttpException::bad('Current password is wrong', ['current' => 'Wrong password']);
        if ($err = Auth::checkPasswordStrength($d['password'])) throw HttpException::bad($err, ['password' => $err]);
        DB::update('users', ['password_hash' => Auth::hashPassword($d['password'])], ['id' => $u['id']]);
        Auth::endAllSessions('staff', (int)$u['id']);
        Auth::startSession('staff', (int)$u['id'], $u['gym_id'] ? (int)$u['gym_id'] : null, $req);
        Util::audit('auth.password_changed', 'user', (int)$u['id']);
        return Response::ok();
    }

    public function updateProfile(Request $req): Response
    {
        $d = V::check($req->json(), ['name' => 'string|max:120', 'phone' => 'nullable|phone', 'language' => 'in:en,ta']);
        DB::update('users', $d, ['id' => Auth::staffId()]);
        return Response::ok();
    }

    public function forgot(Request $req): Response
    {
        $d = V::check($req->json(), ['email' => 'required|email']);
        RateLimit::hit('forgot-email:' . $d['email'], 3, 3600);
        $u = DB::one("SELECT * FROM users WHERE email = ? AND status = 'active'", [$d['email']]);
        if ($u) {
            $token = Auth::newToken();
            DB::insert('password_resets', ['user_id' => $u['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
            $link = rtrim((string)Config::get('app_url'), '/') . '/reset-password?token=' . $token;
            $gymId = (int)($u['gym_id'] ?? 0);
            if ($gymId) {
                Messenger::queue($gymId, 'email', $u['email'], "Hi {$u['name']},\n\nUse this link within 1 hour to set a new password:\n$link\n\nIf you did not ask for this, ignore this email.", ['user_id' => (int)$u['id'], 'payload' => ['subject' => 'Reset your password']]);
                Messenger::dispatch(1, (int)DB::pdo()->lastInsertId());
            }
        }
        return Response::json(['sent' => true]); // never reveal whether the email exists
    }

    public function reset(Request $req): Response
    {
        $d = V::check($req->json(), ['token' => 'required|string|max:100', 'password' => 'required|string|max:200']);
        if ($err = Auth::checkPasswordStrength($d['password'])) throw HttpException::bad($err, ['password' => $err]);
        $r = DB::one('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $d['token'])]);
        if (!$r) throw HttpException::bad('This reset link has expired. Ask for a new one.');
        DB::update('users', ['password_hash' => Auth::hashPassword($d['password']), 'failed_logins' => 0, 'locked_until' => null], ['id' => $r['user_id']]);
        DB::run('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$r['id']]);
        Auth::endAllSessions('staff', (int)$r['user_id']);
        return Response::ok();
    }
}
