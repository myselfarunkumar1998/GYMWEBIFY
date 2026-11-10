<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Util, V};
use App\Services\{Messenger, Notify};

final class CampaignsController extends Base
{
    public function overview(Request $req): array
    {
        $g = $this->gym();
        $m = date('Y-m');
        $stats = DB::one("SELECT COUNT(*) total, SUM(status IN ('sent','delivered','read')) sent, SUM(status = 'failed') failed, SUM(status = 'read') read_count
            FROM messages WHERE gym_id = ? AND direction = 'out' AND DATE_FORMAT(created_at, '%Y-%m') = ?", [$g, $m]);
        $renewed = (int)DB::val("SELECT COUNT(DISTINCT x.member_id) FROM messages x JOIN invoices i ON i.member_id = x.member_id AND i.created_at BETWEEN x.created_at AND x.created_at + INTERVAL 7 DAY
            WHERE x.gym_id = ? AND x.tkey IN ('renewal_reminder','dues_reminder','payment_link') AND DATE_FORMAT(x.created_at, '%Y-%m') = ? AND i.status = 'paid'", [$g, $m]);
        return ['stats' => $stats + ['renewed_after_reminder' => $renewed], 'whatsapp_connected' => Messenger::whatsappConfigured($g),
            'campaigns' => DB::all('SELECT c.*, u.name AS created_by_name, (SELECT COUNT(*) FROM messages x WHERE x.campaign_id = c.id AND x.status IN (\'sent\',\'delivered\',\'read\')) AS delivered FROM campaigns c LEFT JOIN users u ON u.id = c.created_by WHERE c.gym_id = ? ORDER BY c.id DESC LIMIT 50', [$g]),
            'automations' => Util::setting('automations', []), 'templates' => DB::all('SELECT * FROM message_templates WHERE gym_id = ? ORDER BY tkey, language', [$g])];
    }

    /** Members matching a segment. */
    public static function segmentMembers(int $gymId, array $s): array
    {
        $grace = (int)Util::setting('rules.grace_days', 3, $gymId);
        $sql = "SELECT m.* FROM members m LEFT JOIN memberships ms ON ms.id = (SELECT x.id FROM memberships x WHERE x.member_id = m.id AND x.kind = 'membership' AND x.status NOT IN ('cancelled','transferred') ORDER BY x.end_date DESC LIMIT 1)
            WHERE m.gym_id = ? AND m.deleted_at IS NULL";
        $p = [$gymId];
        switch ($s['status'] ?? 'all') {
            case 'active': $sql .= " AND ms.end_date >= CURDATE() - INTERVAL $grace DAY AND ms.status IN ('active','frozen')"; break;
            case 'expired': $sql .= ' AND (ms.id IS NULL OR ms.end_date < CURDATE())'; if (!empty($s['expired_within_days'])) { $sql .= ' AND ms.end_date >= CURDATE() - INTERVAL ? DAY'; $p[] = (int)$s['expired_within_days']; } break;
            case 'expiring': $sql .= " AND ms.status = 'active' AND ms.end_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY"; $p[] = (int)($s['days'] ?? 7); break;
            case 'inactive': $sql .= " AND ms.end_date >= CURDATE() AND NOT EXISTS (SELECT 1 FROM attendance_sessions a WHERE a.member_id = m.id AND a.in_at > NOW() - INTERVAL ? DAY)"; $p[] = (int)($s['days'] ?? 14); break;
            case 'dues': $sql .= " AND EXISTS (SELECT 1 FROM invoices i WHERE i.member_id = m.id AND i.status IN ('unpaid','partial'))"; break;
            case 'churn': $sql .= ' AND m.churn_score >= 60'; break;
            case 'birthday_month': $sql .= ' AND MONTH(m.dob) = MONTH(CURDATE())'; break;
        }
        if (!empty($s['plan_id'])) { $sql .= ' AND ms.plan_id = ?'; $p[] = (int)$s['plan_id']; }
        if (!empty($s['branch_id'])) { $sql .= ' AND m.branch_id = ?'; $p[] = (int)$s['branch_id']; }
        if (!empty($s['gender']) && in_array($s['gender'], ['male', 'female', 'other'], true)) { $sql .= ' AND m.gender = ?'; $p[] = $s['gender']; }
        if (!empty($s['language']) && in_array($s['language'], ['en', 'ta'], true)) { $sql .= ' AND m.language = ?'; $p[] = $s['language']; }
        if (!empty($s['member_ids']) && is_array($s['member_ids'])) $sql .= ' AND m.id IN (' . DB::inList($s['member_ids']) . ')';
        return DB::all($sql, $p);
    }

    private function segment(Request $req): array
    {
        $s = $req->input('segment', []);
        if (!is_array($s)) throw HttpException::bad('Invalid segment');
        V::check($s, ['status' => 'nullable|in:all,active,expired,expiring,inactive,dues,churn,birthday_month', 'days' => 'nullable|int|min:1|max:365',
            'expired_within_days' => 'nullable|int|min:1|max:3650', 'plan_id' => 'nullable|int', 'branch_id' => 'nullable|int', 'gender' => 'nullable|in:male,female,other', 'language' => 'nullable|in:en,ta']);
        return $s;
    }

    public function preview(Request $req): array
    {
        $s = $this->segment($req);
        $rows = self::segmentMembers($this->gym(), $s);
        return ['count' => count($rows), 'sample' => array_map(fn($m) => ['name' => $m['name'], 'phone' => Util::maskPhone($m['phone'])], array_slice($rows, 0, 5))];
    }

    public function store(Request $req): array
    {
        Auth::require('campaigns.send');
        $d = V::check($req->json(), ['name' => 'required|string|max:120', 'channel' => 'required|in:whatsapp,sms,email,push', 'body' => 'required|string|max:1000', 'scheduled_at' => 'nullable|datetime']);
        $s = $this->segment($req);
        $id = DB::insert('campaigns', ['gym_id' => $this->gym(), 'name' => $d['name'], 'channel' => $d['channel'], 'segment' => json_encode($s), 'body' => $d['body'],
            'status' => !empty($d['scheduled_at']) && $d['scheduled_at'] > date('Y-m-d H:i:s') ? 'scheduled' : 'sending', 'scheduled_at' => $d['scheduled_at'] ?? null, 'created_by' => Auth::staffId()]);
        $n = DB::val('SELECT status FROM campaigns WHERE id = ?', [$id]) === 'sending' ? self::send($this->gym(), $id) : 0;
        Util::audit('campaign.create', 'campaign', $id, ['recipients' => $n]);
        return ['id' => $id, 'recipients' => $n];
    }

    public static function send(int $gymId, int $id): int
    {
        $c = DB::one('SELECT * FROM campaigns WHERE id = ? AND gym_id = ?', [$id, $gymId]);
        $members = self::segmentMembers($gymId, json_decode($c['segment'], true) ?: []);
        $gym = Util::gym($gymId);
        $n = 0;
        foreach ($members as $m) {
            $body = Messenger::render($c['body'], ['name' => strtok($m['name'], ' '), 'gym' => $gym['name'], 'code' => $m['member_code']]);
            if ($c['channel'] === 'push') { Notify::member($gymId, (int)$m['id'], $c['name'], $body); $n++; continue; }
            $to = $c['channel'] === 'email' ? $m['email'] : $m['phone'];
            if (!$to) continue;
            Messenger::queue($gymId, $c['channel'], $to, $body, ['member_id' => (int)$m['id'], 'campaign_id' => $id, 'tkey' => 'campaign', 'payload' => $c['channel'] === 'email' ? ['subject' => $c['name']] : null]);
            $n++;
        }
        DB::update('campaigns', ['status' => 'sent', 'recipients' => $n], ['id' => $id]);
        return $n;
    }

    public static function sendDue(): int
    {
        $n = 0;
        foreach (DB::all("SELECT id, gym_id FROM campaigns WHERE status = 'scheduled' AND scheduled_at <= NOW()") as $c) { self::send((int)$c['gym_id'], (int)$c['id']); $n++; }
        return $n;
    }

    public function cancel(Request $req): array
    {
        $c = $this->find('campaigns', $req->id(), 'Campaign');
        if ($c['status'] !== 'scheduled') throw HttpException::bad('Only scheduled campaigns can be cancelled');
        DB::update('campaigns', ['status' => 'cancelled'], ['id' => $c['id']]);
        return ['ok' => true];
    }

    public function messages(Request $req): array
    {
        $sql = 'SELECT x.id, x.direction, x.channel, x.to_addr, x.tkey, x.body, x.status, x.error, x.provider_id, x.created_at, x.sent_at, m.name AS member_name, l.name AS lead_name
            FROM messages x LEFT JOIN members m ON m.id = x.member_id LEFT JOIN leads l ON l.id = x.lead_id WHERE x.gym_id = ?';
        $p = [$this->gym()];
        if ($s = $req->q('status')) { if (in_array($s, ['queued', 'sent', 'failed', 'received'], true)) { $sql .= ' AND x.status = ?'; $p[] = $s; } }
        if ($req->q('inbox')) $sql .= " AND x.direction = 'in'";
        return $this->paged($sql . ' ORDER BY x.id DESC', $p, $req);
    }

    public function saveTemplate(Request $req): array
    {
        Auth::require('campaigns.send');
        $t = $this->find('message_templates', $req->id(), 'Template');
        $d = V::check($req->json(), ['body' => 'required|string|max:1000', 'wa_template_name' => 'nullable|string|max:80', 'is_active' => 'nullable|bool']);
        if (!empty($d['wa_template_name']) && !preg_match('/^[a-z0-9_]+$/', $d['wa_template_name'])) throw HttpException::bad('Template names use lowercase letters, digits and _', ['wa_template_name' => 'Invalid']);
        DB::update('message_templates', $d, ['id' => $t['id']]);
        return ['ok' => true];
    }

    public function automations(Request $req): array
    {
        Auth::require('campaigns.send');
        $in = $req->json();
        $g = Util::gym();
        $s = $g['settings'];
        foreach (array_keys(\App\Services\Provision::DEFAULT_SETTINGS['automations']) as $k) if (array_key_exists($k, $in)) $s['automations'][$k] = (bool)$in[$k];
        DB::update('gyms', ['settings' => json_encode($s)], ['id' => $g['id']]);
        Util::forgetGym();
        return ['ok' => true, 'automations' => $s['automations']];
    }

    // ---- Announcements & challenges (shown in the member app) ----

    public function announcements(Request $req): array { return DB::all('SELECT * FROM announcements WHERE gym_id = ? ORDER BY id DESC LIMIT 50', [$this->gym()]); }

    public function saveAnnouncement(Request $req): array
    {
        $d = V::check($req->json(), ['title' => 'required|string|max:150', 'body' => 'required|string|max:1000', 'visible_until' => 'nullable|date', 'notify' => 'nullable|bool']);
        $notify = $d['notify'] ?? false; unset($d['notify']);
        if ($id = $req->id()) { $this->find('announcements', $id, 'Announcement'); DB::update('announcements', $d, ['id' => $id]); return ['id' => $id]; }
        $id = DB::insert('announcements', $d + ['gym_id' => $this->gym(), 'created_by' => Auth::staffId()]);
        if ($notify) foreach (self::segmentMembers($this->gym(), ['status' => 'active']) as $m) Notify::member($this->gym(), (int)$m['id'], $d['title'], $d['body']);
        return ['id' => $id];
    }

    public function deleteAnnouncement(Request $req): array
    {
        $a = $this->find('announcements', $req->id(), 'Announcement');
        DB::run('DELETE FROM announcements WHERE id = ? AND gym_id = ?', [$a['id'], $this->gym()]);
        return ['ok' => true];
    }

    public function challenges(Request $req): array
    {
        $rows = DB::all('SELECT * FROM challenges WHERE gym_id = ? ORDER BY end_date DESC', [$this->gym()]);
        foreach ($rows as &$c) $c['leaderboard'] = self::leaderboard($this->gym(), $c, 10);
        return $rows;
    }

    public static function leaderboard(int $gymId, array $c, int $limit = 10): array
    {
        $metric = $c['metric'] === 'hours' ? 'ROUND(COALESCE(SUM(s.duration_min),0) / 60, 1)' : 'COUNT(DISTINCT DATE(s.in_at))';
        return DB::all("SELECT m.id, m.name, m.leaderboard_opt_in, $metric AS score FROM attendance_sessions s JOIN members m ON m.id = s.member_id
            WHERE s.gym_id = ? AND DATE(s.in_at) BETWEEN ? AND ? AND m.deleted_at IS NULL GROUP BY m.id ORDER BY score DESC LIMIT " . (int)$limit, [$gymId, $c['start_date'], $c['end_date']]);
    }

    public function saveChallenge(Request $req): array
    {
        $d = V::check($req->json(), ['name' => 'required|string|max:120', 'metric' => 'required|in:visits,hours', 'target' => 'required|int|min:1', 'start_date' => 'required|date', 'end_date' => 'required|date', 'prize' => 'nullable|string|max:150']);
        if ($d['end_date'] < $d['start_date']) throw HttpException::bad('End must be after start', ['end_date' => 'Must be after start']);
        if ($id = $req->id()) { $this->find('challenges', $id, 'Challenge'); DB::update('challenges', $d, ['id' => $id]); return ['id' => $id]; }
        return ['id' => DB::insert('challenges', $d + ['gym_id' => $this->gym()])];
    }
}
