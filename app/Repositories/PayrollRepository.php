<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PayrollRepository
{
    private const SELECT = 'SELECT r.*, u.full_name AS user_name, cb.full_name AS created_by_name, cf.full_name AS confirmed_by_name
                            FROM payroll_runs r
                            JOIN users u ON u.id = r.user_id
                            JOIN users cb ON cb.id = r.created_by
                            LEFT JOIN users cf ON cf.id = r.confirmed_by';

    public function find(int $id): ?array
    {
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE r.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Строка расчёта с блокировкой (без join). */
    public function lock(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM payroll_runs WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array{0:array,1:int} */
    public function search(array $f, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        if (isset($f['user_id'])) {
            $where[] = 'r.user_id = ?';
            $args[] = (int) $f['user_id'];
        }
        if (!empty($f['status'])) {
            $where[] = 'r.status = ?';
            $args[] = $f['status'];
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM payroll_runs r' . $w);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $st = $pdo->prepare(self::SELECT . "{$w} ORDER BY r.created_at DESC, r.id DESC LIMIT {$limit} OFFSET {$offset}");
        $st->execute($args);
        return [$st->fetchAll(), $total];
    }

    public function insertRun(int $userId, string $from, string $to, string $total, int $createdBy): int
    {
        $pdo = Database::pdo();
        $pdo->prepare("INSERT INTO payroll_runs (user_id, period_from, period_to, total_amount, status, created_by) VALUES (?, ?, ?, ?, 'draft', ?)")
            ->execute([$userId, $from, $to, $total, $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public function insertItem(int $runId, array $r): void
    {
        Database::pdo()->prepare(
            'INSERT INTO payroll_run_items (payroll_run_id, work_record_id, work_type_name, door_number, quantity, unit, unit_price, total_amount, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$runId, $r['id'], $r['work_type_name'], $r['door_number'], $r['quantity'], $r['unit'], $r['unit_price'], $r['total_amount']]);
    }

    /** Блокирует и возвращает id работ периода, ещё не включённых в действующие расчёты. @return int[] */
    public function lockFreeRecordIds(int $userId, string $fromUtc, string $toUtc): array
    {
        $st = Database::pdo()->prepare(
            'SELECT wr.id FROM work_records wr
             WHERE wr.user_id = ? AND wr.voided_at IS NULL AND wr.completed_at >= ? AND wr.completed_at < ?
               AND NOT EXISTS (SELECT 1 FROM payroll_run_items pri WHERE pri.work_record_id = wr.id AND pri.is_active = 1)
             ORDER BY wr.completed_at, wr.id FOR UPDATE'
        );
        $st->execute([$userId, $fromUtc, $toUtc]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Сколько работ периода уже входит в другие действующие расчёты. */
    public function countInOtherRuns(int $userId, string $fromUtc, string $toUtc): int
    {
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM work_records wr
             WHERE wr.user_id = ? AND wr.voided_at IS NULL AND wr.completed_at >= ? AND wr.completed_at < ?
               AND EXISTS (SELECT 1 FROM payroll_run_items pri WHERE pri.work_record_id = wr.id AND pri.is_active = 1)'
        );
        $st->execute([$userId, $fromUtc, $toUtc]);
        return (int) $st->fetchColumn();
    }

    /** Данные работ для снимка. @param int[] $ids @return array<int,array> */
    public function recordsForSnapshot(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        return Database::pdo()->query(
            "SELECT wr.id, wr.quantity, wr.unit, wr.unit_price, wr.total_amount, wr.completed_at,
                    wt.name AS work_type_name, d.number AS door_number
             FROM work_records wr JOIN work_types wt ON wt.id = wr.work_type_id LEFT JOIN doors d ON d.id = wr.door_id
             WHERE wr.id IN ({$in}) ORDER BY wr.completed_at, wr.id"
        )->fetchAll();
    }

    /** Позиции расчёта с текущей оплатой и признаком аннулирования работы. */
    public function items(int $runId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT pri.*, wr.voided_at, wr.completed_at,
                    COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                              WHERE pa.work_record_id = pri.work_record_id AND p.status = 'posted'), 0) AS paid
             FROM payroll_run_items pri JOIN work_records wr ON wr.id = pri.work_record_id
             WHERE pri.payroll_run_id = ? ORDER BY wr.completed_at, pri.id"
        );
        $st->execute([$runId]);
        return $st->fetchAll();
    }

    public function setConfirmed(int $id, int $by, string $atUtc): void
    {
        Database::pdo()->prepare("UPDATE payroll_runs SET status = 'confirmed', confirmed_by = ?, confirmed_at = ? WHERE id = ?")->execute([$by, $atUtc, $id]);
    }

    /** Отмена: позиции освобождаются для нового расчёта (is_active = 0), история остаётся. */
    public function setCancelled(int $id): void
    {
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE payroll_runs SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        $pdo->prepare('UPDATE payroll_run_items SET is_active = 0 WHERE payroll_run_id = ?')->execute([$id]);
    }

    /** Есть ли у расчёта невыплаченные-неотменённые выплаты (черновики или подтверждённые). */
    public function liveIssuesPayments(int $runId): array
    {
        $st = Database::pdo()->prepare("SELECT id, status FROM payments WHERE payroll_run_id = ? AND status IN ('draft', 'posted')");
        $st->execute([$runId]);
        return $st->fetchAll();
    }

    /** Действующий (не отменённый) расчёт, в который включена работа. */
    public function activeRunForRecord(int $recordId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT r.id AS payroll_run_id, r.status FROM payroll_run_items pri JOIN payroll_runs r ON r.id = pri.payroll_run_id
             WHERE pri.work_record_id = ? AND pri.is_active = 1 LIMIT 1'
        );
        $st->execute([$recordId]);
        return $st->fetch() ?: null;
    }

    public function isActiveItem(int $runId, int $recordId): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM payroll_run_items WHERE payroll_run_id = ? AND work_record_id = ? AND is_active = 1');
        $st->execute([$runId, $recordId]);
        return (bool) $st->fetchColumn();
    }
}
