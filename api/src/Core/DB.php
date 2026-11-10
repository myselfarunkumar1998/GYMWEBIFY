<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Thin PDO wrapper. Every query uses bound parameters; never interpolate input into SQL. */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $db = Config::get('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $db['host'] ?? 'localhost', (int)($db['port'] ?? 3306), $db['name']);
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $tz = (new \DateTime('now'))->format('P');
            self::$pdo->exec("SET time_zone = '$tz', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    public static function reset(): void { self::$pdo = null; }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = is_int($v) ? PDO::PARAM_INT : (is_bool($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            $st->bindValue($key, is_bool($v) ? (int)$v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $r = self::run($sql, $p)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $p = []): array { return self::run($sql, $p)->fetchAll(); }

    public static function val(string $sql, array $p = []): mixed
    {
        $r = self::run($sql, $p)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function insert(string $table, array $row): int
    {
        self::assertIdent($table);
        $cols = array_keys($row);
        array_walk($cols, [self::class, 'assertIdent']);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (:' . implode(',:', $cols) . ')';
        self::run($sql, $row);
        return (int)self::pdo()->lastInsertId();
    }

    /** Update with mandatory WHERE built from $where (column => value), always AND-ed. */
    public static function update(string $table, array $row, array $where): int
    {
        if (!$row) return 0;
        self::assertIdent($table);
        $set = []; $params = [];
        foreach ($row as $c => $v) { self::assertIdent($c); $set[] = "`$c` = :s_$c"; $params["s_$c"] = $v; }
        $w = [];
        foreach ($where as $c => $v) { self::assertIdent($c); $w[] = "`$c` = :w_$c"; $params["w_$c"] = $v; }
        return self::run("UPDATE `$table` SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $w), $params)->rowCount();
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) return $fn();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function assertIdent(string $id): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $id)) throw new \InvalidArgumentException('Bad identifier');
    }

    /** Build "IN (?,?,?)" placeholders for an int list. */
    public static function inList(array $ids): string
    {
        return $ids ? implode(',', array_map('intval', $ids)) : '0';
    }
}
