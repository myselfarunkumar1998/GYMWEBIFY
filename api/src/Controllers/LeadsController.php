<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Util, V};
use App\Services\{Messenger, Webhooks};

final class LeadsController extends Base
{
    private const RULES = ['name' => 'required|string|max:120', 'phone' => 'required|phone', 'email' => 'nullable|email', 'source' => 'nullable|in:walk_in,instagram,facebook,google,website,referral,whatsapp_bot,phone,other',
        'interest' => 'nullable|string|max:200', 'branch_id' => 'nullable|int', 'assigned_to' => 'nullable|int', 'next_followup_at' => 'nullable|datetime',
        'trial_at' => 'nullable|datetime', 'notes' => 'nullable|string|max:2000', 'stage' => 'nullable|in:new,trial,follow_up,joined,lost', 'lost_reason' => 'nullable|string|max:150'];

    public function index(Request $req): array
    {
        $g = $this->gym();
        [$bf] = $this->branchFilter($req, 'l.branch_id');
        $sql = "SELECT l.*, u.name AS assigned_name, (SELECT COUNT(*) FROM lead_activities a WHERE a.lead_id = l.id) AS activity_count
            FROM leads l LEFT JOIN users u ON u.id = l.assigned_to WHERE l.gym_id = ? $bf";
        $p = [$g];
        if ($q = trim((string)$req->q('q', ''))) { $sql .= ' AND (l.name LIKE ? OR l.phone LIKE ?)'; $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_') . '%'; array_push($p, $like, $like); }
        if ($req->q('mine')) { $sql .= ' AND l.assigned_to = ?'; $p[] = Auth::staffId(); }
        $includeClosed = (bool)$req->q('all');
        if (!$includeClosed) $sql .= " AND (l.stage NOT IN ('joined','lost') OR l.updated_at > NOW() - INTERVAL 30 DAY)";
        $rows = DB::all($sql . ' ORDER BY COALESCE(l.next_followup_at, l.created_at)', $p);
        $cols = ['new' => [], 'trial' => [], 'follow_up' => [], 'joined' => [], 'lost' => []];
        foreach ($rows as $r) {
            $r['overdue'] = $r['next_followup_at'] && $r['next_followup_at'] < date('Y-m-d H:i:s') && !in_array($r['stage'], ['joined', 'lost'], true);
            $cols[$r['stage']][] = $r;
        }
        return ['columns' => $cols, 'stats' => $this->stats()];
    }

    private function stats(): array
    {
        $g = $this->gym();
        $m = date('Y-m');
        $total = (int)DB::val("SELECT COUNT(*) FROM leads WHERE gym_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?", [$g, $m]);
        $joined = (int)DB::val("SELECT COUNT(*) FROM leads WHERE gym_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ? AND stage = 'joined'", [$g, $m]);
        $best = DB::one("SELECT source, SUM(stage = 'joined') / COUNT(*) AS rate, COUNT(*) n FROM leads WHERE gym_id = ? AND created_at > NOW() - INTERVAL 90 DAY GROUP BY source HAVING n >= 3 ORDER BY rate DESC LIMIT 1", [$g]);
        $bot = (int)DB::val("SELECT COUNT(DISTINCT to_addr) FROM messages WHERE gym_id = ? AND direction = 'in' AND DATE_FORMAT(created_at, '%Y-%m') = ?", [$g, $m]);
        return ['month_total' => $total, 'conversion' => $total ? round($joined / $total * 100) : 0, 'best_source' => $best['source'] ?? null, 'bot_chats' => $bot,
            'by_source' => DB::all("SELECT source, COUNT(*) total, SUM(stage = 'joined') joined FROM leads WHERE gym_id = ? AND created_at > NOW() - INTERVAL 90 DAY GROUP BY source ORDER BY total DESC", [$g]),
            'by_staff' => DB::all("SELECT u.name, COUNT(*) total, SUM(l.stage = 'joined') joined FROM leads l JOIN users u ON u.id = l.assigned_to WHERE l.gym_id = ? AND l.created_at > NOW() - INTERVAL 90 DAY GROUP BY u.id ORDER BY joined DESC", [$g])];
    }

    /** Round-robin assignment among front desk and managers when no one is chosen. */
    private function autoAssign(): ?int
    {
        return (int)DB::val("SELECT u.id FROM users u WHERE u.gym_id = ? AND u.status = 'active' AND u.role IN ('front_desk','manager')
            ORDER BY (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id AND l.stage IN ('new','trial','follow_up')), u.id LIMIT 1", [$this->gym()]) ?: null;
    }

    public function store(Request $req): array
    {
        $d = V::check($req->json(), self::RULES);
        $this->assertBranch($d['branch_id'] ?? null);
        $this->assertStaff($d['assigned_to'] ?? null, 'assigned_to');
        $d['assigned_to'] ??= $this->autoAssign();
        $d['next_followup_at'] ??= date('Y-m-d H:i:s', time() + 4 * 3600);
        $d['source'] ??= 'walk_in';
        if (!empty($d['trial_at'])) { $d['stage'] = 'trial'; $d['trial_pass_code'] = Util::randomCode(6); }
        $id = DB::insert('leads', $d + ['gym_id' => $this->gym()]);
        DB::insert('lead_activities', ['lead_id' => $id, 'user_id' => Auth::staffId(), 'kind' => 'created', 'note' => 'Added by ' . (Auth::$staff['name'] ?? 'staff')]);
        if (!empty($d['trial_at'])) $this->sendTrial($id);
        Webhooks::emit($this->gym(), 'lead.created', ['lead_id' => $id, 'source' => $d['source']]);
        return ['id' => $id];
    }

    public function show(Request $req): array
    {
        $l = $this->find('leads', $req->id(), 'Lead');
        $l['activities'] = DB::all('SELECT a.*, u.name AS user_name FROM lead_activities a LEFT JOIN users u ON u.id = a.user_id WHERE a.lead_id = ? ORDER BY a.id DESC', [$l['id']]);
        $l['messages'] = DB::all('SELECT direction, body, status, created_at FROM messages WHERE lead_id = ? ORDER BY id DESC LIMIT 30', [$l['id']]);
        return $l;
    }

    public function update(Request $req): array
    {
        Auth::require('leads.write');
        $l = $this->find('leads', $req->id(), 'Lead');
        $d = V::check($req->json(), array_map(fn($r) => str_replace('required|', '', $r), self::RULES));
        $this->assertBranch($d['branch_id'] ?? null);
        if (array_key_exists('assigned_to', $d)) $this->assertStaff($d['assigned_to'], 'assigned_to');
        if (($d['stage'] ?? null) === 'lost' && empty($d['lost_reason']) && !$l['lost_reason']) throw HttpException::bad('Give a reason', ['lost_reason' => 'Required when marking lost']);
        $newTrial = !empty($d['trial_at']) && $d['trial_at'] !== $l['trial_at'];
        if ($newTrial) { $d['stage'] = 'trial'; $d['trial_pass_code'] = $l['trial_pass_code'] ?: Util::randomCode(6); }
        DB::update('leads', $d, ['id' => $l['id'], 'gym_id' => $this->gym()]);
        if (isset($d['stage']) && $d['stage'] !== $l['stage']) DB::insert('lead_activities', ['lead_id' => $l['id'], 'user_id' => Auth::staffId(), 'kind' => 'stage', 'note' => "Moved to {$d['stage']}"]);
        if ($newTrial) $this->sendTrial((int)$l['id']);
        return ['ok' => true];
    }

    private function sendTrial(int $id): void
    {
        $l = DB::one('SELECT * FROM leads WHERE id = ?', [$id]);
        Messenger::template($this->gym(), 'trial_booked', null, ['name' => strtok($l['name'], ' '), 'date' => date('d M, g:i A', strtotime($l['trial_at'])), 'code' => $l['trial_pass_code']], ['phone' => $l['phone'], 'lead_id' => $id]);
    }

    public function activity(Request $req): array
    {
        Auth::require('leads.write');
        $l = $this->find('leads', $req->id(), 'Lead');
        $d = V::check($req->json(), ['kind' => 'required|in:call,note,whatsapp,visit', 'note' => 'required|string|max:500', 'next_followup_at' => 'nullable|datetime']);
        DB::insert('lead_activities', ['lead_id' => $l['id'], 'user_id' => Auth::staffId(), 'kind' => $d['kind'], 'note' => $d['note']]);
        $upd = ['next_followup_at' => $d['next_followup_at'] ?? null];
        if ($l['stage'] === 'new') $upd['stage'] = 'follow_up';
        DB::update('leads', $upd, ['id' => $l['id']]);
        if ($d['kind'] === 'whatsapp') {
            $mid = Messenger::queue($this->gym(), 'whatsapp', $l['phone'], $d['note'], ['lead_id' => (int)$l['id'], 'tkey' => 'manual']);
            Messenger::dispatch(1, $mid);
        }
        return ['ok' => true];
    }

    /** Convert to member: returns the prefill for the Add member form. */
    public function convert(Request $req): array
    {
        $l = $this->find('leads', $req->id(), 'Lead');
        return ['lead_id' => (int)$l['id'], 'name' => $l['name'], 'phone' => $l['phone'], 'email' => $l['email'], 'source' => 'Lead · ' . $l['source'], 'branch_id' => $l['branch_id']];
    }

    public function destroy(Request $req): array
    {
        Auth::require('leads.write');
        $l = $this->find('leads', $req->id(), 'Lead');
        DB::run('DELETE FROM lead_activities WHERE lead_id = ?', [$l['id']]);
        DB::run('DELETE FROM leads WHERE id = ? AND gym_id = ?', [$l['id'], $this->gym()]);
        return ['ok' => true];
    }
}
