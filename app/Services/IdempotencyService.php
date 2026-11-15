<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Repositories\IdempotencyRepository;
use PDOException;

/**
 * Идемпотентность операций регистрации (ТЗ 10.4). Запись ключа делается В ТОЙ ЖЕ транзакции,
 * что и сама операция: откат операции откатывает и ключ, а параллельный повтор с тем же ключом
 * ждёт на уникальном индексе и затем получает сохранённый ответ, а не второй объект.
 */
final class IdempotencyService
{
    public const TTL_SECONDS = 86400;

    private IdempotencyRepository $repo;

    public function __construct(?IdempotencyRepository $repo = null)
    {
        $this->repo = $repo ?? new IdempotencyRepository();
    }

    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key);
    }

    /** Стабильный хэш нормализованного содержимого запроса. */
    public static function hash(array $normalized): string
    {
        $sort = static function (&$a) use (&$sort): void {
            if (is_array($a)) {
                ksort($a);
                foreach ($a as &$v) {
                    $sort($v);
                }
            }
        };
        $sort($normalized);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Дешёвая предварительная проверка (без блокировок и вне транзакции): если запрос с этим ключом уже выполнен,
     * возвращает сохранённый ответ, чтобы не обрабатывать повторно тяжёлые вложения (фото).
     * Авторитетной остаётся begin() внутри транзакции.
     */
    public function peek(int $userId, string $key, string $operation, string $hash): ?array
    {
        $row = $this->repo->find($userId, $key);
        if ($row === null || $row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            return null;
        }
        if ($row['request_hash'] !== $hash || $row['operation'] !== $operation) {
            throw new ApiException('IDEMPOTENCY_KEY_REUSED', 'Этот ключ уже использован для другого запроса. Сформируйте новый ключ.', 422);
        }
        $stored = $row['status'] === 'done' && $row['response_json'] !== null ? json_decode($row['response_json'], true) : null;
        return is_array($stored) && isset($stored['status']) ? $stored : null;
    }

    /**
     * @return array|null null — запрос новый, выполняйте операцию; массив — ранее сохранённый ответ
     *                    ['status'=>int,'data'=>mixed], его нужно вернуть без повторного выполнения.
     */
    public function begin(int $userId, string $key, string $operation, string $hash): ?array
    {
        $expires = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        try {
            $this->repo->insert($userId, $key, $operation, $hash, $expires);
            return null;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        $row = $this->repo->find($userId, $key);
        if ($row === null) {
            throw new ApiException('REQUEST_IN_PROGRESS', 'Запрос обрабатывается. Повторите через несколько секунд.', 409);
        }
        if ($row['expires_at'] < gmdate('Y-m-d H:i:s')) {       // срок хранения ключа истёк — ключ можно использовать заново
            $this->repo->reset((int) $row['id'], $operation, $hash, $expires);
            return null;
        }
        if ($row['request_hash'] !== $hash || $row['operation'] !== $operation) {
            throw new ApiException('IDEMPOTENCY_KEY_REUSED', 'Этот ключ уже использован для другого запроса. Сформируйте новый ключ.', 422);
        }
        $stored = $row['status'] === 'done' && $row['response_json'] !== null ? json_decode($row['response_json'], true) : null;
        if (!is_array($stored) || !isset($stored['status'])) {
            throw new ApiException('REQUEST_IN_PROGRESS', 'Запрос обрабатывается. Повторите через несколько секунд.', 409);
        }
        return $stored;
    }

    public function complete(int $userId, string $key, int $entityId, int $status, $data): void
    {
        $this->repo->complete($userId, $key, $entityId, json_encode(['status' => $status, 'data' => $data], JSON_UNESCAPED_UNICODE));
        if (random_int(1, 100) === 1) {
            $this->repo->purgeExpired();
        }
    }
}
