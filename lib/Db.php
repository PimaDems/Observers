<?php
declare(strict_types=1);

/** Thin PDO wrapper. Always use prepared statements via q(). */
final class Db
{
    private static ?PDO $pdo = null;
    private static bool $inTx = false;

    public static function set(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$inTx = false;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = Config::get('db.dsn');
            if (!$dsn) {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    Config::get('db.host', 'localhost'),
                    (int) Config::get('db.port', 3306),
                    Config::get('db.name', ''),
                    Config::get('db.charset', 'utf8mb4')
                );
            }
            self::$pdo = new PDO($dsn, Config::get('db.user'), Config::get('db.pass'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            if (self::isSqlite()) {
                self::$pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
        return self::$pdo;
    }

    public static function isSqlite(): bool
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $r = self::q($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::q($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Row-lock suffix for SELECTs inside a transaction (no-op on SQLite, which locks the whole DB). */
    public static function forUpdate(): string
    {
        return self::isSqlite() ? '' : ' FOR UPDATE';
    }

    /** Run $fn in a transaction; rolls back and rethrows on any exception. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$inTx) {
            return $fn();
        }
        $sqlite = self::isSqlite();
        $sqlite ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
        self::$inTx = true;
        try {
            $result = $fn();
            $sqlite ? $pdo->exec('COMMIT') : $pdo->commit();
            self::$inTx = false;
            return $result;
        } catch (Throwable $e) {
            self::$inTx = false;
            try {
                $sqlite ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
            } catch (Throwable) {
                // connection already rolled back
            }
            throw $e;
        }
    }

    /** Apply schema.sql (translated for SQLite when needed). */
    public static function installSchema(?string $file = null): int
    {
        $sql = file_get_contents($file ?? OBS_ROOT . '/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $count = 0;
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            $stmt = rtrim($stmt, ';');
            if (self::isSqlite()) {
                $stmt = preg_replace('/\)\s*ENGINE=.*$/s', ')', $stmt);
                $stmt = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $stmt);
            }
            self::pdo()->exec($stmt);
            $count++;
        }
        return $count;
    }
}
