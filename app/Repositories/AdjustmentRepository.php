<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AdjustmentRepository
{
    public function insert(array $d): int
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO financial_adjustments (user_id, type, amount, affects_balance, work_record_id, payment_id, payroll_run_id, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $d['user_id'], $d['type'], $d['amount'], (int) $d['affects_balance'],
            $d['work_record_id'] ?? null, $d['payment_id'] ?? null, $d['payroll_run_id'] ?? null, $d['reason'], $d['created_by'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE fa.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private const SELECT = 'SELECT fa.*, u.full_name AS user_name, cb.full_name AS created_by_name
                            FROM financial_adjustments fa JOIN users u ON u.id = fa.user_id JOIN users cb ON cb.id = fa.created_by';

    /** @return array{0:array,1:int} */
    public function search(array $f, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        if (isset($f['user_id'])) {
            $where[] = 'fa.user_id = ?';
            $args[] = (int) $f['user_id'];
        }
        if (!empty($f['type'])) {
            $where[] = 'fa.type = ?';
            $args[] = $f['type'];
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM financial_adjustments fa' . $w);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $st = $pdo->prepare(self::SELECT . "{$w} ORDER BY fa.created_at DESC, fa.id DESC LIMIT {$limit} OFFSET {$offset}");
        $st->execute($args);
        return [$st->fetchAll(), $total];
    }

    /** Корректировки для расчётного листа: привязанные к расчёту и введённые администратором за его период. */
    public function forPayslip(int $userId, int $runId, string $fromUtc, string $toUtc): array
    {
        $st = Database::pdo()->prepare(
            self::SELECT . ' WHERE fa.user_id = ? AND (fa.payroll_run_id = ? OR (fa.affects_balance = 1 AND fa.created_at >= ? AND fa.created_at < ?))
                             ORDER BY fa.created_at, fa.id'
        );
        $st->execute([$userId, $runId, $fromUtc, $toUtc]);
        return $st->fetchAll();
    }
}
