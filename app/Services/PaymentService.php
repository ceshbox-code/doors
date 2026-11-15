<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Permissions;
use App\Core\Tx;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Money;
use App\Models\Payment;
use App\Repositories\AdjustmentRepository;
use App\Repositories\FinanceRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\PayrollRepository;
use App\Repositories\UserRepository;
use App\Validators\FinanceValidator;
use App\Validators\WorkRecordValidator;

/**
 * Выплаты (ТЗ 6.3, 6.4). Жизненный цикл: draft → posted → cancelled.
 * Черновик не влияет на остаток. Подтверждение — под блокировками: строки работ блокируются, уже распределённое
 * пересчитывается блокирующим чтением, поэтому одну сумму нельзя зачесть дважды и нельзя превысить остаток начисления.
 * Порядок блокировок везде: расчёт → выплата → работы.
 */
final class PaymentService
{
    private PaymentRepository $payments;
    private PayrollRepository $payroll;
    private FinanceRepository $finance;
    private AdjustmentRepository $adjustments;
    private UserRepository $users;
    private IdempotencyService $idem;
    private AuditService $audit;

    public function __construct()
    {
        $this->payments = new PaymentRepository();
        $this->payroll = new PayrollRepository();
        $this->finance = new FinanceRepository();
        $this->adjustments = new AdjustmentRepository();
        $this->users = new UserRepository();
        $this->idem = new IdempotencyService();
        $this->audit = new AuditService();
    }

    // ------------------------------------------------------------------ чтение

    private function canSee(array $viewer, array $row): bool
    {
        if (Permissions::can($viewer['role'], 'finance.view_all')) {
            return true;
        }
        return Permissions::can($viewer['role'], 'finance.view_own')
            && (int) $row['user_id'] === (int) $viewer['id'] && $row['status'] === 'posted';
    }

    private function presentFull(int $id): array
    {
        $row = $this->payments->find($id);
        $allocs = $this->payments->allocations($id);
        $total = '0.00';
        $items = [];
        foreach ($allocs as $a) {
            $total = Money::add($total, (string) $a['amount']);
            $items[] = $this->presentAllocation($a);
        }
        return Payment::present($row) + ['allocated_total' => $total, 'allocations' => $items];
    }

    private function presentAllocation(array $a): array
    {
        return [
            'id'             => (int) $a['id'],
            'work_record_id' => (int) $a['work_record_id'],
            'amount'         => $a['amount'],
            'work_type_name' => $a['work_type_name'],
            'door_number'    => $a['door_number'],
            'completed_at'   => Dates::toIso($a['completed_at']),
            'work_total'     => $a['total_amount'],
            'work_voided'    => $a['voided_at'] !== null,
        ];
    }

    public function list(array $viewer, array $query): array
    {
        $f = [];
        if (!Permissions::can($viewer['role'], 'finance.view_all')) {
            $f['user_id'] = (int) $viewer['id'];
            $f['only_posted'] = true;                    // исполнитель видит только свои подтверждённые выплаты
        } else {
            if (isset($query['user_id']) && ($u = Input::id($query['user_id'])) !== null) {
                $f['user_id'] = $u;
            }
            if (isset($query['status']) && in_array($query['status'], ['draft', 'posted', 'cancelled'], true)) {
                $f['status'] = $query['status'];
            }
            if (isset($query['payroll_run_id']) && ($r = Input::id($query['payroll_run_id'])) !== null) {
                $f['payroll_run_id'] = $r;
            }
        }
        $period = FinanceValidator::period($query);
        $tz = (string) \App\Core\Config::get('app.timezone', 'UTC');
        $f['from_utc'] = $period['from'] === null ? null : Dates::localDayToUtc($period['from'], $tz);
        $f['to_utc'] = $period['to'] === null ? null : Dates::localDayToUtc($period['to'], $tz, true);
        [$page, $per] = Input::paging($query, 50);
        [$rows, $total] = $this->payments->search($f, $per, ($page - 1) * $per);
        return ['items' => array_map([Payment::class, 'present'], $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    public function get(array $viewer, int $id): array
    {
        $row = $id > 0 ? $this->payments->find($id) : null;
        if ($row === null || !$this->canSee($viewer, $row)) {
            throw new ApiException('NOT_FOUND', 'Выплата не найдена', 404);
        }
        return $this->presentFull($id);
    }

    public function allocations(array $viewer, int $id): array
    {
        $row = $id > 0 ? $this->payments->find($id) : null;
        if ($row === null || !$this->canSee($viewer, $row)) {
            throw new ApiException('NOT_FOUND', 'Выплата не найдена', 404);
        }
        return array_map([$this, 'presentAllocation'], $this->payments->allocations($id));
    }

    // ------------------------------------------------------------------ создание

    /** @return array{status:int,data:array,replayed:bool} */
    public function create(array $actor, array $input, string $idemKey, string $ip): array
    {
        if (!IdempotencyService::validKey($idemKey)) {
            throw new ApiException('IDEMPOTENCY_KEY_INVALID', 'Заголовок Idempotency-Key обязателен: 8–100 символов (буквы, цифры, . _ : -)', 400);
        }
        $d = FinanceValidator::payment($input);
        $hash = IdempotencyService::hash($d);
        $actorId = (int) $actor['id'];

        return Tx::run(function () use ($actorId, $d, $idemKey, $hash, $ip, $actor) {
            $cached = $this->idem->begin($actorId, $idemKey, 'payment.create', $hash);
            if ($cached !== null) {
                return ['status' => (int) $cached['status'], 'data' => $cached['data'], 'replayed' => true];
            }

            // Получатель: выплачивать можно и деактивированному исполнителю (расчёт при увольнении)
            $user = $this->users->find($d['user_id']);
            if ($user === null || !in_array($user['role'], ['master', 'owner'], true)) {
                throw new ApiException('USER_NOT_FOUND', 'Получатель не найден или не является исполнителем', 404);
            }
            if ($d['payroll_run_id'] !== null) {
                $run = $this->payroll->lock($d['payroll_run_id']);       // не даём отменить расчёт, пока создаётся выплата
                if ($run === null || (int) $run['user_id'] !== $d['user_id']) {
                    throw new ApiException('NOT_FOUND', 'Расчёт не найден у этого получателя', 404);
                }
                if ($run['status'] !== 'confirmed') {
                    throw new ApiException('RUN_NOT_CONFIRMED', 'Выплату по расчёту можно создать только после его подтверждения', 409);
                }
            }

            $outstanding = $this->finance->outstandingForUser($d['user_id'], $d['payroll_run_id']);
            $allocations = $d['allocations'];
            if ($allocations === null) {
                // По умолчанию — на самые ранние неоплаченные начисления
                $plan = AllocationPlanner::auto($d['amount'], array_map(
                    static fn(array $o): array => ['id' => (int) $o['id'], 'outstanding' => (string) $o['outstanding']], $outstanding
                ));
                if (Money::cmp($plan['remainder'], '0') > 0) {
                    $avail = '0.00';
                    foreach ($outstanding as $o) {
                        $avail = Money::add($avail, (string) $o['outstanding']);
                    }
                    throw new ApiException('AMOUNT_EXCEEDS_OUTSTANDING',
                        'Сумма выплаты больше неоплаченных начислений получателя. Доступно к распределению: ' . $avail, 422,
                        ['available' => $avail, 'amount' => $d['amount']]);
                }
                $allocations = $plan['plan'];
            } else {
                $byId = [];
                foreach ($outstanding as $o) {
                    $byId[(int) $o['id']] = (string) $o['outstanding'];
                }
                AllocationPlanner::validate($d['amount'], $allocations, $byId);
            }

            $d['payment_date'] = $d['payment_date'] ?? Dates::nowUtc();      // по умолчанию — момент создания (после проверки ключа)
            $id = $this->payments->insert($d + ['created_by' => $actorId]);
            foreach ($allocations as $a) {
                $this->payments->insertAllocation($id, $a['work_record_id'], $a['amount']);
            }
            $this->audit->log($actorId, 'payment.create', 'payment', $id, null, [
                'user_id' => $d['user_id'], 'amount' => $d['amount'], 'payment_method' => $d['payment_method'],
                'payroll_run_id' => $d['payroll_run_id'], 'allocations' => count($allocations),
            ], null, $ip);

            if ($d['post']) {
                $this->postCore($actor, $id, $ip);
            }
            $presented = $this->presentFull($id);
            $this->idem->complete($actorId, $idemKey, $id, 201, $presented);
            return ['status' => 201, 'data' => $presented, 'replayed' => false];
        });
    }

    /** Замена распределения черновика (ТЗ 6.4: «администратор может изменить распределение до подтверждения»). */
    public function replaceAllocations(array $actor, int $id, array $input, string $ip): array
    {
        $allocations = FinanceValidator::allocations($input['allocations'] ?? null);
        return Tx::run(function () use ($actor, $id, $allocations, $ip) {
            $pay = $this->lockRunThenPayment($id);
            if ($pay['status'] !== 'draft') {
                throw new ApiException('PAYMENT_NOT_DRAFT', 'Распределение можно менять только у черновика выплаты', 409);
            }
            $outstanding = $this->finance->outstandingForUser((int) $pay['user_id'], $pay['payroll_run_id'] === null ? null : (int) $pay['payroll_run_id']);
            $byId = [];
            foreach ($outstanding as $o) {
                $byId[(int) $o['id']] = (string) $o['outstanding'];
            }
            AllocationPlanner::validate((string) $pay['amount'], $allocations, $byId);

            $old = array_map(static fn(array $a): array => ['work_record_id' => (int) $a['work_record_id'], 'amount' => $a['amount']], $this->payments->allocations($id));
            $this->payments->deleteAllocations($id);           // у черновика распределение ещё не является финансовым фактом
            foreach ($allocations as $a) {
                $this->payments->insertAllocation($id, $a['work_record_id'], $a['amount']);
            }
            $this->audit->log((int) $actor['id'], 'payment.allocations_update', 'payment', $id, ['allocations' => $old], ['allocations' => $allocations], null, $ip);
            return $this->presentFull($id);
        });
    }

    // ------------------------------------------------------------------ подтверждение и отмена

    /** Расчёт блокируется раньше выплаты — тот же порядок, что при отмене расчёта (нет взаимных блокировок). */
    private function lockRunThenPayment(int $id): array
    {
        $peek = $id > 0 ? $this->payments->peek($id) : null;
        if ($peek === null) {
            throw new ApiException('NOT_FOUND', 'Выплата не найдена', 404);
        }
        if ($peek['payroll_run_id'] !== null) {
            $this->payroll->lock((int) $peek['payroll_run_id']);
        }
        $pay = $this->payments->lock($id);
        if ($pay === null) {
            throw new ApiException('NOT_FOUND', 'Выплата не найдена', 404);
        }
        return $pay;
    }

    public function post(array $actor, int $id, string $ip): array
    {
        return Tx::run(function () use ($actor, $id, $ip) {
            $this->lockRunThenPayment($id);
            $this->postCore($actor, $id, $ip);
            return $this->presentFull($id);
        });
    }

    /** Вызывается внутри транзакции; строка выплаты (и расчёта) уже заблокирована вызывающим. */
    private function postCore(array $actor, int $id, string $ip): void
    {
        $pay = $this->payments->lock($id);
        if ($pay === null) {
            throw new ApiException('NOT_FOUND', 'Выплата не найдена', 404);
        }
        if ($pay['status'] !== 'draft') {
            throw new ApiException('PAYMENT_NOT_DRAFT', 'Подтвердить можно только черновик выплаты', 409);
        }
        $runId = $pay['payroll_run_id'] === null ? null : (int) $pay['payroll_run_id'];
        if ($runId !== null) {
            $run = $this->payroll->lock($runId);
            if ($run === null || $run['status'] !== 'confirmed') {
                throw new ApiException('RUN_NOT_CONFIRMED', 'Расчёт по этой выплате не подтверждён или отменён', 409);
            }
        }

        $rows = $this->payments->allocations($id);
        if (!$rows) {
            throw new ApiException('NO_ALLOCATIONS', 'У выплаты нет распределения', 422);
        }
        $ids = array_map(static fn(array $r): int => (int) $r['work_record_id'], $rows);

        // Блокируем начисления и перечитываем уже распределённое блокирующим чтением
        $locked = $this->payments->lockRecords($ids);
        $sum = '0.00';
        foreach ($rows as $r) {
            $rid = (int) $r['work_record_id'];
            $rec = $locked[$rid] ?? null;
            if ($rec === null || (int) $rec['user_id'] !== (int) $pay['user_id']) {
                throw new ApiException('ALLOCATION_INVALID', "Начисление №{$rid} не найдено или принадлежит другому получателю", 422, ['work_record_id' => $rid]);
            }
            if ($rec['voided_at'] !== null) {
                throw new ApiException('ALLOCATION_VOIDED', "Работа №{$rid} аннулирована, выплачивать за неё нельзя. Измените распределение.", 409, ['work_record_id' => $rid]);
            }
            if ($runId !== null && !$this->payroll->isActiveItem($runId, $rid)) {
                throw new ApiException('ALLOCATION_NOT_IN_RUN', "Работа №{$rid} не входит в расчёт этой выплаты", 422, ['work_record_id' => $rid]);
            }
            $sum = Money::add($sum, (string) $r['amount']);
        }
        if (Money::cmp($sum, (string) $pay['amount']) !== 0) {
            throw new ApiException('ALLOCATION_SUM_MISMATCH', 'Сумма распределения должна равняться сумме выплаты', 422, ['allocated' => $sum, 'amount' => $pay['amount']]);
        }
        $already = $this->payments->postedSumsLocked($ids);
        foreach ($rows as $r) {
            $rid = (int) $r['work_record_id'];
            $left = Money::sub((string) $locked[$rid]['total_amount'], $already[$rid] ?? '0.00');
            if (Money::cmp((string) $r['amount'], $left) > 0) {
                throw new ApiException('ALLOCATION_EXCEEDS_OUTSTANDING',
                    "Сумма по начислению №{$rid} превышает его неоплаченный остаток {$left}. Измените распределение.", 409,
                    ['work_record_id' => $rid, 'outstanding' => $left, 'requested' => $r['amount']]);
            }
        }

        $this->payments->markPosted($id, Dates::nowUtc());
        $this->audit->log((int) $actor['id'], 'payment.post', 'payment', $id,
            ['status' => 'draft'], ['status' => 'posted', 'amount' => $pay['amount'], 'user_id' => (int) $pay['user_id']], null, $ip);
    }

    public function cancel(array $actor, int $id, array $input, string $ip): array
    {
        $reason = WorkRecordValidator::reason($input);
        return Tx::run(function () use ($actor, $id, $reason, $ip) {
            $pay = $this->lockRunThenPayment($id);
            if ($pay['status'] === 'cancelled') {
                throw new ApiException('PAYMENT_ALREADY_CANCELLED', 'Выплата уже отменена', 409);
            }
            $this->payments->markCancelled($id);
            $adjId = null;
            if ($pay['status'] === 'posted') {
                // История выплаты не переписывается: отмена фиксируется корректировкой, а остаток восстанавливается тем,
                // что отменённая выплата перестаёт учитываться в «выплачено»
                $adjId = $this->adjustments->insert([
                    'user_id' => (int) $pay['user_id'], 'type' => 'cancel_payment', 'amount' => Money::add((string) $pay['amount'], '0'),
                    'affects_balance' => 0, 'payment_id' => $id, 'payroll_run_id' => $pay['payroll_run_id'] === null ? null : (int) $pay['payroll_run_id'],
                    'work_record_id' => null, 'reason' => $reason, 'created_by' => (int) $actor['id'],
                ]);
            }
            $this->audit->log((int) $actor['id'], 'payment.cancel', 'payment', $id,
                ['status' => $pay['status']], ['status' => 'cancelled', 'adjustment_id' => $adjId], $reason, $ip);
            return $this->presentFull($id);
        });
    }
}
