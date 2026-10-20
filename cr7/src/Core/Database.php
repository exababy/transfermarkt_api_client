<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper with MySQL/SQLite parity. Prepared statements only.
 *
 * Params may be positional (list => `?`) or named (['slug' => 'x'] => `:slug`).
 * LIKE: `col LIKE ? ESCAPE '\'` with Database::likeEscape() works on both drivers
 * (MySQL sessions run with NO_BACKSLASH_ESCAPES for that reason).
 */
final class Database
{
    /** Max bound parameters per multi-row statement (below SQLite's historical 999 limit). */
    private const MAX_PARAMS = 900;

    private static ?PDO $pdo = null;
    private static ?string $driver = null;

    /** Lazily connected singleton. */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::setPdo(self::connect());
        }
        return self::$pdo;
    }

    /** Inject a connection (tests) or reset with null. */
    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$driver = null;
        if ($pdo === null) {
            return;
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'sqlite' : 'mysql';
        if (self::$driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** 'mysql' | 'sqlite' (from the active connection, else from config). */
    public static function driver(): string
    {
        return self::$driver ?? (Config::get('db.driver', 'mysql') === 'sqlite' ? 'sqlite' : 'mysql');
    }

    /** @return list<array<string, mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** First column of the first row, or null. */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Run a statement and return the number of affected rows. */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** Prepare + bind + execute; returns the statement (iterate large result sets with it). */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        self::bind($stmt, $params);
        $stmt->execute();
        return $stmt;
    }

    /** Insert one row; returns lastInsertId (0 if none). */
    public static function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Database::insert() needs at least one column.');
        }
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdent($table),
            implode(', ', array_map(self::quoteIdent(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        self::execute($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Update rows matching $where. $whereParams may be positional (`id = ?`) or named (`id = :id`).
     * Returns affected rows.
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }
        $named = $whereParams !== [] && !array_is_list($whereParams);
        $sets = [];
        $params = [];
        $i = 0;
        foreach ($data as $column => $value) {
            if ($named) {
                $placeholder = ':__set' . $i++;
                $params[$placeholder] = $value;
            } else {
                $placeholder = '?';
                $params[] = $value;
            }
            $sets[] = self::quoteIdent((string) $column) . ' = ' . $placeholder;
        }
        if ($named) {
            foreach ($whereParams as $key => $value) {
                $params[str_starts_with((string) $key, ':') ? (string) $key : ':' . $key] = $value;
            }
        } else {
            array_push($params, ...$whereParams);
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::quoteIdent($table), implode(', ', $sets), $where);
        return self::execute($sql, $params);
    }

    /**
     * Insert or update one row.
     * mysql: INSERT ... ON DUPLICATE KEY UPDATE col=VALUES(col); sqlite: ON CONFLICT(cols) DO UPDATE SET col=excluded.col.
     * $updateColumns null => every column of $data except the conflict columns.
     */
    public static function upsert(string $table, array $data, array $conflictColumns, ?array $updateColumns = null): void
    {
        self::upsertMany($table, [$data], $conflictColumns, $updateColumns);
    }

    /**
     * Upsert many rows (all rows must share the first row's columns), chunked, inside one transaction.
     */
    public static function upsertMany(string $table, array $rows, array $conflictColumns, ?array $updateColumns = null): void
    {
        if ($rows === []) {
            return;
        }
        $columns = array_keys((array) reset($rows));
        if ($columns === [] || $conflictColumns === []) {
            throw new \InvalidArgumentException('Database::upsertMany() needs columns and conflict columns.');
        }
        foreach ([...$conflictColumns, ...($updateColumns ?? [])] as $column) {
            if (!in_array($column, $columns, true)) {
                throw new \InvalidArgumentException("Column '{$column}' is not part of the upserted data.");
            }
        }
        $update = $updateColumns ?? array_values(array_diff($columns, $conflictColumns));

        $rowSql = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $prefix = sprintf('INSERT INTO %s (%s) VALUES ', self::quoteIdent($table), implode(', ', array_map(self::quoteIdent(...), $columns)));
        $suffix = self::upsertClause($conflictColumns, $update);
        $chunkSize = max(1, intdiv(self::MAX_PARAMS, count($columns)));

        self::transaction(static function () use ($rows, $columns, $chunkSize, $rowSql, $prefix, $suffix): void {
            foreach (array_chunk($rows, $chunkSize) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    foreach ($columns as $column) {
                        if (!array_key_exists($column, $row)) {
                            throw new \InvalidArgumentException("Upsert row is missing column '{$column}'.");
                        }
                        $params[] = $row[$column];
                    }
                }
                self::execute($prefix . implode(', ', array_fill(0, count($chunk), $rowSql)) . $suffix, $params);
            }
        });
    }

    /** Run $fn inside a transaction (re-entrant: nested calls join the outer transaction). */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Quote a table/column identifier (validated against /^[a-z_][a-z0-9_]*$/i). */
    public static function quoteIdent(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$name}");
        }
        return self::driver() === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
    }

    /** Escape % _ \ for use in `LIKE ? ESCAPE '\'`. */
    public static function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    private static function upsertClause(array $conflictColumns, array $update): string
    {
        if (self::driver() === 'mysql') {
            $sets = $update === []
                ? [self::quoteIdent($conflictColumns[0]) . ' = ' . self::quoteIdent($conflictColumns[0])]
                : array_map(static fn (string $c): string => self::quoteIdent($c) . ' = VALUES(' . self::quoteIdent($c) . ')', $update);
            return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
        }
        $target = ' ON CONFLICT (' . implode(', ', array_map(self::quoteIdent(...), $conflictColumns)) . ')';
        if ($update === []) {
            return $target . ' DO NOTHING';
        }
        return $target . ' DO UPDATE SET ' . implode(', ', array_map(
            static fn (string $c): string => self::quoteIdent($c) . ' = excluded.' . self::quoteIdent($c),
            $update
        ));
    }

    private static function bind(PDOStatement $stmt, array $params): void
    {
        $positional = true;
        foreach (array_keys($params) as $key) {
            if (!is_int($key)) {
                $positional = false;
                break;
            }
        }
        if ($positional) {
            $params = array_values($params); // tolerate non-0-based positional arrays
        }
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            if (is_bool($value)) {
                $value = (int) $value;
            } elseif (is_float($value)) {
                $value = (string) $value;
            } elseif ($value instanceof \Stringable) {
                $value = (string) $value;
            }
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
    }

    private static function connect(): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (Config::get('db.driver', 'mysql') === 'sqlite') {
            $path = (string) Config::get('db.sqlite_path', App::basePath('storage/database.sqlite'));
            $memory = $path === ':memory:';
            if (!$memory && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            if (!$memory) {
                $pdo->exec('PRAGMA journal_mode = WAL');
            }
            return $pdo;
        }

        $charset = (string) Config::get('db.charset', 'utf8mb4');
        if (preg_match('/^[a-z0-9_]+$/i', $charset) !== 1) {
            throw new \InvalidArgumentException('Invalid db.charset');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) Config::get('db.host', 'localhost'),
            (int) Config::get('db.port', 3306),
            (string) Config::get('db.database', ''),
            $charset
        );
        $pdo = new PDO($dsn, (string) Config::get('db.username', ''), (string) Config::get('db.password', ''), $options + [
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // UTC session, utf8mb4, consistent strict mode across hosts (no ONLY_FULL_GROUP_BY;
        // NO_BACKSLASH_ESCAPES keeps `ESCAPE '\'` portable with SQLite).
        $collate = $charset === 'utf8mb4' ? ' COLLATE utf8mb4_unicode_ci' : '';
        $pdo->exec("SET NAMES {$charset}{$collate}, time_zone = '+00:00', "
            . "sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_BACKSLASH_ESCAPES'");
        return $pdo;
    }
}
