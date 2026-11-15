<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RateRepository
{
    private const SELECT = 'SELECT r.*, u.full_name AS user_name, wt.name AS work_type_name
                            FROM work_rates r
                            JOIN users u ON u.id = r.user_id
                            JOIN work_types wt ON wt.id = r.work_type_id';

    public function find(int $id, bool $forUpdate = false): ?array
    {
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE r.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Блокирует и возвращает все расценки пары «исполнитель — вид работы», новые первыми. */
    public function lockPair(int $userId, int $typeId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM work_rates WHERE user_id = ? AND work_type_id = ? ORDER BY valid_from DESC, id DESC FOR UPDATE'
        );
        $st->execute([$userId, $typeId]);
        return $st->fetchAll();
    }

    /** Расценка, действующая в момент $atUtc. */
    public function activeAt(int $userId, int $typeId, string $atUtc): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM work_rates
             WHERE user_id = ? AND work_type_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to > ?)
             ORDER BY valid_from DESC, id DESC LIMIT 1'
        );
        $st->execute([$userId, $typeId, $atUtc, $atUtc]);
        return $st->fetch() ?: null;
    }

    public function insert(int $userId, int $typeId, string $price, string $validFrom, int $createdBy): int
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO work_rates (user_id, work_type_id, unit_price, valid_from, valid_to, created_by)
             VALUES (?, ?, ?, ?, NULL, ?)'
        )->execute([$userId, $typeId, $price, $validFrom, $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public function setValidTo(int $id, string $validTo): void
    {
        Database::pdo()->prepare('UPDATE work_rates SET valid_to = ? WHERE id = ?')->execute([$validTo, $id]);
    }

    /** @return array{0:array,1:int} */
    public function search(array $f, int $limit, int $offset, bool $newestCreatedFirst = false): array
    {
        $where = [];
        $args = [];
        foreach (['user_id' => 'r.user_id', 'work_type_id' => 'r.work_type_id'] as $key => $col) {
            if (isset($f[$key])) {
                $where[] = "{$col} = ?";
                $args[] = (int) $f[$key];
            }
        }
        if (!empty($f['current_at'])) {
            $where[] = 'r.valid_from <= ? AND (r.valid_to IS NULL OR r.valid_to > ?)';
            $args[] = $f['current_at'];
            $args[] = $f['current_at'];
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM work_rates r' . $w);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $order = $newestCreatedFirst ? 'r.created_at DESC, r.id DESC' : 'u.full_name, wt.name, r.valid_from DESC, r.id DESC';
        $st = $pdo->prepare(self::SELECT . "{$w} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}");
        $st->execute($args);
        return [$st->fetchAll(), $total];
    }
}
