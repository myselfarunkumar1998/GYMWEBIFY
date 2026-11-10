<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Util, V};
use App\Services\Provision;

/** Platform console for the software owner: create and manage gym accounts (SaaS tenants). */
final class PlatformController extends Base
{
    public function gyms(Request $req): array
    {
        return DB::all("SELECT g.id, g.name, g.slug, g.status, g.subscription_plan, g.subscription_until, g.created_at, g.phone, g.email,
            (SELECT COUNT(*) FROM members m WHERE m.gym_id = g.id AND m.deleted_at IS NULL) members,
            (SELECT COUNT(*) FROM branches b WHERE b.gym_id = g.id) branches,
            (SELECT MAX(s.in_at) FROM attendance_sessions s WHERE s.gym_id = g.id) last_checkin,
            (SELECT u.email FROM users u WHERE u.gym_id = g.id AND u.role = 'owner' LIMIT 1) owner_email
            FROM gyms g ORDER BY g.id DESC");
    }

    public function create(Request $req): array
    {
        $d = V::check($req->json(), ['name' => 'required|string|max:150', 'phone' => 'nullable|phone', 'address' => 'nullable|string|max:400', 'gstin' => 'nullable|gstin',
            'owner_name' => 'required|string|max:120', 'owner_email' => 'required|email', 'owner_password' => 'required|string|max:200',
            'subscription_plan' => 'nullable|in:starter,standard,multi_branch', 'subscription_until' => 'nullable|date', 'default_language' => 'nullable|in:en,ta']);
        if ($err = Auth::checkPasswordStrength($d['owner_password'])) throw HttpException::bad($err, ['owner_password' => $err]);
        $r = Provision::createGym(['name' => $d['name'], 'phone' => $d['phone'] ?? null, 'address' => $d['address'] ?? null, 'gstin' => $d['gstin'] ?? null,
            'state_code' => !empty($d['gstin']) ? substr($d['gstin'], 0, 2) : '33', 'subscription_plan' => $d['subscription_plan'] ?? 'standard',
            'subscription_until' => $d['subscription_until'] ?? null, 'default_language' => $d['default_language'] ?? 'en'],
            ['name' => $d['owner_name'], 'email' => $d['owner_email'], 'password' => $d['owner_password'], 'phone' => $d['phone'] ?? null]);
        Util::audit('platform.gym_create', 'gym', $r['gym_id'], $d['name']);
        return $r;
    }

    public function update(Request $req): array
    {
        $id = $req->id();
        if (!DB::val('SELECT id FROM gyms WHERE id = ?', [$id])) throw HttpException::notFound('Gym not found');
        $d = V::check($req->json(), ['status' => 'nullable|in:active,suspended,trial', 'subscription_plan' => 'nullable|in:starter,standard,multi_branch', 'subscription_until' => 'nullable|date']);
        DB::update('gyms', $d, ['id' => $id]);
        if (($d['status'] ?? null) === 'suspended') DB::run('DELETE FROM auth_sessions WHERE gym_id = ?', [$id]);
        Util::audit('platform.gym_update', 'gym', $id, $d);
        return ['ok' => true];
    }
}
