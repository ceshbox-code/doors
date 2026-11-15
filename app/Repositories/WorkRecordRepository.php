<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class WorkRecordRepository
{
    private const SELECT = 'SELECT wr.id, wr.work_type_id, wr.door_id, wr.user_id, wr.rate_id, wr.quantity, wr.unit,
                                   wr.unit_price, wr.total_amount, wr.completed_at, wr.created_at, wr.created_by,
                                   wr.comment, wr.voided_at, wr.voided_by, wr.void_reason,
                                   (SELECT COUNT(*) FROM photos ph WHERE ph.work_record_id = wr.id AND ph.deleted_at IS NULL) AS photos_count,
                                   wt.name AS work_type_name, d.number AS door_number,
                                   u.full_name AS user_name, cb.full_name AS created_by_name, vb.full_name AS voided_by_name
                            FROM work_records wr
                            JOIN work_types wt ON wt.id = wr.work_type_id
                            LEFT JOIN doors d ON d.id = wr.door_id
                            JOIN users u ON u.id = wr.user_id
                            JOIN users cb ON cb.id = wr.created_by
                            LEFT JOIN users vb ON vb.id = wr.voided_by';

    public function insert(array $d): int
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO work_records
                (work_type_id, door_id, user_id, rate_id, quantity, unit, unit_price, total_amount,
                 allow_unique_per_door, completed_at, created_by, comment)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $d['work_type_id'], $d['door_id'], $d['user_id'], $d['rate_id'], $d['quantity'], $d['unit'],
            $d['unit_price'], $d['total_amount'], $d['allow_unique_per_door'], $d['completed_at'],
            $d['created_by'], $d['comment'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function findDetailed(int $id, bool $forUpdate = false): ?array
    {
        // FOR UPDATE блокирует строки всех join-таблиц; блокируем только запись работы отдельно
        if ($forUpdate) {
            $st = Database::pdo()->prepare('SELECT id FROM work_records WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            if (!$st->fetch()) {
                return null;
            }
        }
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE wr.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Действующая (не аннулированная) запись того же вида для той же двери. */
    public function findActiveDuplicate(int $typeId, int $doorId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT id, completed_at FROM work_records WHERE work_type_id = ? AND door_id = ? AND voided_at IS NULL LIMIT 1'
        );
        $st->execute([$typeId, $doorId]);
        return $st->fetch() ?: null;
    }

    /** @return array{0:array,1:int} */
    public function search(array $f, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        foreach (['user_id' => 'wr.user_id', 'work_type_id' => 'wr.work_type_id', 'door_id' => 'wr.door_id'] as $key => $col) {
            if (isset($f[$key])) {
                $where[] = "{$col} = ?";
                $args[] = (int) $f[$key];
            }
        }
        if (!empty($f['from_utc'])) {
            $where[] = 'wr.completed_at >= ?';
            $args[] = $f['from_utc'];
        }
        if (!empty($f['to_utc'])) {
            $where[] = 'wr.completed_at < ?';
            $args[] = $f['to_utc'];
        }
        $status = $f['status'] ?? 'active';
        if ($status === 'active') {
            $where[] = 'wr.voided_at IS NULL';
        } elseif ($status === 'voided') {
            $where[] = 'wr.voided_at IS NOT NULL';
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM work_records wr' . $w);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $order = ($f['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $st = $pdo->prepare(self::SELECT . "{$w} ORDER BY wr.completed_at {$order}, wr.id {$order} LIMIT {$limit} OFFSET {$offset}");
        $st->execute($args);
        return [$st->fetchAll(), $total];
    }

    public function markVoided(int $id, int $by, string $reason, string $atUtc): void
    {
        Database::pdo()->prepare('UPDATE work_records SET voided_at = ?, voided_by = ?, void_reason = ? WHERE id = ?')
            ->execute([$atUtc, $by, $reason, $id]);
    }

    /** Работа входит в действующий расчётный период? */
    public function inActivePayroll(int $id): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM payroll_run_items WHERE work_record_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    /** По работе есть распределения выплат (кроме отменённых выплат)? */
    public function hasPostedAllocation(int $id): bool
    {
        $st = Database::pdo()->prepare(
            "SELECT 1 FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
             WHERE pa.work_record_id = ? AND p.status <> 'cancelled' LIMIT 1"
        );
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }
}
