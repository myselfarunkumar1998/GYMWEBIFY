<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{DB, Util};

/**
 * Door access rules. The decision for live check-ins (QR, manual, kiosk) is made here in real time.
 * Biometric devices decide locally, so we keep their user list in sync: when a member loses access
 * we queue a "move to blocked group" command for every device; when access returns we restore it.
 */
final class Access
{
    /** @return array{allowed: bool, reason: ?string, membership: ?array} */
    public static function check(int $gymId, array $member, ?int $branchId = null, ?string $at = null): array
    {
        $at ??= date('Y-m-d H:i:s');
        $date = substr($at, 0, 10);
        $time = substr($at, 11, 8);
        if ($member['is_blocked']) return ['allowed' => false, 'reason' => $member['blocked_reason'] ?: 'Blocked by the gym', 'membership' => null];

        $ms = DB::one("SELECT ms.*, p.access_start, p.access_end, p.all_branches, p.name AS plan_name FROM memberships ms JOIN plans p ON p.id = ms.plan_id
            WHERE ms.gym_id = ? AND ms.member_id = ? AND ms.kind = 'membership' AND ms.status IN ('active','frozen','upcoming','expired')
            AND ms.start_date <= ? ORDER BY ms.end_date DESC LIMIT 1", [$gymId, $member['id'], $date]);
        if (!$ms) return ['allowed' => false, 'reason' => 'No active plan', 'membership' => null];
        if ($ms['status'] === 'frozen' || ($ms['freeze_start'] && $ms['freeze_start'] <= $date && $ms['freeze_end'] >= $date)) {
            return ['allowed' => false, 'reason' => 'Plan is frozen till ' . date('d M', strtotime((string)$ms['freeze_end'])), 'membership' => $ms];
        }
        $grace = (int)Util::setting('rules.grace_days', 3, $gymId);
        if (Util::addDays($ms['end_date'], $grace) < $date) {
            return ['allowed' => false, 'reason' => 'Plan expired ' . date('d M', strtotime($ms['end_date'])), 'membership' => $ms];
        }
        if ($ms['access_start'] && $ms['access_end'] && ($time < $ms['access_start'] || $time > $ms['access_end'])) {
            return ['allowed' => false, 'reason' => 'Plan allows ' . substr($ms['access_start'], 0, 5) . '–' . substr($ms['access_end'], 0, 5) . ' only', 'membership' => $ms];
        }
        if ($branchId && !$ms['all_branches'] && $member['branch_id'] && (int)$member['branch_id'] !== $branchId) {
            return ['allowed' => false, 'reason' => 'Plan is for another branch', 'membership' => $ms];
        }
        $duesDays = (int)Util::setting('rules.block_dues_after_days', 15, $gymId);
        if ($duesDays > 0) {
            $old = DB::val("SELECT COUNT(*) FROM invoices WHERE gym_id = ? AND member_id = ? AND status IN ('unpaid','partial') AND due_date < ?",
                [$gymId, $member['id'], Util::addDays($date, -$duesDays)]);
            if ($old > 0) return ['allowed' => false, 'reason' => "Dues unpaid for over $duesDays days", 'membership' => $ms];
        }
        return ['allowed' => true, 'reason' => null, 'membership' => $ms];
    }

    /** Re-check a member and sync device state if access changed. */
    public static function reevaluate(int $gymId, int $memberId): void
    {
        $m = DB::one('SELECT * FROM members WHERE id = ? AND gym_id = ?', [$memberId, $gymId]);
        if (!$m || !$m['device_user_id']) return;
        $allowedNow = self::check($gymId, $m)['allowed'];
        $state = DB::val("SELECT command FROM device_commands dc JOIN devices d ON d.id = dc.device_id WHERE d.gym_id = ? AND dc.command LIKE ? ORDER BY dc.id DESC LIMIT 1",
            [$gymId, '%PIN=' . $m['device_user_id'] . "\t%"]);
        $wasBlocked = $state !== null && str_contains((string)$state, "\tGrp=" . self::blockedGroup($gymId));
        if ($allowedNow === !$wasBlocked && $state !== null) return; // already in sync
        if ($state === null && $allowedNow) return; // never blocked
        self::syncUser($gymId, $m, $allowedNow);
    }

    public static function blockedGroup(int $gymId): int { return (int)Util::setting('devices.blocked_group', 2, $gymId); }

    public static function syncUser(int $gymId, array $m, bool $allowed): void
    {
        $grp = $allowed ? (int)Util::setting('devices.allowed_group', 1, $gymId) : self::blockedGroup($gymId);
        $name = preg_replace('/[^A-Za-z0-9 .]/', '', $m['name']);
        $cmd = "DATA UPDATE USERINFO PIN={$m['device_user_id']}\tName=" . substr($name, 0, 24) . "\tPri=0\tGrp=$grp";
        foreach (DB::all('SELECT id FROM devices WHERE gym_id = ? AND is_active = 1', [$gymId]) as $d) {
            DB::insert('device_commands', ['device_id' => $d['id'], 'command' => $cmd]);
        }
    }
}
