<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Permissions;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Money;
use App\Models\Adjustment;
use App\Models\User;
use App\Repositories\AdjustmentRepository;
use App\Repositories\FinanceRepository;
use App\Repositories\UserRepository;
use App\Validators\FinanceValidator;

/**
 * Финансовые показатели (ТЗ 6.2). Остаток = начислено − выплачено + ручные корректировки.
 * «Выплачено» — только распределения подтверждённых выплат; отменённые выплаты не учитываются.
 * Отрицательный остаток не скрывается: он выводится отдельно как переплата.
 */
final class FinanceService
{
    private FinanceRepository $finance;
    private UserRepository $users;
    private AdjustmentRepository $adjustments;

    public function __construct()
    {
        $this->finance = new FinanceRepository();
        $this->users = new UserRepository();
        $this->adjustments = new AdjustmentRepository();
    }

    private static function tz(): string
    {
        return (string) Config::get('app.timezone', 'UTC');
    }

    /** Границы периода в UTC: [от, до) по локальным датам предприятия. @return array{0:?string,1:?string} */
    private static function bounds(?string $from, ?string $to): array
    {
        return [
            $from === null ? null : Dates::localDayToUtc($from, self::tz()),
            $to === null ? null : Dates::localDayToUtc($to, self::tz(), true),
        ];
    }

    /** @param array<string,mixed> $t строка из FinanceRepository::totals */
    public static function figures(array $t): array
    {
        $accrued = bcadd((string) $t['accrued'], '0', 2);
        $paid = bcadd((string) $t['paid'], '0', 2);
        $adj = bcadd((string) $t['adjustments'], '0', 2);
        $balance = Money::add(Money::sub($accrued, $paid), $adj);
        return [
            'accrued'      => $accrued,
            'paid'         => $paid,
            'adjustments'  => $adj,
            'balance'      => $balance,
            'debt'         => Money::positive($balance),
            'overpayment'  => Money::negativePart($balance),
            'works_count'  => (int) ($t['works_count'] ?? 0),
        ];
    }

    private static function zero(): array
    {
        return ['accrued' => '0', 'paid' => '0', 'adjustments' => '0', 'works_count' => 0];
    }

    public function userFinance(array $actor, int $userId, array $query): array
    {
        $canAll = Permissions::can($actor['role'], 'finance.view_all');
        $user = $userId > 0 ? $this->users->find($userId) : null;
        if ($user === null || (!$canAll && $userId !== (int) $actor['id'])) {
            throw new ApiException('NOT_FOUND', 'Пользователь не найден', 404);
        }
        $period = FinanceValidator::period($query);
        $all = $this->finance->totals([$userId], null, null, false)[$userId] ?? self::zero();
        $allocatable = '0.00';
        foreach ($this->finance->outstandingForUser($userId) as $o) {
            $allocatable = Money::add($allocatable, (string) $o['outstanding']);
        }
        $out = [
            'user'   => ['id' => (int) $user['id'], 'full_name' => $user['full_name'], 'role' => $user['role'], 'is_active' => (bool) (int) $user['is_active']],
            'totals' => self::figures($all) + ['unpaid_accruals_total' => $allocatable],
        ];
        if ($period['from'] !== null || $period['to'] !== null) {
            [$fromUtc, $toUtc] = self::bounds($period['from'], $period['to']);
            $in = $this->finance->totals([$userId], $fromUtc, $toUtc, false)[$userId] ?? self::zero();
            $end = $this->finance->totals([$userId], null, $toUtc, false)[$userId] ?? self::zero();
            $f = self::figures($in);
            $endFigures = self::figures($end);
            $out['period'] = [
                'from' => $period['from'], 'to' => $period['to'],
                'accrued' => $f['accrued'], 'paid' => $f['paid'], 'adjustments' => $f['adjustments'], 'works_count' => $f['works_count'],
                'balance_at_end' => $endFigures['balance'], 'debt_at_end' => $endFigures['debt'], 'overpayment_at_end' => $endFigures['overpayment'],
            ];
        }
        return $out;
    }

    public function balances(array $query): array
    {
        $period = FinanceValidator::period($query);
        [$fromUtc, $toUtc] = self::bounds($period['from'], $period['to']);
        $all = $this->finance->totals(null, null, null);
        $inPeriod = ($fromUtc !== null || $toUtc !== null) ? $this->finance->totals(null, $fromUtc, $toUtc) : [];
        $items = [];
        foreach ($all as $uid => $t) {
            $row = ['user' => ['id' => $uid, 'full_name' => $t['full_name'], 'role' => $t['role'], 'is_active' => (bool) (int) $t['is_active']]] + self::figures($t);
            if (isset($query['only_debt']) && (string) $query['only_debt'] === '1' && Money::cmp($row['balance'], '0') <= 0) {
                continue;
            }
            if ($inPeriod) {
                $p = self::figures($inPeriod[$uid] ?? self::zero());
                $row['period'] = ['accrued' => $p['accrued'], 'paid' => $p['paid'], 'adjustments' => $p['adjustments']];
            }
            $items[] = $row;
        }
        return ['items' => $items, 'period' => $inPeriod ? $period : null];
    }

    public function summary(array $query): array
    {
        $period = FinanceValidator::period($query);
        [$fromUtc, $toUtc] = self::bounds($period['from'], $period['to']);
        $sum = static function (array $rows): array {
            $acc = $paid = $adj = '0.00';
            $debt = $over = '0.00';
            $debtors = 0;
            foreach ($rows as $t) {
                $f = FinanceService::figures($t);
                $acc = Money::add($acc, $f['accrued']);
                $paid = Money::add($paid, $f['paid']);
                $adj = Money::add($adj, $f['adjustments']);
                $debt = Money::add($debt, $f['debt']);
                $over = Money::add($over, $f['overpayment']);
                $debtors += Money::cmp($f['debt'], '0') > 0 ? 1 : 0;
            }
            return ['accrued' => $acc, 'paid' => $paid, 'adjustments' => $adj, 'debt_total' => $debt, 'overpayment_total' => $over, 'executors_with_debt' => $debtors];
        };
        $out = ['totals' => $sum($this->finance->totals(null, null, null))];
        if ($fromUtc !== null || $toUtc !== null) {
            $r = $sum($this->finance->totals(null, $fromUtc, $toUtc));
            $out['period'] = ['from' => $period['from'], 'to' => $period['to'], 'accrued' => $r['accrued'], 'paid' => $r['paid'], 'adjustments' => $r['adjustments']];
        }
        return $out;
    }

    public function accruals(array $actor, array $query): array
    {
        $f = [];
        if (!Permissions::can($actor['role'], 'finance.view_all')) {
            $f['user_id'] = (int) $actor['id'];
        } elseif (isset($query['user_id']) && ($u = Input::id($query['user_id'])) !== null) {
            $f['user_id'] = $u;
        }
        $period = FinanceValidator::period($query);
        [$f['from_utc'], $f['to_utc']] = self::bounds($period['from'], $period['to']);
        $status = $query['status'] ?? 'active';
        $state = $query['payment_state'] ?? 'all';
        if (!in_array($status, ['active', 'voided', 'all'], true) || !in_array($state, ['all', 'unpaid', 'partial', 'paid', 'open'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422,
                ['status' => 'active, voided или all', 'payment_state' => 'all, unpaid, partial, paid или open']);
        }
        $f['status'] = $status;
        $f['payment_state'] = $state;
        [$page, $per] = Input::paging($query, 50);
        [$rows, $total] = $this->finance->accruals($f, $per, ($page - 1) * $per);
        $items = array_map(static function (array $r): array {
            $voided = $r['voided_at'] !== null;
            $outstanding = $voided ? '0.00' : Money::positive(Money::sub((string) $r['total_amount'], (string) $r['paid']));
            $state = $voided ? 'voided' : (Money::cmp((string) $r['paid'], (string) $r['total_amount']) >= 0 ? 'paid' : (Money::cmp((string) $r['paid'], '0') > 0 ? 'partial' : 'unpaid'));
            return [
                'work_record_id' => (int) $r['id'],
                'user'           => ['id' => (int) $r['user_id'], 'full_name' => $r['user_name']],
                'work_type'      => ['id' => (int) $r['work_type_id'], 'name' => $r['work_type_name']],
                'door_number'    => $r['door_number'],
                'quantity'       => $r['quantity'],
                'unit'           => $r['unit'],
                'unit_price'     => $r['unit_price'],
                'total_amount'   => $r['total_amount'],
                'paid'           => bcadd((string) $r['paid'], '0', 2),
                'outstanding'    => $outstanding,
                'payment_state'  => $state,
                'payroll_run_id' => $r['payroll_run_id'] === null ? null : (int) $r['payroll_run_id'],
                'completed_at'   => Dates::toIso($r['completed_at']),
            ];
        }, $rows);
        return ['items' => $items, 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    public function adjustmentList(array $actor, array $query): array
    {
        $f = [];
        if (!Permissions::can($actor['role'], 'finance.view_all')) {
            $f['user_id'] = (int) $actor['id'];
        } elseif (isset($query['user_id']) && ($u = Input::id($query['user_id'])) !== null) {
            $f['user_id'] = $u;
        }
        if (isset($query['type']) && is_string($query['type']) && $query['type'] !== '') {
            $f['type'] = $query['type'];
        }
        [$page, $per] = Input::paging($query, 50);
        [$rows, $total] = $this->adjustments->search($f, $per, ($page - 1) * $per);
        return ['items' => array_map([Adjustment::class, 'present'], $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
    }
}
