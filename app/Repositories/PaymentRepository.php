<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PaymentRepository
{
    private const SELECT = 'SELECT p.*, u.full_name AS user_name, cb.full_name AS created_by_name
                            FROM payments p JOIN users u ON u.id = p.user_id JOIN users cb ON cb.id = p.created_by';

    public function insert(array $d): int
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            "INSERT INTO payments (user_id, payroll_run_id, amount, payment_method, payment_date, reference, comment, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        )->execute([$d['user_id'], $d['payroll_run_id'], $d['amount'], $d['payment_method'], $d['payment_date'], $d['reference'], $d['comment'], $d['created_by']]);
        return (int) $pdo->lastInsertId();
    }

    /** Карточка с именами (обычное чтение). */
    public function find(int $id): ?array
    {
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE p.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Строка выплаты с блокировкой (без join — блокируется только она). */
    public function lock(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Обычное чтение без блокировки (чтобы узнать расчёт и заблокировать в правильном порядке). */
    public function peek(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, payroll_run_id FROM payments WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function markPosted(int $id, string $atUtc): void
    {
        Database::pdo()->prepare("UPDATE payments SET status = 'posted', posted_at = ? WHERE id = ?")->execute([$atUtc, $id]);
    }

    public function markCancelled(int $id): void
    {
        Database::pdo()->prepare("UPDATE payments SET status = 'cancelled' WHERE id = ?")->execute([$id]);
    }

    /** @return array{0:array,1:int} */
    public function search(array $f, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        foreach (['user_id' => 'p.user_id', 'payroll_run_id' => 'p.payroll_run_id'] as $k => $col) {
            if (isset($f[$k])) {
                $where[] = "{$col} = ?";
                $args[] = (int) $f[$k];
            }
        }
        if (!empty($f['status'])) {
            $where[] = 'p.status = ?';
            $args[] = $f['status'];
        }
        if (!empty($f['only_posted'])) {
            $where[] = "p.status = 'posted'";
        }
        if (!empty($f['from_utc'])) {
            $where[] = 'p.payment_date >= ?';
            $args[] = $f['from_utc'];
        }
        if (!empty($f['to_utc'])) {
            $where[] = 'p.payment_date < ?';
            $args[] = $f['to_utc'];
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM payments p' . $w);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $st = $pdo->prepare(self::SELECT . "{$w} ORDER BY p.payment_date DESC, p.id DESC LIMIT {$limit} OFFSET {$offset}");
        $st->execute($args);
        return [$st->fetchAll(), $total];
    }

    // ---- распределения ----

    public function insertAllocation(int $paymentId, int $workRecordId, string $amount): void
    {
        Database::pdo()->prepare('INSERT INTO payment_allocations (payment_id, work_record_id, amount) VALUES (?, ?, ?)')
            ->execute([$paymentId, $workRecordId, $amount]);
    }

    /** Только для черновика: распределение ещё не является финансовым фактом. */
    public function deleteAllocations(int $paymentId): void
    {
        Database::pdo()->prepare('DELETE FROM payment_allocations WHERE payment_id = ?')->execute([$paymentId]);
    }

    public function allocations(int $paymentId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT pa.id, pa.payment_id, pa.work_record_id, pa.amount, pa.created_at,
                    wt.name AS work_type_name, d.number AS door_number, wr.completed_at, wr.total_amount, wr.voided_at
             FROM payment_allocations pa
             JOIN work_records wr ON wr.id = pa.work_record_id
             JOIN work_types wt ON wt.id = wr.work_type_id
             LEFT JOIN doors d ON d.id = wr.door_id
             WHERE pa.payment_id = ? ORDER BY wr.completed_at, pa.id'
        );
        $st->execute([$paymentId]);
        return $st->fetchAll();
    }

    /**
     * Блокирует строки работ (в порядке id — против взаимных блокировок) и возвращает их.
     *
     * @param int[] $ids
     * @return array<int,array>
     */
    public function lockRecords(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $in = implode(',', $ids);
        $rows = Database::pdo()->query("SELECT id, user_id, total_amount, voided_at FROM work_records WHERE id IN ({$in}) ORDER BY id FOR UPDATE")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /**
     * Уже подтверждённые распределения по работам. Блокирующее чтение: видит последнее зафиксированное состояние
     * независимо от снимка транзакции, поэтому две параллельные выплаты не покроют один остаток дважды.
     *
     * @param int[] $ids
     * @return array<int,string>
     */
    public function postedSumsLocked(array $ids): array
    {
        $in = implode(',', array_map('intval', $ids));
        $st = Database::pdo()->query(
            "SELECT pa.work_record_id, SUM(pa.amount) AS s FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
             WHERE p.status = 'posted' AND pa.work_record_id IN ({$in}) GROUP BY pa.work_record_id FOR UPDATE"
        );
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['work_record_id']] = (string) $r['s'];
        }
        return $out;
    }
}
