<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Money;
use App\Repositories\FinanceRepository;
use App\Repositories\StatsRepository;

/**
 * Статистика (ТЗ раздел 7, 12.9). Считает только действующие работы. Выплаты и остатки берутся из финансового слоя
 * (FinanceRepository::totals), поэтому совпадают с модулем финансов. Все методы принимают query-параметры запроса.
 */
final class StatsService
{
    private StatsRepository $stats;
    private FinanceRepository $finance;

    public function __construct()
    {
        $this->stats = new StatsRepository();
        $this->finance = new FinanceRepository();
    }

    // ---------------------------------------------------------------- сводка (7.1)

    public function summary(array $query): array
    {
        return $this->summaryFor(ReportFilters::fromQuery($query));
    }

    public function summaryFor(array $f): array
    {
        $cat = ['repair' => 0, 'packing' => 0, 'other' => 0];
        foreach ($this->stats->countsByCategory($f) as $r) {
            $cat[$r['category']] = ($cat[$r['category']] ?? 0) + (int) $r['works'];
        }
        $totals = $this->stats->totals($f);
        return [
            'filters' => ReportFilters::describe($f),
            'counts'  => [
                'doors'       => $this->stats->distinctDoors($f),
                'repairs'     => $cat['repair'],
                'packings'    => $cat['packing'],
                'other_works' => $cat['other'],
                'operations'  => (int) $totals['works'],
            ],
            'money'   => $this->money($f, bcadd((string) $totals['amount'], '0', 2)),
        ];
    }

    /**
     * Начислено / выплачено / задолженность. Выплаты не делятся по видам работ и дверям, поэтому при таких фильтрах
     * показывается только начисленная сумма (остальное — null).
     */
    private function money(array $f, string $accruedFromRecords): array
    {
        if ($f['work_type_id'] !== null || $f['door_id'] !== null || $f['door_missing']) {
            return ['accrued' => $accruedFromRecords, 'paid' => null, 'adjustments' => null, 'debt' => null, 'overpayment' => null,
                    'note' => 'Выплаты и остаток не делятся по видам работ и дверям'];
        }
        $ids = $f['user_id'] !== null ? [$f['user_id']] : null;
        $inPeriod = $this->finance->totals($ids, $f['from_utc'], $f['to_utc']);
        $atEnd = $this->finance->totals($ids, null, $f['to_utc']);
        $accrued = $paid = $adj = $debt = $over = '0.00';
        foreach ($inPeriod as $t) {
            $x = FinanceService::figures($t);
            $accrued = Money::add($accrued, $x['accrued']);
            $paid = Money::add($paid, $x['paid']);
            $adj = Money::add($adj, $x['adjustments']);
        }
        foreach ($atEnd as $t) {
            $x = FinanceService::figures($t);
            $debt = Money::add($debt, $x['debt']);
            $over = Money::add($over, $x['overpayment']);
        }
        return ['accrued' => $accrued, 'paid' => $paid, 'adjustments' => $adj, 'debt' => $debt, 'overpayment' => $over];
    }

    // ---------------------------------------------------------------- по дням

    public function daily(array $query): array
    {
        return $this->dailyFor(ReportFilters::fromQuery($query));
    }

    public function dailyFor(array $f): array
    {
        $tz = new \DateTimeZone($f['tz']);
        $utc = new \DateTimeZone('UTC');
        $days = [];
        foreach ($this->stats->dailyRows($f) as $r) {
            $day = (new \DateTimeImmutable($r['completed_at'], $utc))->setTimezone($tz)->format('Y-m-d');
            $d = $days[$day] ?? ['works' => 0, 'doors' => [], 'amount' => '0.00', 'cat' => ['repair' => 0, 'packing' => 0, 'other' => 0]];
            $d['works']++;
            if ($r['door_id'] !== null) {
                $d['doors'][$r['door_id']] = true;
            }
            $d['amount'] = Money::add($d['amount'], (string) $r['total_amount']);
            $d['cat'][$r['category']] = ($d['cat'][$r['category']] ?? 0) + 1;
            $days[$day] = $d;
        }
        // Непрерывный ряд для графиков, если период задан целиком и не слишком длинный
        $from = $f['period']['from'];
        $to = $f['period']['to'];
        if ($from !== null && $to !== null && Dates::daysBetween($from, $to) <= 400) {
            for ($c = new \DateTimeImmutable($from); $c->format('Y-m-d') <= $to; $c = $c->modify('+1 day')) {
                $k = $c->format('Y-m-d');
                $days[$k] = $days[$k] ?? ['works' => 0, 'doors' => [], 'amount' => '0.00', 'cat' => ['repair' => 0, 'packing' => 0, 'other' => 0]];
            }
        }
        ksort($days);
        $items = [];
        $works = 0;
        $amount = '0.00';
        foreach ($days as $date => $d) {
            $items[] = [
                'date' => $date, 'works_count' => $d['works'], 'doors_count' => count($d['doors']), 'amount' => $d['amount'],
                'repairs' => $d['cat']['repair'], 'packings' => $d['cat']['packing'], 'other_works' => $d['cat']['other'],
            ];
            $works += $d['works'];
            $amount = Money::add($amount, $d['amount']);
        }
        return ['filters' => ReportFilters::describe($f), 'items' => $items, 'totals' => ['works_count' => $works, 'amount' => $amount]];
    }

    // ---------------------------------------------------------------- по видам работ

    public function workTypes(array $query): array
    {
        return $this->workTypesFor(ReportFilters::fromQuery($query));
    }

    public function workTypesFor(array $f): array
    {
        $items = [];
        $works = 0;
        $amount = '0.00';
        foreach ($this->stats->byWorkType($f) as $r) {
            $items[] = [
                'work_type'   => ['id' => (int) $r['id'], 'name' => $r['name'], 'unit' => $r['unit'], 'category' => $r['category']],
                'works_count' => (int) $r['works'],
                'doors_count' => (int) $r['doors'],
                'quantity'    => $r['quantity'],
                'amount'      => bcadd((string) $r['amount'], '0', 2),
            ];
            $works += (int) $r['works'];
            $amount = Money::add($amount, (string) $r['amount']);
        }
        return ['filters' => ReportFilters::describe($f), 'items' => $items, 'totals' => ['works_count' => $works, 'amount' => $amount]];
    }

    // ---------------------------------------------------------------- по исполнителям (7.3)

    public function users(array $query): array
    {
        return $this->usersFor(ReportFilters::fromQuery($query));
    }

    public function usersFor(array $f): array
    {
        $users = [];
        foreach ($this->stats->byUserAndType($f) as $r) {
            $uid = (int) $r['user_id'];
            $users[$uid] = $users[$uid] ?? [
                'user' => ['id' => $uid, 'full_name' => $r['full_name'], 'role' => $r['role'], 'is_active' => (bool) (int) $r['is_active']],
                'works_count' => 0, 'accrued' => '0.00', 'by_work_type' => [],
            ];
            $users[$uid]['works_count'] += (int) $r['works'];
            $users[$uid]['accrued'] = Money::add($users[$uid]['accrued'], (string) $r['amount']);
            $users[$uid]['by_work_type'][] = [
                'work_type_id' => (int) $r['work_type_id'], 'name' => $r['work_type_name'], 'unit' => $r['unit'],
                'works_count' => (int) $r['works'], 'quantity' => $r['quantity'], 'amount' => bcadd((string) $r['amount'], '0', 2),
            ];
        }
        $doors = $this->stats->doorsByUser($f);
        $ids = array_keys($users);
        // Выплаты и остаток недоступны в разрезе вида работ/двери — тогда только начисления
        $withMoney = $f['work_type_id'] === null && $f['door_id'] === null && !$f['door_missing'];
        $paid = $withMoney && $ids ? $this->finance->totals($ids, $f['from_utc'], $f['to_utc'], false) : [];
        $atEnd = $withMoney && $ids ? $this->finance->totals($ids, null, $f['to_utc'], false) : [];

        $items = [];
        $sumWorks = 0;
        $sumAccrued = '0.00';
        $sumPaid = '0.00';
        foreach ($users as $uid => $u) {
            $u['doors_count'] = $doors[$uid] ?? 0;
            if ($withMoney) {
                $p = FinanceService::figures($paid[$uid] ?? ['accrued' => '0', 'paid' => '0', 'adjustments' => '0', 'works_count' => 0]);
                $e = FinanceService::figures($atEnd[$uid] ?? ['accrued' => '0', 'paid' => '0', 'adjustments' => '0', 'works_count' => 0]);
                $u['paid'] = $p['paid'];
                $u['balance_at_end'] = $e['balance'];
                $u['debt'] = $e['debt'];
                $u['overpayment'] = $e['overpayment'];
                $sumPaid = Money::add($sumPaid, $p['paid']);
            } else {
                $u['paid'] = $u['balance_at_end'] = $u['debt'] = $u['overpayment'] = null;
            }
            $sumWorks += $u['works_count'];
            $sumAccrued = Money::add($sumAccrued, $u['accrued']);
            $items[] = $u;
        }
        return [
            'filters' => ReportFilters::describe($f),
            'items'   => $items,
            'totals'  => ['works_count' => $sumWorks, 'accrued' => $sumAccrued, 'paid' => $withMoney ? $sumPaid : null],
        ];
    }

    // ---------------------------------------------------------------- по дверям

    public function doors(array $query): array
    {
        $f = ReportFilters::fromQuery($query);
        [$page, $per] = Input::paging($query, 50);
        [$rows, $total] = $this->stats->doors($f, $per, ($page - 1) * $per);
        return [
            'filters'  => ReportFilters::describe($f),
            'items'    => array_map([self::class, 'presentDoor'], $rows),
            'page'     => $page, 'per_page' => $per, 'total' => $total,
        ];
    }

    /** @return array<int,array> все двери выборки (для экспорта), не более $limit */
    public function doorsAll(array $f, int $limit = 5000): array
    {
        [$rows] = $this->stats->doors($f, $limit, 0);
        return array_map([self::class, 'presentDoor'], $rows);
    }

    public static function presentDoor(array $r): array
    {
        return [
            'door'         => ['id' => (int) $r['id'], 'number' => $r['number'], 'status' => $r['status']],
            'works_count'  => (int) $r['works'],
            'amount'       => bcadd((string) $r['amount'], '0', 2),
            'work_types'   => $r['work_types'],
            'photos_count' => (int) $r['photos_count'],
            'first_at'     => \App\Helpers\Dates::toIso($r['first_at']),
            'last_at'      => \App\Helpers\Dates::toIso($r['last_at']),
        ];
    }
}
