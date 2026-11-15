<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class IdempotencyRepository
{
    public function insert(int $userId, string $key, string $operation, string $hash, string $expiresAt): void
    {
        Database::pdo()->prepare(
            "INSERT INTO idempotency_keys (user_id, idem_key, operation, request_hash, status, expires_at)
             VALUES (?, ?, ?, ?, 'processing', ?)"
        )->execute([$userId, $key, $operation, $hash, $expiresAt]);
    }

    /**
     * Обычное (согласованное) чтение: вызывается после ожидания на уникальном индексе, когда конкурирующая
     * транзакция уже завершилась, поэтому снимок видит её результат. Блокирующее чтение здесь привело бы
     * к взаимным блокировкам при параллельных повторах одного ключа.
     */
    public function find(int $userId, string $key): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM idempotency_keys WHERE user_id = ? AND idem_key = ?');
        $st->execute([$userId, $key]);
        return $st->fetch() ?: null;
    }

    /** Переиспользование ключа с истёкшим сроком хранения. */
    public function reset(int $id, string $operation, string $hash, string $expiresAt): void
    {
        Database::pdo()->prepare(
            "UPDATE idempotency_keys SET operation = ?, request_hash = ?, status = 'processing',
                    entity_id = NULL, response_json = NULL, created_at = UTC_TIMESTAMP(), expires_at = ? WHERE id = ?"
        )->execute([$operation, $hash, $expiresAt, $id]);
    }

    public function complete(int $userId, string $key, int $entityId, string $responseJson): void
    {
        Database::pdo()->prepare(
            "UPDATE idempotency_keys SET status = 'done', entity_id = ?, response_json = ? WHERE user_id = ? AND idem_key = ?"
        )->execute([$entityId, $responseJson, $userId, $key]);
    }

    public function purgeExpired(): void
    {
        Database::pdo()->exec('DELETE FROM idempotency_keys WHERE expires_at < UTC_TIMESTAMP()');
    }
}
