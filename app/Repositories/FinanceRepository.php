<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Чтение финансовых показателей. Деньги — только DECIMAL-строки, арифметику выполняет Money (bcmath). */
final class FinanceRepository
{
    /**
     * Остатки к оплате по начислениям получателя (только действующие работы), от раннего к позднему.
     * Если задан $runId — только работы этого расчёта.
     *
     * @return array<int,array{id:int,total_amount:string,paid:string,outstanding:string,completed_at:string}>
     */
    public function outstandingForUser(int $userId, ?int $runId = null): array
    {
        $args = [$userId, $userId];
        $runSql = '';
        if ($runId !== null) {
            $runSql = ' AND wr.id IN (SELECT work_record_id FROM payroll_run_items WHERE payroll_run_id = ? AND is_active = 1)';
            $args[] = $runId;
        }
        $st = Database::pdo()->prepare(
            "SELECT wr.id, wr.total_amount, wr.completed_at,
                    COALESCE(x.paid, 0) AS paid,
                    wr.total_amount - COALESCE(x.paid, 0) AS outstanding
             FROM work_records wr
             LEFT JOIN (
                 SELECT pa.work_record_id, SUM(pa.amount) AS paid
                 FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                 WHERE p.status = 'posted' AND p.user_id = ?
                 GROUP BY pa.work_record_id
             ) x ON x.work_record_id = wr.id
             WHERE wr.user_id = ? AND wr.voided_at IS NULL
               AND wr.total_amount - COALESCE(x.paid, 0) > 0 {$runSql}
             ORDER BY wr.completed_at, wr.id"
        );
        $st->execute($args);
        return $st->fetchAll();
    }

    /**
     * Итоги по исполнителям: начислено / выплачено / корректировки. Границы — UTC-момент (включительно от, не включая до):
     * начисления по completed_at, выплаты по payment_date, корректировки по created_at.
     *
     * @param int[]|null $userIds null — все исполнители (мастера и владельцы с активностью)
     * @return array<int,array<string,mixed>> по user_id
     */
    public function totals(?array $userIds = null, ?string $fromUtc = null, ?string $toUtc = null, bool $onlyWithActivity = true): array
    {
        $acc = $pay = $adj = '';
        $accArgs = $payArgs = $adjArgs = [];
        foreach ([[$fromUtc, '>='], [$toUtc, '<']] as [$bound, $op]) {
            if ($bound !== null) {
                $acc .= " AND wr.completed_at {$op} ?";  $accArgs[] = $bound;
                $pay .= " AND p.payment_date {$op} ?";   $payArgs[] = $bound;
                $adj .= " AND fa.created_at {$op} ?";    $adjArgs[] = $bound;
            }
        }
        $where = "u.role IN ('master','owner')";
        $whereArgs = [];
        if ($userIds !== null) {
            if (!$userIds) {
                return [];
            }
            $where .= ' AND u.id IN (' . implode(',', array_map('intval', $userIds)) . ')';
        }
        if ($onlyWithActivity && $userIds === null) {
            $where .= " AND (u.role = 'master' OR EXISTS (SELECT 1 FROM work_records w WHERE w.user_id = u.id)
                             OR EXISTS (SELECT 1 FROM payments pp WHERE pp.user_id = u.id))";
        }
        $sql = "SELECT u.id AS user_id, u.full_name, u.role, u.is_active,
                  COALESCE((SELECT SUM(wr.total_amount) FROM work_records wr WHERE wr.user_id = u.id AND wr.voided_at IS NULL{$acc}), 0) AS accrued,
                  (SELECT COUNT(*) FROM work_records wr WHERE wr.user_id = u.id AND wr.voided_at IS NULL{$acc}) AS works_count,
                  COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                            WHERE p.user_id = u.id AND p.status = 'posted'{$pay}), 0) AS paid,
                  COALESCE((SELECT SUM(fa.amount) FROM financial_adjustments fa WHERE fa.user_id = u.id AND fa.affects_balance = 1{$adj}), 0) AS adjustments
                FROM users u WHERE {$where} ORDER BY u.full_name, u.id";
        $st = Database::pdo()->prepare($sql);
        $st->execute(array_merge($accArgs, $accArgs, $payArgs, $adjArgs, $whereArgs));
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['user_id']] = $r;
        }
        return $out;
    }

    /**
     * Начисления (работы) с оплатой и остатком.
     *
     * @return array{0:array,1:int}
     */
    public function accruals(array $f, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        if (isset($f['user_id'])) {
            $where[] = 'wr.user_id = ?';
            $args[] = (int) $f['user_id'];
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
        if ($status === 'voided') {
            $where[] = 'wr.voided_at IS NOT NULL';
        } elseif ($status !== 'all') {
            $where[] = 'wr.voided_at IS NULL';
        }
        $pay = $f['payment_state'] ?? 'all';
        if ($pay === 'unpaid') {
            $where[] = 'COALESCE(x.paid, 0) = 0';
        } elseif ($pay === 'partial') {
            $where[] = 'COALESCE(x.paid, 0) > 0 AND COALESCE(x.paid, 0) < wr.total_amount';
        } elseif ($pay === 'paid') {
            $where[] = 'COALESCE(x.paid, 0) >= wr.total_amount';
        } elseif ($pay === 'open') {
            $where[] = 'COALESCE(x.paid, 0) < wr.total_amount';
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $from = "FROM work_records wr
                 JOIN work_types wt ON wt.id = wr.work_type_id
                 LEFT JOIN doors d ON d.id = wr.door_id
                 JOIN users u ON u.id = wr.user_id
                 LEFT JOIN (
                     SELECT pa.work_record_id, SUM(pa.amount) AS paid
                     FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                     WHERE p.status = 'posted' GROUP BY pa.work_record_id
                 ) x ON x.work_record_id = wr.id";
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT COUNT(*) {$from}{$w}");
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $st = $pdo->prepare(
            "SELECT wr.id, wr.user_id, u.full_name AS user_name, wr.work_type_id, wt.name AS work_type_name, wr.door_id,
                    d.number AS door_number, wr.quantity, wr.unit, wr.unit_price, wr.total_amount, wr.completed_at,
                    wr.voided_at, COALESCE(x.paid, 0) AS paid,
                    (SELECT pri.payroll_run_id FROM payroll_run_items pri WHERE pri.work_record_id = wr.id AND pri.is_active = 1 LIMIT 1) AS payroll_run_id
             {$from}{$w} ORDER BY wr.completed_at DESC, wr.id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $st->execute($args);
        return [$st->fetchAll(), $total];
    }

    /** Сумма подтверждённых распределений по работе (блокирующее чтение — для аннулирования). */
    public function postedAllocatedSumLocked(int $workRecordId): string
    {
        $st = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(pa.amount), 0) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
             WHERE pa.work_record_id = ? AND p.status = 'posted' FOR UPDATE"
        );
        $st->execute([$workRecordId]);
        return (string) $st->fetchColumn();
    }
}
