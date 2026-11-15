<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Helpers\Format;
use App\Helpers\Money;
use App\Repositories\PaymentRepository;
use App\Repositories\WorkRecordRepository;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Наборы данных для CSV/XLSX (ТЗ 7.5). Строятся из тех же слоёв, что JSON-статистика и PDF, и учитывают те же фильтры.
 */
final class ExportService
{
    public const DATASETS = ['works', 'payments', 'balances', 'stats-users', 'stats-types', 'stats-daily', 'stats-doors'];
    public const MAX_ROWS = 50000;

    private StatsService $stats;
    private ReportService $reports;
    private WorkRecordRepository $records;
    private PaymentRepository $payments;

    public function __construct()
    {
        $this->stats = new StatsService();
        $this->reports = new ReportService();
        $this->records = new WorkRecordRepository();
        $this->payments = new PaymentRepository();
    }

    public function dataset(string $name, array $f, array $query): Dataset
    {
        switch ($name) {
            case 'works':       return $this->works($f, $this->enum($query, 'status', ['active', 'voided', 'all'], 'active'));
            case 'payments':    return $this->paymentsSet($f, $this->enum($query, 'status', ['posted', 'draft', 'cancelled', 'all'], 'posted'));
            case 'balances':    return $this->balances($f);
            case 'stats-users': return $this->statsUsers($f);
            case 'stats-types': return $this->statsTypes($f);
            case 'stats-daily': return $this->statsDaily($f);
            case 'stats-doors': return $this->statsDoors($f);
        }
        throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422, ['dataset' => implode(', ', self::DATASETS)]);
    }

    private function enum(array $q, string $key, array $allowed, string $default): string
    {
        $v = isset($q[$key]) && is_string($q[$key]) && $q[$key] !== '' ? $q[$key] : $default;
        if (!in_array($v, $allowed, true)) {
            throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422, [$key => implode(', ', $allowed)]);
        }
        return $v;
    }

    private function meta(string $title, array $f, array $extra = []): array
    {
        $b = $this->reports->base($title, $f);
        return array_merge(['Организация: ' . $b['org'], 'Отчёт: ' . $title, 'Период: ' . $b['period']], $b['filters'], $extra, ['Сформировано: ' . $b['generated']]);
    }

    private function works(array $f, string $status): Dataset
    {
        $rows = [];
        $sum = '0.00';
        if (!$f['door_missing']) {
            $filters = ['status' => $status, 'order' => 'asc', 'from_utc' => $f['from_utc'], 'to_utc' => $f['to_utc']];
            foreach (['user_id', 'work_type_id', 'door_id'] as $k) {
                if ($f[$k] !== null) {
                    $filters[$k] = $f[$k];
                }
            }
            [$list, $total] = $this->records->search($filters, self::MAX_ROWS, 0);
            if ($total > self::MAX_ROWS) {
                throw new ApiException('REPORT_TOO_LARGE', "В выборке {$total} строк, максимум " . self::MAX_ROWS . '. Сузьте период или добавьте фильтры.', 422);
            }
            foreach ($list as $r) {
                $voided = $r['voided_at'] !== null;
                $rows[] = [
                    'id' => (int) $r['id'], 'completed_at' => $r['completed_at'], 'door_number' => $r['door_number'], 'work_type' => $r['work_type_name'],
                    'executor' => $r['user_name'], 'quantity' => $r['quantity'], 'unit' => $r['unit'], 'unit_price' => $r['unit_price'],
                    'total_amount' => $r['total_amount'], 'status' => $voided ? 'Аннулирована' : 'Действует',
                    'comment' => $r['comment'], 'photos_count' => (int) $r['photos_count'],
                ];
                if (!$voided) {
                    $sum = Money::add($sum, (string) $r['total_amount']);
                }
            }
        }
        $cols = [
            ['key' => 'id', 'title' => '№ работы', 'type' => 'int'], ['key' => 'completed_at', 'title' => 'Дата и время', 'type' => 'datetime'],
            ['key' => 'door_number', 'title' => 'Дверь', 'type' => 'text'], ['key' => 'work_type', 'title' => 'Вид работы', 'type' => 'text'],
            ['key' => 'executor', 'title' => 'Исполнитель', 'type' => 'text'], ['key' => 'quantity', 'title' => 'Количество', 'type' => 'qty'],
            ['key' => 'unit', 'title' => 'Ед.', 'type' => 'text'], ['key' => 'unit_price', 'title' => 'Расценка', 'type' => 'money'],
            ['key' => 'total_amount', 'title' => 'Сумма начисления', 'type' => 'money'], ['key' => 'status', 'title' => 'Статус', 'type' => 'text'],
            ['key' => 'comment', 'title' => 'Комментарий', 'type' => 'text'], ['key' => 'photos_count', 'title' => 'Фото', 'type' => 'int'],
        ];
        return new Dataset('Выполненные работы', $cols, $rows, ['total_amount' => $sum], $this->meta('Выполненные работы', $f, ['Статус записей: ' . $status, 'Итог — только по действующим работам']));
    }

    private function paymentsSet(array $f, string $status): Dataset
    {
        $filters = ['from_utc' => $f['from_utc'], 'to_utc' => $f['to_utc']];
        if ($f['user_id'] !== null) {
            $filters['user_id'] = $f['user_id'];
        }
        if ($status !== 'all') {
            $filters['status'] = $status;
        }
        [$list] = $this->payments->search($filters, self::MAX_ROWS, 0);
        $rows = [];
        $sum = '0.00';
        foreach (array_reverse($list) as $r) {
            $alloc = [];
            foreach ($this->payments->allocations((int) $r['id']) as $a) {
                $alloc[] = ($a['door_number'] ?? ('работа №' . $a['work_record_id'])) . ': ' . Money::add((string) $a['amount'], '0');
            }
            $rows[] = [
                'id' => (int) $r['id'], 'payment_date' => $r['payment_date'], 'executor' => $r['user_name'], 'amount' => $r['amount'],
                'method' => $r['payment_method'], 'reference' => $r['reference'], 'status' => ReportHtml::payStatus($r['status']),
                'run' => $r['payroll_run_id'] === null ? null : (int) $r['payroll_run_id'], 'comment' => $r['comment'], 'allocations' => implode('; ', $alloc),
            ];
            if ($r['status'] === 'posted') {
                $sum = Money::add($sum, (string) $r['amount']);
            }
        }
        $cols = [
            ['key' => 'id', 'title' => '№ выплаты', 'type' => 'int'], ['key' => 'payment_date', 'title' => 'Дата выплаты', 'type' => 'datetime'],
            ['key' => 'executor', 'title' => 'Исполнитель', 'type' => 'text'], ['key' => 'amount', 'title' => 'Сумма', 'type' => 'money'],
            ['key' => 'method', 'title' => 'Способ', 'type' => 'text'], ['key' => 'reference', 'title' => '№ операции / документа', 'type' => 'text'],
            ['key' => 'status', 'title' => 'Статус', 'type' => 'text'], ['key' => 'run', 'title' => '№ расчёта', 'type' => 'int'],
            ['key' => 'comment', 'title' => 'Комментарий', 'type' => 'text'], ['key' => 'allocations', 'title' => 'Распределение (дверь: сумма)', 'type' => 'text'],
        ];
        return new Dataset('Выплаты', $cols, $rows, ['amount' => $sum], $this->meta('Выплаты', $f, ['Статус выплат: ' . $status, 'Итог — только по подтверждённым выплатам']));
    }

    private function balances(array $f): Dataset
    {
        $d = $this->reports->finance($f);
        $cols = [
            ['key' => 'executor', 'title' => 'Исполнитель', 'type' => 'text'], ['key' => 'accrued', 'title' => 'Начислено', 'type' => 'money'],
            ['key' => 'paid', 'title' => 'Выплачено', 'type' => 'money'], ['key' => 'adjustments', 'title' => 'Корректировки', 'type' => 'money'],
            ['key' => 'balance', 'title' => 'Остаток на конец периода', 'type' => 'money'], ['key' => 'debt', 'title' => 'Задолженность', 'type' => 'money'],
            ['key' => 'overpayment', 'title' => 'Переплата', 'type' => 'money'],
        ];
        return new Dataset('Финансовые показатели', $cols, $d['rows'], [
            'accrued' => $d['sum']['accrued'], 'paid' => $d['sum']['paid'], 'adjustments' => $d['sum']['adjustments'],
            'debt' => $d['sum']['debt'], 'overpayment' => $d['sum']['overpayment'],
        ], $this->meta('Финансовые показатели', $f));
    }

    private function statsUsers(array $f): Dataset
    {
        $d = $this->stats->usersFor($f);
        $rows = [];
        foreach ($d['items'] as $u) {
            foreach ($u['by_work_type'] as $t) {
                $rows[] = ['executor' => $u['user']['full_name'], 'work_type' => $t['name'], 'works' => $t['works_count'], 'quantity' => $t['quantity'], 'unit' => $t['unit'], 'amount' => $t['amount']];
            }
        }
        $cols = [
            ['key' => 'executor', 'title' => 'Исполнитель', 'type' => 'text'], ['key' => 'work_type', 'title' => 'Вид работы', 'type' => 'text'],
            ['key' => 'works', 'title' => 'Работ', 'type' => 'int'], ['key' => 'quantity', 'title' => 'Объём', 'type' => 'qty'],
            ['key' => 'unit', 'title' => 'Ед.', 'type' => 'text'], ['key' => 'amount', 'title' => 'Начислено', 'type' => 'money'],
        ];
        return new Dataset('Статистика по исполнителям', $cols, $rows, ['works' => $d['totals']['works_count'], 'amount' => $d['totals']['accrued']], $this->meta('Статистика по исполнителям', $f));
    }

    private function statsTypes(array $f): Dataset
    {
        $d = $this->stats->workTypesFor($f);
        $cat = ['repair' => 'Ремонт', 'packing' => 'Упаковка', 'other' => 'Дополнительные работы'];
        $rows = [];
        foreach ($d['items'] as $t) {
            $rows[] = [
                'work_type' => $t['work_type']['name'], 'category' => $cat[$t['work_type']['category']] ?? $t['work_type']['category'], 'works' => $t['works_count'],
                'doors' => $t['doors_count'], 'quantity' => $t['quantity'], 'unit' => $t['work_type']['unit'], 'amount' => $t['amount'],
            ];
        }
        $cols = [
            ['key' => 'work_type', 'title' => 'Вид работы', 'type' => 'text'], ['key' => 'category', 'title' => 'Категория', 'type' => 'text'],
            ['key' => 'works', 'title' => 'Работ', 'type' => 'int'], ['key' => 'doors', 'title' => 'Дверей', 'type' => 'int'],
            ['key' => 'quantity', 'title' => 'Объём', 'type' => 'qty'], ['key' => 'unit', 'title' => 'Ед.', 'type' => 'text'], ['key' => 'amount', 'title' => 'Начислено', 'type' => 'money'],
        ];
        return new Dataset('Статистика по видам работ', $cols, $rows, ['works' => $d['totals']['works_count'], 'amount' => $d['totals']['amount']], $this->meta('Статистика по видам работ', $f));
    }

    private function statsDaily(array $f): Dataset
    {
        $d = $this->stats->dailyFor($f);
        $rows = [];
        foreach ($d['items'] as $i) {
            $rows[] = ['date' => $i['date'], 'works' => $i['works_count'], 'doors' => $i['doors_count'], 'repairs' => $i['repairs'], 'packings' => $i['packings'], 'other' => $i['other_works'], 'amount' => $i['amount']];
        }
        $cols = [
            ['key' => 'date', 'title' => 'Дата', 'type' => 'localdate'], ['key' => 'works', 'title' => 'Работ', 'type' => 'int'], ['key' => 'doors', 'title' => 'Дверей', 'type' => 'int'],
            ['key' => 'repairs', 'title' => 'Ремонтов', 'type' => 'int'], ['key' => 'packings', 'title' => 'Упаковок', 'type' => 'int'],
            ['key' => 'other', 'title' => 'Доп. работ', 'type' => 'int'], ['key' => 'amount', 'title' => 'Начислено', 'type' => 'money'],
        ];
        return new Dataset('Работы по дням', $cols, $rows, ['works' => $d['totals']['works_count'], 'amount' => $d['totals']['amount']], $this->meta('Работы по дням', $f));
    }

    private function statsDoors(array $f): Dataset
    {
        $rows = [];
        foreach ($this->stats->doorsAll($f, self::MAX_ROWS) as $d) {
            $rows[] = [
                'number' => $d['door']['number'], 'works' => $d['works_count'], 'work_types' => $d['work_types'], 'amount' => $d['amount'], 'photos' => $d['photos_count'],
                'first_at' => $d['first_at'] === null ? null : str_replace(['T', 'Z'], [' ', ''], $d['first_at']),
                'last_at' => $d['last_at'] === null ? null : str_replace(['T', 'Z'], [' ', ''], $d['last_at']),
            ];
        }
        $cols = [
            ['key' => 'number', 'title' => 'Дверь', 'type' => 'text'], ['key' => 'works', 'title' => 'Работ', 'type' => 'int'],
            ['key' => 'work_types', 'title' => 'Виды работ', 'type' => 'text'], ['key' => 'amount', 'title' => 'Начислено', 'type' => 'money'],
            ['key' => 'photos', 'title' => 'Фото', 'type' => 'int'], ['key' => 'first_at', 'title' => 'Первая операция', 'type' => 'datetime'],
            ['key' => 'last_at', 'title' => 'Последняя операция', 'type' => 'datetime'],
        ];
        $sumWorks = array_sum(array_column($rows, 'works'));
        $sumAmount = '0.00';
        foreach ($rows as $r) {
            $sumAmount = Money::add($sumAmount, (string) $r['amount']);
        }
        return new Dataset('Статистика по дверям', $cols, $rows, ['works' => $sumWorks, 'amount' => $sumAmount], $this->meta('Статистика по дверям', $f));
    }

    // ------------------------------------------------------------------ форматы

    public function csv(Dataset $d, string $decimal): string
    {
        return CsvWriter::build($d, $decimal === 'dot' ? 'dot' : 'comma', (string) Config::get('app.timezone', 'UTC'));
    }

    /** XLSX: лист с данными (строка 1 — заголовки, закреплена, автофильтр, числа и даты — настоящие значения) + лист «Параметры». */
    public function xlsx(Dataset $d): string
    {
        $tz = (string) Config::get('app.timezone', 'UTC');
        $ss = new Spreadsheet();
        $ss->getProperties()->setCreator((string) Config::get('app.org_name', 'Организация'))->setTitle($d->title);
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle(self::sheetTitle($d->title));

        $widths = [];
        foreach ($d->columns as $i => $c) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValueExplicit($col . '1', $c['title'], DataType::TYPE_STRING);
            $widths[$i] = mb_strlen($c['title']) + 3;
        }
        $lastCol = Coordinate::stringFromColumnIndex(max(1, count($d->columns)));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE3EAF3');
        $sheet->getStyle("A1:{$lastCol}1")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        $r = 2;
        foreach ($d->rows as $row) {
            foreach ($d->columns as $i => $c) {
                $widths[$i] = max($widths[$i], $this->writeCell($sheet, Coordinate::stringFromColumnIndex($i + 1) . $r, $c['type'], $row[$c['key']] ?? null, $tz));
            }
            $r++;
        }
        if ($d->totals !== null && $d->rows) {
            foreach ($d->columns as $i => $c) {
                $coord = Coordinate::stringFromColumnIndex($i + 1) . $r;
                if (array_key_exists($c['key'], $d->totals)) {
                    $this->writeCell($sheet, $coord, $c['type'], $d->totals[$c['key']], $tz);
                } elseif ($i === 0) {
                    $sheet->setCellValueExplicit($coord, 'ИТОГО', DataType::TYPE_STRING);
                }
            }
            $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        }
        $lastRow = $d->rows ? ($d->totals !== null ? $r : $r - 1) : 1;
        $formats = ['money' => '#,##0.00', 'qty' => '#,##0.###', 'datetime' => 'dd.mm.yyyy hh:mm', 'date' => 'dd.mm.yyyy', 'localdate' => 'dd.mm.yyyy'];
        foreach ($d->columns as $i => $c) {
            if (isset($formats[$c['type']]) && $lastRow >= 2) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->getStyle("{$col}2:{$col}{$lastRow}")->getNumberFormat()->setFormatCode($formats[$c['type']]);
            }
        }
        foreach ($widths as $i => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth((float) min(60, max(10, $w)));
        }
        $sheet->freezePane('A2');
        if ($d->rows) {
            $sheet->setAutoFilter("A1:{$lastCol}" . (count($d->rows) + 1));
        }

        // Условия выборки — на отдельном листе: данные остаются «чистой» таблицей
        $p = $ss->createSheet();
        $p->setTitle('Параметры');
        foreach (array_values($d->meta) as $i => $line) {
            $p->setCellValueExplicit('A' . ($i + 1), $line, DataType::TYPE_STRING);
        }
        $p->getColumnDimension('A')->setWidth(90);
        $ss->setActiveSheetIndex(0);

        $tmp = (string) tempnam(sys_get_temp_dir(), 'door_xlsx_');
        try {
            (new Xlsx($ss))->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $ss->disconnectWorksheets();
        }
        return $bytes;
    }

    /** @param mixed $v @return int длина текста для подбора ширины колонки */
    private function writeCell($sheet, string $coord, string $type, $v, string $tz): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        switch ($type) {
            case 'int':
                $sheet->setCellValueExplicit($coord, (int) $v, DataType::TYPE_NUMERIC);
                return strlen((string) (int) $v) + 2;
            case 'money':
                $sheet->setCellValueExplicit($coord, (float) $v, DataType::TYPE_NUMERIC);
                return strlen(Format::money((string) $v)) + 2;
            case 'qty':
                $sheet->setCellValueExplicit($coord, (float) $v, DataType::TYPE_NUMERIC);
                return strlen(Format::qty((string) $v)) + 2;
            case 'datetime':
            case 'date':
                $dt = (new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz));
                $sheet->setCellValueExplicit($coord, XlDate::PHPToExcel($dt), DataType::TYPE_NUMERIC);
                return 18;
            case 'localdate':
                $sheet->setCellValueExplicit($coord, XlDate::PHPToExcel(new \DateTimeImmutable((string) $v)), DataType::TYPE_NUMERIC);
                return 12;
            default:
                // Явный строковый тип: значение, начинающееся с «=», не будет выполнено как формула
                $sheet->setCellValueExplicit($coord, (string) $v, DataType::TYPE_STRING);
                return mb_strlen((string) $v) + 2;
        }
    }

    public static function sheetTitle(string $title): string
    {
        $t = preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $title) ?? '';
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        return mb_substr($t === '' ? 'Данные' : $t, 0, 31);
    }
}
