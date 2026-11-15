<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Permissions;
use App\Helpers\Dates;
use App\Helpers\Format;
use App\Helpers\Money;
use App\Helpers\Periods;
use App\Models\Adjustment;
use App\Repositories\AdjustmentRepository;
use App\Repositories\FinanceRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\PhotoRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkRecordRepository;
use App\Repositories\WorkTypeRepository;

/**
 * Данные для PDF-отчётов (ТЗ раздел 8). Все цифры берутся из тех же слоёв, что JSON-статистика и финансы,
 * поэтому суммы в отчётах совпадают с данными системы.
 */
final class ReportService
{
    public const PHOTO_REPORT_MAX_RECORDS = 100;
    public const PHOTOS_PER_RECORD = 3;

    private StatsService $stats;
    private FinanceRepository $finance;
    private PaymentRepository $payments;
    private PayrollService $payroll;
    private WorkRecordRepository $records;
    private PhotoRepository $photos;
    private UserRepository $users;
    private WorkTypeRepository $types;
    private AdjustmentRepository $adjustments;
    private PhotoStorage $storage;

    public function __construct()
    {
        $this->stats = new StatsService();
        $this->finance = new FinanceRepository();
        $this->payments = new PaymentRepository();
        $this->payroll = new PayrollService();
        $this->records = new WorkRecordRepository();
        $this->photos = new PhotoRepository();
        $this->users = new UserRepository();
        $this->types = new WorkTypeRepository();
        $this->adjustments = new AdjustmentRepository();
        $this->storage = new PhotoStorage();
    }

    /** Общая шапка отчёта: организация, название, период, условия выборки, дата формирования. */
    public function base(string $title, array $f): array
    {
        $tz = $f['tz'] ?? (string) Config::get('app.timezone', 'UTC');
        $lines = [];
        if (($f['user_id'] ?? null) !== null) {
            $u = $this->users->find((int) $f['user_id']);
            $lines[] = 'Исполнитель: ' . ($u['full_name'] ?? ('№' . $f['user_id']));
        }
        if (($f['work_type_id'] ?? null) !== null) {
            $t = $this->types->find((int) $f['work_type_id']);
            $lines[] = 'Вид работы: ' . ($t['name'] ?? ('№' . $f['work_type_id']));
        }
        if (($f['door_number'] ?? null) !== null) {
            $lines[] = 'Дверь: ' . $f['door_number'];
        }
        return [
            'org'       => (string) Config::get('app.org_name', 'Организация'),
            'title'     => $title,
            'period'    => $f['period']['label'] ?? '',
            'filters'   => $lines,
            'generated' => Format::dateTime(Dates::nowUtc(), $tz),
            'tz'        => $tz,
            'currency'  => Format::currency(),
        ];
    }

    // ------------------------------------------------------------ 8.1 Производственный отчёт

    public function production(array $f): array
    {
        $summary = $this->stats->summaryFor($f);
        $types = $this->stats->workTypesFor($f);
        $users = $this->stats->usersFor($f);
        return $this->base('Производственный отчёт', $f) + [
            'counts'       => $summary['counts'],
            'money'        => $summary['money'],
            'by_work_type' => $types['items'],
            'by_executor'  => $users['items'],
            'totals'       => $types['totals'],
        ];
    }

    // ------------------------------------------------------------ 8.2 Расчётный лист

    public function payslip(array $viewer, int $runId): array
    {
        $run = $this->payroll->detail($runId, $viewer);       // права и принадлежность проверяются здесь (404)
        // Черновик видит только администратор (с пометкой «ЧЕРНОВИК»); отменённый расчёт не выдаётся никому
        if ($run['status'] === 'cancelled' || ($run['status'] !== 'confirmed' && !Permissions::can($viewer['role'], 'finance.manage'))) {
            throw new ApiException('NOT_FOUND', 'Расчёт не найден', 404);
        }
        $tz = (string) Config::get('app.timezone', 'UTC');
        $userId = (int) $run['user']['id'];
        $fromUtc = Dates::localDayToUtc($run['period_from'], $tz);
        $toUtc = Dates::localDayToUtc($run['period_to'], $tz, true);

        $adjRows = $this->adjustments->forPayslip($userId, (int) $run['id'], (string) $fromUtc, (string) $toUtc);
        $adjSum = '0.00';
        foreach ($adjRows as $a) {
            if ((int) $a['affects_balance'] === 1) {
                $adjSum = Money::add($adjSum, (string) $a['amount']);
            }
        }
        $current = $run['totals']['current_total'];
        $total = Money::add($current, $adjSum);
        $paid = $run['totals']['paid'];
        $rest = Money::sub($total, $paid);
        $overall = FinanceService::figures($this->finance->totals([$userId], null, null, false)[$userId] ?? ['accrued' => '0', 'paid' => '0', 'adjustments' => '0', 'works_count' => 0]);

        $base = $this->base('Расчётный лист', ['tz' => $tz, 'period' => ['label' => Periods::rangeLabel($run['period_from'], $run['period_to'])]]);
        return $base + [
            'run'         => $run,
            'executor'    => $run['user']['full_name'],
            'adjustments' => array_map([Adjustment::class, 'present'], $adjRows),
            'summary'     => [
                'accrued'        => $current,
                'voided'         => $run['totals']['voided_total'],
                'adjustments'    => $adjSum,
                'total_accrued'  => $total,
                'paid'           => $paid,
                'remaining'      => Money::positive($rest),
                'overpayment'    => Money::negativePart($rest),
                'overall_balance' => $overall['balance'],
            ],
        ];
    }

    // ------------------------------------------------------------ 8.3 Отчёт по выплатам

    public function payments(array $f, string $status = 'posted'): array
    {
        $filters = ['from_utc' => $f['from_utc'], 'to_utc' => $f['to_utc']];
        if ($f['user_id'] !== null) {
            $filters['user_id'] = $f['user_id'];
        }
        if ($status !== 'all') {
            $filters['status'] = $status;
        }
        [$rows] = $this->payments->search($filters, 5000, 0);
        $rows = array_reverse($rows);                      // хронологически
        $items = [];
        $total = '0.00';
        $byMethod = [];
        foreach ($rows as $r) {
            $allocs = [];
            foreach ($this->payments->allocations((int) $r['id']) as $a) {
                $allocs[] = [
                    'work_record_id' => (int) $a['work_record_id'], 'door_number' => $a['door_number'],
                    'work_type_name' => $a['work_type_name'], 'amount' => $a['amount'],
                ];
            }
            $items[] = [
                'id' => (int) $r['id'], 'payment_date' => $r['payment_date'], 'executor' => $r['user_name'], 'amount' => $r['amount'],
                'method' => $r['payment_method'], 'reference' => $r['reference'], 'status' => $r['status'],
                'comment' => $r['comment'], 'allocations' => $allocs,
            ];
            if ($r['status'] === 'posted') {
                $total = Money::add($total, (string) $r['amount']);
                $byMethod[$r['payment_method']] = Money::add($byMethod[$r['payment_method']] ?? '0.00', (string) $r['amount']);
            }
        }
        ksort($byMethod);
        $base = $this->base('Отчёт по выплатам', $f);
        if ($f['work_type_id'] !== null || $f['door_id'] !== null || $f['door_missing']) {
            $base['filters'][] = 'Фильтры по виду работ и двери к выплатам не применяются';
        }
        return $base + ['items' => $items, 'total_posted' => $total, 'by_method' => $byMethod, 'status' => $status];
    }

    // ------------------------------------------------------------ 8.4 Общий финансовый отчёт

    public function finance(array $f): array
    {
        $ids = $f['user_id'] !== null ? [$f['user_id']] : null;
        $period = $this->finance->totals($ids, $f['from_utc'], $f['to_utc']);
        $end = $this->finance->totals($ids, null, $f['to_utc']);
        $rows = [];
        $sum = ['accrued' => '0.00', 'paid' => '0.00', 'adjustments' => '0.00', 'debt' => '0.00', 'overpayment' => '0.00'];
        foreach ($period as $uid => $t) {
            $p = FinanceService::figures($t);
            $e = FinanceService::figures($end[$uid] ?? $t);
            if (Money::cmp($p['accrued'], '0') === 0 && Money::cmp($p['paid'], '0') === 0 && Money::cmp($p['adjustments'], '0') === 0 && Money::cmp($e['balance'], '0') === 0) {
                continue;
            }
            $rows[] = [
                'executor' => $t['full_name'], 'accrued' => $p['accrued'], 'paid' => $p['paid'], 'adjustments' => $p['adjustments'],
                'balance' => $e['balance'], 'debt' => $e['debt'], 'overpayment' => $e['overpayment'],
            ];
            foreach (['accrued', 'paid', 'adjustments'] as $k) {
                $sum[$k] = Money::add($sum[$k], $p[$k]);
            }
            $sum['debt'] = Money::add($sum['debt'], $e['debt']);
            $sum['overpayment'] = Money::add($sum['overpayment'], $e['overpayment']);
        }
        $types = $this->stats->workTypesFor($f);
        return $this->base('Общий финансовый отчёт', $f) + ['rows' => $rows, 'sum' => $sum, 'by_work_type' => $types['items'], 'types_totals' => $types['totals']];
    }

    // ------------------------------------------------------------ 8.5 Фотоотчёт

    public function photos(array $f): array
    {
        $items = [];
        $total = 0;
        if (!$f['door_missing']) {
            $filters = ['status' => 'active', 'order' => 'asc', 'from_utc' => $f['from_utc'], 'to_utc' => $f['to_utc']];
            foreach (['user_id', 'work_type_id', 'door_id'] as $k) {
                if ($f[$k] !== null) {
                    $filters[$k] = $f[$k];
                }
            }
            [$rows, $total] = $this->records->search($filters, self::PHOTO_REPORT_MAX_RECORDS, 0);
            if ($total > self::PHOTO_REPORT_MAX_RECORDS) {
                throw new ApiException('REPORT_TOO_LARGE',
                    "В фотоотчёт попадает {$total} работ, максимум " . self::PHOTO_REPORT_MAX_RECORDS . '. Сузьте период или добавьте фильтры (исполнитель, вид работы, дверь).', 422,
                    ['records' => $total, 'max' => self::PHOTO_REPORT_MAX_RECORDS]);
            }
            foreach ($rows as $r) {
                $all = $this->photos->listByRecord((int) $r['id']);
                $shown = [];
                foreach (array_slice($all, 0, self::PHOTOS_PER_RECORD) as $p) {
                    $path = $this->storage->resolve((string) ($p['optimized_file_name'] ?: $p['file_name']));
                    if ($path !== null) {
                        $uri = \App\Helpers\PhotoThumb::dataUri($path);
                        if ($uri !== null) {
                            $shown[] = $uri;
                        }
                    }
                }
                $items[] = [
                    'id' => (int) $r['id'], 'door_number' => $r['door_number'], 'work_type' => $r['work_type_name'],
                    'executor' => $r['user_name'], 'completed_at' => $r['completed_at'], 'comment' => $r['comment'],
                    'photos' => $shown, 'photos_total' => count($all),
                ];
            }
        }
        return $this->base('Фотоотчёт', $f) + ['items' => $items, 'total' => $total];
    }
}
