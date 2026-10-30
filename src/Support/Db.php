<?php
declare(strict_types=1);

namespace MajiLink\Support;

use PDO;
use PDOStatement;
use RuntimeException;

final class Db
{
    private static ?PDO $pdo = null;
    private static array $config = [];
    private static int $depth = 0;

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = self::$config;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn ($c) => ':' . $c, $columns))
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $params = [];
        $sets = [];
        foreach ($data as $column => $value) {
            $sets[] = $column . ' = :s_' . $column;
            $params['s_' . $column] = $value;
        }

        // The where clause uses positional "?" markers; rewrite them as named
        // parameters so they can coexist with the SET keys above.
        $whereSql = $where;
        foreach (array_values($whereParams) as $index => $value) {
            $position = strpos($whereSql, '?');
            if ($position === false) {
                break;
            }
            $whereSql = substr_replace($whereSql, ':w_' . $index, $position, 1);
            $params['w_' . $index] = $value;
        }

        $stmt = self::run("UPDATE $table SET " . implode(', ', $sets) . " WHERE $whereSql", $params);
        return $stmt->rowCount();
    }

    /**
     * Nested-safe transaction. Rolls back when the outermost callback throws.
     */
    public static function transaction(callable $callback): mixed
    {
        if (self::$depth === 0) {
            self::pdo()->beginTransaction();
        }
        self::$depth++;
        try {
            $result = $callback();
            self::$depth--;
            if (self::$depth === 0) {
                self::pdo()->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            self::$depth--;
            if (self::$depth === 0 && self::pdo()->inTransaction()) {
                self::pdo()->rollBack();
            }
            throw $e;
        }
    }

    public static function table(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
    }
}

final class Clock
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function at(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
