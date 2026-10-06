<?php

declare(strict_types=1);

namespace Sso\Services;

use Sso\Data\Database;
use Sso\Support\Clock;

/**
 * محدودساز نرخ درخواست (بر پایه‌ی دیتابیس).
 */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    public function hits(string $key, int $decaySeconds): int
    {
        return (int) $this->db->value(
            'SELECT `hits` FROM `rate_limits` WHERE `bucket_key` = ? AND `expires_at` > ?',
            [$key, Clock::now()],
            0
        );
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return $this->hits($key, $decaySeconds) >= max(1, $maxAttempts);
    }

    /**
     * ثبت یک تلاش و برگرداندن تعداد فعلی.
     */
    public function hit(string $key, int $decaySeconds): int
    {
        return (int) $this->db->transaction(function (Database $db) use ($key, $decaySeconds): int {
            $now = Clock::now();
            $db->query('DELETE FROM `rate_limits` WHERE `bucket_key` = ? AND `expires_at` <= ?', [$key, $now]);

            $updated = $db->query(
                'UPDATE `rate_limits` SET `hits` = `hits` + 1, `updated_at` = ? WHERE `bucket_key` = ?',
                [$now, $key]
            )->rowCount();

            if ($updated === 0) {
                try {
                    $db->insert('rate_limits', [
                        'bucket_key' => $key,
                        'hits' => 1,
                        'expires_at' => Clock::inSeconds(max(1, $decaySeconds)),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    return 1;
                } catch (\PDOException) {
                    $db->query(
                        'UPDATE `rate_limits` SET `hits` = `hits` + 1, `updated_at` = ? WHERE `bucket_key` = ?',
                        [$now, $key]
                    );
                }
            }

            $this->maybeCleanup($db);
            return (int) $db->value('SELECT `hits` FROM `rate_limits` WHERE `bucket_key` = ?', [$key], 1);
        });
    }

    public function clear(string $key): void
    {
        $this->db->delete('rate_limits', '`bucket_key` = ?', [$key]);
    }

    /**
     * چند ثانیه مانده تا آزاد شدن (۰ یعنی همین حالا).
     */
    public function availableIn(string $key): int
    {
        $expires = $this->db->value(
            'SELECT `expires_at` FROM `rate_limits` WHERE `bucket_key` = ? AND `expires_at` > ?',
            [$key, Clock::now()]
        );
        if ($expires === null) {
            return 0;
        }
        return max(0, (int) (strtotime((string) $expires . ' UTC') - time()));
    }

    /**
     * پاکسازی احتمالی ردیف‌های منقضی (۱ درصد درخواست‌ها).
     */
    private function maybeCleanup(Database $db): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        $db->query('DELETE FROM `rate_limits` WHERE `expires_at` < ?', [Clock::ago(86400)]);
    }
}
