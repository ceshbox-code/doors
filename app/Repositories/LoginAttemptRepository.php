<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class LoginAttemptRepository
{
    public function record(string $username, string $ip, bool $success): void
    {
        Database::pdo()->prepare('INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)')
            ->execute([mb_substr($username, 0, 100), mb_substr($ip, 0, 45), (int) $success]);
    }

    public function failuresByUsername(string $username, int $minutes): int
    {
        $m = max(1, $minutes);
        $st = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND username = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL {$m} MINUTE"
        );
        $st->execute([mb_substr($username, 0, 100)]);
        return (int) $st->fetchColumn();
    }

    public function failuresByIp(string $ip, int $minutes): int
    {
        $m = max(1, $minutes);
        $st = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND ip = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL {$m} MINUTE"
        );
        $st->execute([mb_substr($ip, 0, 45)]);
        return (int) $st->fetchColumn();
    }

    public function clearFailures(string $username): void
    {
        Database::pdo()->prepare('DELETE FROM login_attempts WHERE success = 0 AND username = ?')
            ->execute([mb_substr($username, 0, 100)]);
    }

    public function purgeOld(): void
    {
        Database::pdo()->exec('DELETE FROM login_attempts WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    }
}
