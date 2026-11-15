<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Permissions;
use App\Core\Tx;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Money;
use App\Models\PayrollRun;
use App\Repositories\PayrollRepository;
use App\Repositories\UserRepository;
use App\Validators\FinanceValidator;
use App\Validators\WorkRecordValidator;
use PDOException;

/**
 * Расчёты за период (ТЗ 6.3 режим Б, 6.5). Черновик формируется из действующих работ периода и сразу занимает их
 * (одна работа — не более чем в одном действующем расчёте). Подтверждённый расчёт — неизменяемый снимок.
 */
final class PayrollService
{
    private PayrollRepository $payroll;
    private UserRepository $users;
    private AuditService $audit;

    public function __construct()
    {
        $this->payroll = new PayrollRepository();
        $this->users = new UserRepository();
        $this->audit = new AuditService();
    }

    /** Расчёт с позициями и итогами. Исполнитель видит только свои подтверждённые расчёты. */
    public function detail(int $id, ?array $viewer = null): array
    {
        $run = $id > 0 ? $this->payroll->find($id) : null;
        if ($run === null || ($viewer !== null && !$this->canView($viewer, $run))) {
            throw new ApiException('NOT_FOUND', 'Расчёт не найден', 404);
        }
        return $this->present($run);
    }

    private function canView(array $viewer, array $run): bool
    {
        if (Permissions::can($viewer['role'], 'finance.view_all')) {
            return true;
        }
        return Permissions::can($viewer['role'], 'finance.view_own')
            && (int) $run['user_id'] === (int) $viewer['id'] && $run['status'] === 'confirmed';
    }

    private function present(array $run): array
    {
        $items = $this->payroll->items((int) $run['id']);
        $voidedTotal = $paid = $overOnVoided = '0.00';
        $presented = [];
        foreach ($items as $it) {
            $voided = $it['voided_at'] !== null;
            $itemPaid = bcadd((string) $it['paid'], '0', 2);
            if ($voided) {
                $voidedTotal = Money::add($voidedTotal, (string) $it['total_amount']);
                $overOnVoided = Money::add($overOnVoided, $itemPaid);
            } else {
                $paid = Money::add($paid, $itemPaid);
            }
            $presented[] = [
                'id'             => (int) $it['id'],
                'work_record_id' => (int) $it['work_record_id'],
                'work_type_name' => $it['work_type_name'],
                'door_number'    => $it['door_number'],
                'quantity'       => $it['quantity'],
                'unit'           => $it['unit'],
                'unit_price'     => $it['unit_price'],
                'total_amount'   => $it['total_amount'],
                'completed_at'   => Dates::toIso($it['completed_at']),
                'paid'           => $itemPaid,
                'outstanding'    => $voided ? '0.00' : Money::positive(Money::sub((string) $it['total_amount'], $itemPaid)),
                'voided'         => $voided,
            ];
        }
        $current = Money::sub((string) $run['total_amount'], $voidedTotal);
        return PayrollRun::present($run) + [
            'items'  => $presented,
            'totals' => [
                'snapshot_total'      => bcadd((string) $run['total_amount'], '0', 2),   // сумма на момент подтверждения — не меняется
                'voided_total'        => $voidedTotal,
                'current_total'       => $current,
                'paid'                => $paid,
                'remaining'           => Money::positive(Money::sub($current, $paid)),
                'paid_on_voided_work' => $overOnVoided,
            ],
        ];
    }

    public function list(array $viewer, array $query): array
    {
        $f = [];
        if (!Permissions::can($viewer['role'], 'finance.view_all')) {
            $f['user_id'] = (int) $viewer['id'];
            $f['status'] = 'confirmed';
        } else {
            if (isset($query['user_id']) && ($u = Input::id($query['user_id'])) !== null) {
                $f['user_id'] = $u;
            }
            if (isset($query['status']) && in_array($query['status'], ['draft', 'confirmed', 'cancelled'], true)) {
                $f['status'] = $query['status'];
            }
        }
        [$page, $per] = Input::paging($query, 50);
        [$rows, $total] = $this->payroll->search($f, $per, ($page - 1) * $per);
        return ['items' => array_map([PayrollRun::class, 'present'], $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    public function create(array $actor, array $input, string $ip): array
    {
        $d = FinanceValidator::payrollRun($input);
        $tz = (string) Config::get('app.timezone', 'UTC');
        $fromUtc = Dates::localDayToUtc($d['period_from'], $tz);
        $toUtc = Dates::localDayToUtc($d['period_to'], $tz, true);

        return Tx::run(function () use ($actor, $d, $fromUtc, $toUtc, $ip) {
            $user = $this->users->find($d['user_id']);
            if ($user === null) {
                throw new ApiException('USER_NOT_FOUND', 'Исполнитель не найден', 404);
            }
            if (!in_array($user['role'], ['master', 'owner'], true)) {
                throw new ApiException('INVALID_EXECUTOR', 'Расчёт формируется только для исполнителей работ', 422);
            }
            // Блокируем работы периода: параллельное аннулирование или другой расчёт дождутся нас
            $ids = $this->payroll->lockFreeRecordIds($d['user_id'], $fromUtc, $toUtc);
            $other = $this->payroll->countInOtherRuns($d['user_id'], $fromUtc, $toUtc);
            if (!$ids) {
                throw new ApiException('NO_WORKS_IN_PERIOD', 'За период нет действующих работ, не включённых в другие расчёты', 422, ['already_in_other_runs' => $other]);
            }
            $records = $this->payroll->recordsForSnapshot($ids);
            $total = '0.00';
            foreach ($records as $r) {
                $total = Money::add($total, (string) $r['total_amount']);
            }
            $runId = $this->payroll->insertRun($d['user_id'], $d['period_from'], $d['period_to'], $total, (int) $actor['id']);
            try {
                foreach ($records as $r) {
                    $this->payroll->insertItem($runId, $r);
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ApiException('RUN_CONFLICT', 'Работы одновременно включены в другой расчёт. Повторите попытку.', 409);
                }
                throw $e;
            }
            $this->audit->log((int) $actor['id'], 'payroll_run.create', 'payroll_run', $runId, null, [
                'user_id' => $d['user_id'], 'period_from' => $d['period_from'], 'period_to' => $d['period_to'],
                'total_amount' => $total, 'items' => count($records), 'already_in_other_runs' => $other,
            ], null, $ip);
            return $this->present($this->payroll->find($runId)) + ['already_in_other_runs' => $other];
        });
    }

    public function confirm(array $actor, int $id, string $ip): array
    {
        return Tx::run(function () use ($actor, $id, $ip) {
            $run = $id > 0 ? $this->payroll->lock($id) : null;
            if ($run === null) {
                throw new ApiException('NOT_FOUND', 'Расчёт не найден', 404);
            }
            if ($run['status'] !== 'draft') {
                throw new ApiException('RUN_NOT_DRAFT', 'Подтвердить можно только черновик расчёта', 409);
            }
            $items = $this->payroll->items($id);
            $sum = '0.00';
            foreach ($items as $it) {
                if ($it['voided_at'] !== null) {
                    throw new ApiException('RUN_HAS_VOIDED_WORKS', 'В расчёте есть аннулированные работы. Отмените черновик и сформируйте заново.', 409);
                }
                $sum = Money::add($sum, (string) $it['total_amount']);
            }
            if (!$items || Money::cmp($sum, (string) $run['total_amount']) !== 0) {
                throw new ApiException('RUN_INCONSISTENT', 'Сумма позиций не совпадает с суммой расчёта. Сформируйте расчёт заново.', 409);
            }
            $this->payroll->setConfirmed($id, (int) $actor['id'], Dates::nowUtc());
            $this->audit->log((int) $actor['id'], 'payroll_run.confirm', 'payroll_run', $id,
                ['status' => 'draft'], ['status' => 'confirmed', 'total_amount' => $run['total_amount'], 'items' => count($items)], null, $ip);
            return $this->present($this->payroll->find($id));
        });
    }

    public function cancel(array $actor, int $id, array $input, string $ip): array
    {
        $reason = WorkRecordValidator::reason($input);
        return Tx::run(function () use ($actor, $id, $reason, $ip) {
            $run = $id > 0 ? $this->payroll->lock($id) : null;      // порядок блокировок: расчёт → выплаты
            if ($run === null) {
                throw new ApiException('NOT_FOUND', 'Расчёт не найден', 404);
            }
            if ($run['status'] === 'cancelled') {
                throw new ApiException('RUN_ALREADY_CANCELLED', 'Расчёт уже отменён', 409);
            }
            $live = $this->payroll->liveIssuesPayments($id);
            if ($live) {
                throw new ApiException('RUN_HAS_PAYMENTS',
                    'По расчёту есть выплаты. Сначала отмените их (отмена подтверждённой выплаты оформляется корректировкой), затем отмените расчёт.', 409,
                    ['payments' => array_map(static fn(array $p): array => ['id' => (int) $p['id'], 'status' => $p['status']], $live)]);
            }
            $this->payroll->setCancelled($id);
            $this->audit->log((int) $actor['id'], 'payroll_run.cancel', 'payroll_run', $id,
                ['status' => $run['status']], ['status' => 'cancelled'], $reason, $ip);
            return $this->present($this->payroll->find($id));
        });
    }
}
