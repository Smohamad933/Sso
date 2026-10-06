<?php

declare(strict_types=1);

namespace Sso\Data;

use PDO;
use PDOStatement;

/**
 * لایه‌ی نازک روی PDO.
 *
 * دو درایور پشتیبانی می‌شود:
 *  - mysql  (هدف اصلی: IIS + MySQL)
 *  - sqlite (برای توسعه/تست سریع بدون نیاز به سرور دیتابیس)
 *
 * برای اینکه یک کد روی هر دو درایور کار کند، از این قواعد پیروی شده:
 *  - هیچ تابع تاریخ/زمانِ SQL استفاده نشده؛ مقادیر زمان در PHP ساخته می‌شوند.
 *  - هیچ upsert / ON DUPLICATE KEY / INSERT IGNORE استفاده نشده.
 *  - شناسه‌ها همیشه با backtick کوت شده‌اند (هر دو درایور می‌پذیرند).
 */
final class Database
{
    private PDO $pdo;
    private string $driver;

    public function __construct(array $config)
    {
        $driver = strtolower((string) ($config['driver'] ?? 'mysql'));

        if ($driver === 'sqlite') {
            $path = (string) ($config['path'] ?? (SSO_STORAGE . '/database/sso.sqlite'));
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $dsn = 'sqlite:' . $path;
            $this->pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $charset = (string) ($config['charset'] ?? 'utf8mb4');
            $socket = $config['unix_socket'] ?? null;
            if (!empty($socket)) {
                $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $config['database'], $charset);
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    (string) ($config['host'] ?? '127.0.0.1'),
                    (int) ($config['port'] ?? 3306),
                    (string) ($config['database'] ?? ''),
                    $charset
                );
            }
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ];
            $this->pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $options);
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }

        $this->driver = $driver;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }

    public static function quoteIdentifier(string $name): string
    {
        if ($name === '*') {
            return '*';
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function quoteTable(string $table): string
    {
        return self::quoteIdentifier($table);
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? $default : $row[0];
    }

    public function count(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params, 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        if ($columns === []) {
            throw new \InvalidArgumentException('داده‌ای برای درج ارسال نشده است.');
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteTable($table),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->query($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $whereParams
     */
    public function update(string $table, array $data, string $whereSql, array $whereParams = []): int
    {
        $columns = array_keys($data);
        if ($columns === []) {
            return 0;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteTable($table),
            implode(', ', array_map(static fn(string $c): string => self::quoteIdentifier($c) . ' = ?', $columns)),
            $whereSql
        );
        return $this->query($sql, array_merge(array_values($data), array_values($whereParams)))->rowCount();
    }

    public function delete(string $table, string $whereSql, array $whereParams = []): int
    {
        return $this->query(
            sprintf('DELETE FROM %s WHERE %s', $this->quoteTable($table), $whereSql),
            $whereParams
        )->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * تولید جایگاه‌های IN: placeholders(3) => "?, ?, ?"
     */
    public static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, max(0, $count), '?'));
    }
}
