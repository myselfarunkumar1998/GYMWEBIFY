<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, DB, HttpException, Request, Util};

abstract class Base
{
    protected function gym(): int { return Auth::gymId(); }

    /** Fetch a row that must belong to the current gym. */
    protected function find(string $table, int $id, string $what = 'Record'): array
    {
        DB::assertIdent($table);
        $row = DB::one("SELECT * FROM `$table` WHERE id = ? AND gym_id = ?", [$id, $this->gym()]);
        if (!$row) throw HttpException::notFound("$what not found");
        return $row;
    }

    protected function memberRow(int $id): array
    {
        $m = DB::one('SELECT * FROM members WHERE id = ? AND gym_id = ? AND deleted_at IS NULL', [$id, $this->gym()]);
        if (!$m) throw HttpException::notFound('Member not found');
        if (Auth::role() === 'trainer' && (int)$m['trainer_id'] !== Auth::staffId()) throw HttpException::forbidden('This member is not assigned to you');
        return $m;
    }

    /** Branch filter: explicit ?branch_id= or the staff member's own branch. Returns [sqlFragment, params]. */
    protected function branchFilter(Request $req, string $col): array
    {
        $limit = Auth::branchLimit();
        $b = $limit ?? ((int)$req->q('branch_id', 0) ?: null);
        if ($b && !DB::val('SELECT id FROM branches WHERE id = ? AND gym_id = ?', [$b, $this->gym()])) throw HttpException::notFound('Branch not found');
        return $b ? [" AND $col = $b", []] : ['', []];
    }

    protected function assertBranch(?int $branchId): ?int
    {
        if ($branchId === null) return null;
        if (!DB::val('SELECT id FROM branches WHERE id = ? AND gym_id = ?', [$branchId, $this->gym()])) throw HttpException::bad('Invalid branch', ['branch_id' => 'Invalid branch']);
        $lim = Auth::branchLimit();
        if ($lim && $lim !== $branchId) throw HttpException::forbidden('You can only work in your own branch');
        return $branchId;
    }

    protected function defaultBranch(): int
    {
        return Auth::branchLimit() ?? (int)DB::val('SELECT id FROM branches WHERE gym_id = ? AND is_active = 1 ORDER BY id LIMIT 1', [$this->gym()]);
    }

    protected function assertStaff(?int $userId, string $field = 'trainer_id'): ?int
    {
        if (!$userId) return null;
        if (!DB::val("SELECT id FROM users WHERE id = ? AND gym_id = ? AND status = 'active'", [$userId, $this->gym()])) throw HttpException::bad('Invalid staff member', [$field => 'Invalid staff member']);
        return $userId;
    }

    protected function graceDays(): int { return (int)Util::setting('rules.grace_days', 3); }

    protected function paged(string $sql, array $params, Request $req): array
    {
        [$limit, $offset] = $req->page();
        $total = (int)DB::val("SELECT COUNT(*) FROM ($sql) t", $params);
        $rows = DB::all("$sql LIMIT $limit OFFSET $offset", $params);
        return ['items' => $rows, 'total' => $total, 'page' => intdiv($offset, $limit) + 1, 'limit' => $limit];
    }
}
