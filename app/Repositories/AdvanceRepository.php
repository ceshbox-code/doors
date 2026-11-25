<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use RuntimeException;

final class AdvanceRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * Фиксирует выдачу аванса по подтверждённой выплате.
     * Для одного платежа допускается только одна операция issue.
     */
    public function issue(
        int $userId,
        int $paymentId,
        float $amount,
        int $createdBy,
        ?string $reason = null
    ): int {
        if ($amount <= 0) {
            throw new RuntimeException('Сумма аванса должна быть больше нуля');
        }

        $st = $this->pdo()->prepare(
            "INSERT INTO employee_advance_ledger
                (user_id, type, amount, payment_id, created_by, reason)
             VALUES (?, 'issue', ?, ?, ?, ?)"
        );
        $st->execute([
            $userId,
            number_format($amount, 2, '.', ''),
            $paymentId,
            $createdBy,
            $reason,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Зачитывает доступный аванс в счёт новой работы.
     * Очерёдность выдач — FIFO по id.
     *
     * Вызывающий код обязан предварительно заблокировать строку users
     * соответствующего сотрудника через SELECT ... FOR UPDATE.
     */
    public function applyToWork(
        int $userId,
        int $workRecordId,
        float $workAmount,
        int $createdBy
    ): float {
        if ($workAmount <= 0) {
            return 0.0;
        }

        $pdo = $this->pdo();

        $st = $pdo->prepare(
            "SELECT id
               FROM employee_advance_ledger
              WHERE user_id = ?
                AND type = 'issue'
              ORDER BY id
              FOR UPDATE"
        );
        $st->execute([$userId]);
        $issueIds = $st->fetchAll(PDO::FETCH_COLUMN);

        // Внутренние денежные вычисления выполняются в целых копейках.
        $remainingCents = (int) round($workAmount * 100);
        $appliedCents = 0;

        foreach ($issueIds as $issueId) {
            if ($remainingCents <= 0) {
                break;
            }

            $availableCents = (int) round(
                $this->issueBalance((int) $issueId) * 100
            );

            if ($availableCents <= 0) {
                continue;
            }

            $partCents = min($availableCents, $remainingCents);

            if ($partCents <= 0) {
                continue;
            }

            $insert = $pdo->prepare(
                "INSERT INTO employee_advance_ledger
                    (user_id, type, amount, work_record_id,
                     source_issue_id, created_by, reason)
                 VALUES (?, 'apply', ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $userId,
                number_format($partCents / 100, 2, '.', ''),
                $workRecordId,
                (int) $issueId,
                $createdBy,
                'Автоматический зачёт аванса за работу',
            ]);

            $remainingCents -= $partCents;
            $appliedCents += $partCents;
        }

        return $appliedCents / 100;
    }

    /**
     * Остаток конкретной выдачи:
     * issue - apply + reverse_apply - reverse_issue.
     */
    public function issueBalance(int $issueId): float
    {
        $st = $this->pdo()->prepare(
            "SELECT
                i.amount
                + COALESCE(SUM(
                    CASE l.type
                        WHEN 'apply' THEN -l.amount
                        WHEN 'reverse_apply' THEN l.amount
                        WHEN 'reverse_issue' THEN -l.amount
                        ELSE 0
                    END
                ), 0) AS balance
             FROM employee_advance_ledger i
             LEFT JOIN employee_advance_ledger l
                    ON l.source_issue_id = i.id
             WHERE i.id = ?
               AND i.type = 'issue'
             GROUP BY i.id, i.amount"
        );
        $st->execute([$issueId]);

        $value = $st->fetchColumn();

        return $value === false ? 0.0 : round((float) $value, 2);
    }

    /**
     * Возвращает весь неиспользованный остаток авансов сотрудника.
     */
    public function balance(int $userId): float
    {
        $st = $this->pdo()->prepare(
            "SELECT COALESCE(SUM(
                CASE type
                    WHEN 'issue' THEN amount
                    WHEN 'apply' THEN -amount
                    WHEN 'reverse_apply' THEN amount
                    WHEN 'reverse_issue' THEN -amount
                    ELSE 0
                END
            ), 0)
             FROM employee_advance_ledger
             WHERE user_id = ?"
        );
        $st->execute([$userId]);

        return round((float) $st->fetchColumn(), 2);
    }

    /**
     * Сторнирует все ещё активные зачёты конкретной выдачи.
     */
    private function reverseApplicationsForIssue(
        int $issueId,
        int $createdBy,
        string $reason
    ): void {
        $pdo = $this->pdo();

        $st = $pdo->prepare(
            "SELECT a.id, a.user_id, a.amount
               FROM employee_advance_ledger a
              WHERE a.type = 'apply'
                AND a.source_issue_id = ?
                AND NOT EXISTS (
                    SELECT 1
                      FROM employee_advance_ledger r
                     WHERE r.reversal_of_id = a.id
                )
              ORDER BY a.id
              FOR UPDATE"
        );
        $st->execute([$issueId]);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $application) {
            $insert = $pdo->prepare(
                "INSERT INTO employee_advance_ledger
                    (user_id, type, amount, source_issue_id,
                     reversal_of_id, created_by, reason)
                 VALUES (?, 'reverse_apply', ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                (int) $application['user_id'],
                $application['amount'],
                $issueId,
                (int) $application['id'],
                $createdBy,
                $reason,
            ]);
        }
    }

    /**
     * Отменяет выдачу аванса по платежу:
     * сначала возвращает все зачёты, затем сторнирует выдачу.
     */
    public function reverseForPayment(
        int $paymentId,
        int $createdBy,
        string $reason = 'Отмена выплаты'
    ): void {
        $pdo = $this->pdo();

        $st = $pdo->prepare(
            "SELECT id, user_id, amount
               FROM employee_advance_ledger
              WHERE type = 'issue'
                AND payment_id = ?
              FOR UPDATE"
        );
        $st->execute([$paymentId]);
        $issue = $st->fetch(PDO::FETCH_ASSOC);

        if (!$issue) {
            return;
        }

        $issueId = (int) $issue['id'];

        $this->reverseApplicationsForIssue($issueId, $createdBy, $reason);

        $check = $pdo->prepare(
            "SELECT id
               FROM employee_advance_ledger
              WHERE reversal_of_id = ?
              LIMIT 1"
        );
        $check->execute([$issueId]);

        if ($check->fetchColumn()) {
            return;
        }

        $insert = $pdo->prepare(
            "INSERT INTO employee_advance_ledger
                (user_id, type, amount, source_issue_id,
                 reversal_of_id, created_by, reason)
             VALUES (?, 'reverse_issue', ?, ?, ?, ?, ?)"
        );
        $insert->execute([
            (int) $issue['user_id'],
            $issue['amount'],
            $issueId,
            $issueId,
            $createdBy,
            $reason,
        ]);
    }

    /**
     * Возвращает аванс, зачтённый в счёт аннулируемой работы.
     */
    public function reverseForWork(
        int $workRecordId,
        int $createdBy,
        string $reason = 'Аннулирование работы'
    ): void {
        $pdo = $this->pdo();

        $st = $pdo->prepare(
            "SELECT id, user_id, amount, source_issue_id
               FROM employee_advance_ledger a
              WHERE a.type = 'apply'
                AND a.work_record_id = ?
                AND NOT EXISTS (
                    SELECT 1
                      FROM employee_advance_ledger r
                     WHERE r.reversal_of_id = a.id
                )
              ORDER BY a.id
              FOR UPDATE"
        );
        $st->execute([$workRecordId]);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $application) {
            $insert = $pdo->prepare(
                "INSERT INTO employee_advance_ledger
                    (user_id, type, amount, work_record_id,
                     source_issue_id, reversal_of_id, created_by, reason)
                 VALUES (?, 'reverse_apply', ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                (int) $application['user_id'],
                $application['amount'],
                $workRecordId,
                (int) $application['source_issue_id'],
                (int) $application['id'],
                $createdBy,
                $reason,
            ]);
        }
    }
}
