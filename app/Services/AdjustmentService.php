<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Tx;
use App\Models\Adjustment;
use App\Repositories\AdjustmentRepository;
use App\Repositories\PayrollRepository;
use App\Repositories\UserRepository;
use App\Validators\FinanceValidator;

/**
 * Ручные финансовые корректировки (ТЗ 6.4 п.7, 6.5, 11.12): ошибки исправляются не правкой исходных записей,
 * а отдельной записью с причиной. Знак: «+» увеличивает долг перед исполнителем, «−» уменьшает.
 * Начальные остатки при внедрении вводятся так же (тип manual).
 */
final class AdjustmentService
{
    private AdjustmentRepository $adjustments;
    private UserRepository $users;
    private PayrollRepository $payroll;
    private AuditService $audit;

    public function __construct()
    {
        $this->adjustments = new AdjustmentRepository();
        $this->users = new UserRepository();
        $this->payroll = new PayrollRepository();
        $this->audit = new AuditService();
    }

    public function create(array $actor, array $input, string $ip): array
    {
        $d = FinanceValidator::adjustment($input);
        return Tx::run(function () use ($actor, $d, $ip) {
            $user = $this->users->find($d['user_id']);
            if ($user === null || !in_array($user['role'], ['master', 'owner'], true)) {
                throw new ApiException('USER_NOT_FOUND', 'Исполнитель не найден', 404);
            }
            if ($d['work_record_id'] !== null) {
                $st = Database::pdo()->prepare('SELECT user_id FROM work_records WHERE id = ?');
                $st->execute([$d['work_record_id']]);
                $uid = $st->fetchColumn();
                if ($uid === false || (int) $uid !== $d['user_id']) {
                    throw new ApiException('VALIDATION_ERROR', 'Работа не найдена или принадлежит другому исполнителю', 422, ['work_record_id' => 'Некорректная работа']);
                }
            }
            if ($d['payroll_run_id'] !== null) {
                $run = $this->payroll->find($d['payroll_run_id']);
                if ($run === null || (int) $run['user_id'] !== $d['user_id']) {
                    throw new ApiException('VALIDATION_ERROR', 'Расчёт не найден или принадлежит другому исполнителю', 422, ['payroll_run_id' => 'Некорректный расчёт']);
                }
            }
            $id = $this->adjustments->insert($d + ['affects_balance' => 1, 'created_by' => (int) $actor['id']]);
            $this->audit->log((int) $actor['id'], 'adjustment.create', 'financial_adjustment', $id, null, [
                'user_id' => $d['user_id'], 'type' => $d['type'], 'amount' => $d['amount'],
                'work_record_id' => $d['work_record_id'], 'payroll_run_id' => $d['payroll_run_id'],
            ], $d['reason'], $ip);
            return Adjustment::present($this->adjustments->find($id));
        });
    }
}
