<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Format;
use App\Helpers\Money;

/** HTML-шаблоны PDF-отчётов в едином корпоративном стиле (ТЗ 8.6). Все данные экранируются. */
final class ReportHtml
{
    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function css(): string
    {
        return '
body { font-family: dejavusans; font-size: 9pt; color: #1b2733; }
.org { font-size: 8.5pt; color: #5b6b7b; text-transform: uppercase; letter-spacing: 0.5pt; }
h1 { font-size: 16pt; color: #1f3a5f; margin: 2pt 0 4pt 0; }
h2 { font-size: 11pt; color: #1f3a5f; margin: 14pt 0 4pt 0; border-bottom: 1pt solid #1f3a5f; padding-bottom: 2pt; }
h3 { font-size: 10pt; color: #1b2733; margin: 10pt 0 2pt 0; }
.sub { font-size: 8.5pt; color: #5b6b7b; margin: 0 0 2pt 0; }
.head { border-bottom: 2pt solid #1f3a5f; padding-bottom: 6pt; margin-bottom: 8pt; }
.note { font-size: 8.5pt; margin: 6pt 0; }
.muted { color: #7a8794; text-align: center; padding: 8pt; }
table.data { border-collapse: collapse; width: 100%; margin-top: 3pt; }
table.data th { background-color: #1f3a5f; color: #ffffff; padding: 4pt 5pt; text-align: left; font-size: 8.5pt; }
table.data td { border-bottom: 0.5pt solid #d5dde6; padding: 3pt 5pt; vertical-align: top; }
table.data th.num, table.data td.num { text-align: right; white-space: nowrap; }
table.data tr { page-break-inside: avoid; }
table.data tr.total td { font-weight: bold; background-color: #eef3f9; border-top: 1pt solid #1f3a5f; }
table.data tr.struck td { color: #98a2ad; text-decoration: line-through; }
table.kpi { border-collapse: separate; border-spacing: 4pt; width: 100%; margin: 4pt 0; }
table.kpi td { background-color: #eef3f9; border: 0.5pt solid #d5dde6; padding: 6pt; text-align: center; }
table.kpi .v { font-size: 13pt; font-weight: bold; color: #1f3a5f; }
table.kpi .l { font-size: 7.5pt; color: #5b6b7b; }
table.rec { width: 100%; border-collapse: collapse; border: 0.5pt solid #c9d3de; margin-bottom: 8pt; page-break-inside: avoid; }
table.rec td { padding: 3pt 4pt; vertical-align: top; }
table.rec td.rh { background-color: #eef3f9; }
table.rec td.ph { text-align: center; }
';
    }

    /** Шапка отчёта: организация, название, период, условия выборки, дата формирования. */
    public static function layout(array $b, string $body): string
    {
        $h = '<div class="head"><div class="org">' . self::e($b['org']) . '</div><h1>' . self::e($b['title']) . '</h1>';
        if ($b['period'] !== '') {
            $h .= '<div class="sub">Период: <b>' . self::e($b['period']) . '</b></div>';
        }
        foreach ($b['filters'] as $line) {
            $h .= '<div class="sub">' . self::e($line) . '</div>';
        }
        return $h . '<div class="sub">Дата формирования: ' . self::e($b['generated']) . '</div></div>' . $body;
    }

    private static function cell($c): string
    {
        return is_array($c) ? (string) ($c['html'] ?? '') : self::e($c);
    }

    /**
     * @param array<int,array{t:string,num?:bool,w?:string}> $cols
     * @param array<int,array<int,mixed>> $rows ячейка — строка (экранируется) или ['html' => ...]
     */
    private static function table(array $cols, array $rows, ?array $total = null, string $empty = 'Нет данных за выбранный период'): string
    {
        $h = '<table class="data"><thead><tr>';
        foreach ($cols as $c) {
            $h .= '<th' . (!empty($c['num']) ? ' class="num"' : '') . (!empty($c['w']) ? ' width="' . $c['w'] . '"' : '') . '>' . self::e($c['t']) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        if (!$rows) {
            $h .= '<tr><td colspan="' . count($cols) . '" class="muted">' . self::e($empty) . '</td></tr>';
        }
        foreach ($rows as $r) {
            $cls = isset($r['_class']) ? ' class="' . $r['_class'] . '"' : '';
            $h .= '<tr' . $cls . '>';
            foreach ($cols as $i => $c) {
                $h .= '<td' . (!empty($c['num']) ? ' class="num"' : '') . '>' . self::cell($r[$i] ?? '') . '</td>';
            }
            $h .= '</tr>';
        }
        if ($total !== null && $rows) {
            $h .= '<tr class="total">';
            foreach ($cols as $i => $c) {
                $h .= '<td' . (!empty($c['num']) ? ' class="num"' : '') . '>' . self::cell($total[$i] ?? '') . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    }

    /** @param array<int,array{0:string,1:string}> $items [подпись, значение] */
    private static function kpi(array $items): string
    {
        $h = '<table class="kpi"><tr>';
        foreach ($items as [$label, $value]) {
            $h .= '<td><div class="v">' . self::e($value) . '</div><div class="l">' . self::e($label) . '</div></td>';
        }
        return $h . '</tr></table>';
    }

    private static function money(?string $v, string $cur): string
    {
        return Format::money($v) . ' ' . $cur;
    }

    private static function qty(string $q, string $unit): string
    {
        return Format::qty($q) . ' ' . $unit;
    }

    // ------------------------------------------------------------------ 8.1

    public static function production(array $d): string
    {
        $cur = $d['currency'];
        $c = $d['counts'];
        $m = $d['money'];
        $h = self::kpi([
            ['Уникальных дверей', (string) $c['doors']], ['Ремонтов', (string) $c['repairs']], ['Упаковок', (string) $c['packings']],
            ['Дополнительных работ', (string) $c['other_works']], ['Всего операций', (string) $c['operations']],
            ['Начислено', self::money($m['accrued'], $cur)],
        ]);
        if ($m['paid'] !== null) {
            $h .= '<p class="note">Выплачено за период: <b>' . self::e(self::money($m['paid'], $cur)) . '</b>. Задолженность на конец периода: <b>'
                . self::e(self::money($m['debt'], $cur)) . '</b>' . (Money::cmp((string) $m['overpayment'], '0') > 0 ? '. Переплата: <b>' . self::e(self::money($m['overpayment'], $cur)) . '</b>' : '') . '.</p>';
        } elseif (!empty($m['note'])) {
            $h .= '<p class="note">' . self::e($m['note']) . '.</p>';
        }

        $h .= '<h2>Работы по видам</h2>';
        $rows = [];
        $doors = 0;
        foreach ($d['by_work_type'] as $t) {
            $rows[] = [$t['work_type']['name'], (string) $t['works_count'], (string) $t['doors_count'], self::qty($t['quantity'], $t['work_type']['unit']), Format::money($t['amount'])];
            $doors += $t['doors_count'];
        }
        $h .= self::table(
            [['t' => 'Вид работы'], ['t' => 'Работ', 'num' => true], ['t' => 'Дверей', 'num' => true], ['t' => 'Объём', 'num' => true], ['t' => 'Начислено, ' . $cur, 'num' => true]],
            $rows, ['Итого', (string) $d['totals']['works_count'], '', '', Format::money($d['totals']['amount'])]
        );

        $h .= '<h2>Детализация по исполнителям</h2>';
        if (!$d['by_executor']) {
            $h .= '<p class="muted">Нет данных за выбранный период</p>';
        }
        foreach ($d['by_executor'] as $u) {
            $line = 'Работ: ' . $u['works_count'] . ' · Дверей: ' . $u['doors_count'] . ' · Начислено: ' . self::money($u['accrued'], $cur);
            if ($u['paid'] !== null) {
                $line .= ' · Выплачено: ' . self::money($u['paid'], $cur) . ' · Остаток на конец периода: ' . self::money($u['balance_at_end'], $cur);
            }
            $h .= '<h3>' . self::e($u['user']['full_name']) . '</h3><div class="sub">' . self::e($line) . '</div>';
            $rows = [];
            foreach ($u['by_work_type'] as $t) {
                $rows[] = [$t['name'], (string) $t['works_count'], self::qty($t['quantity'], $t['unit']), Format::money($t['amount'])];
            }
            $h .= self::table(
                [['t' => 'Вид работы'], ['t' => 'Работ', 'num' => true], ['t' => 'Объём', 'num' => true], ['t' => 'Начислено, ' . $cur, 'num' => true]],
                $rows, ['Итого', (string) $u['works_count'], '', Format::money($u['accrued'])]
            );
        }
        return $h;
    }

    // ------------------------------------------------------------------ 8.2

    public static function payslip(array $d): string
    {
        $cur = $d['currency'];
        $run = $d['run'];
        $s = $d['summary'];
        $tz = $d['tz'];
        $h = '<div class="sub">Исполнитель</div><h2 style="margin-top:0">' . self::e($d['executor']) . '</h2>';
        $h .= '<div class="sub">Расчётный период: <b>' . self::e(self::range($run['period_from'], $run['period_to'])) . '</b> · Расчёт № ' . (int) $run['id'] . '</div>';
        if ($run['status'] === 'confirmed') {
            $h .= '<div class="sub">Расчёт подтверждён: ' . self::e(self::iso($run['confirmed_at'], $tz)) . '</div>';
        } else {
            $h .= '<p class="note"><b>ЧЕРНОВИК — документ не подтверждён и не является расчётным листом.</b></p>';
        }

        $h .= '<h2>Выполненные работы</h2>';
        $rows = [];
        $n = 0;
        foreach ($run['items'] as $it) {
            $n++;
            $row = [
                (string) $n, self::iso($it['completed_at'], $tz, true), $it['door_number'] ?? '—', $it['work_type_name'],
                self::qty($it['quantity'], $it['unit']), Format::money($it['unit_price']),
                $it['voided'] ? 'аннулирована' : Format::money($it['total_amount']),
            ];
            if ($it['voided']) {
                $row['_class'] = 'struck';
            }
            $rows[] = $row;
        }
        $h .= self::table(
            [['t' => '№', 'w' => '5%'], ['t' => 'Дата'], ['t' => 'Дверь'], ['t' => 'Вид работы'], ['t' => 'Кол-во', 'num' => true], ['t' => 'Расценка, ' . $cur, 'num' => true], ['t' => 'Сумма, ' . $cur, 'num' => true]],
            $rows, ['', '', '', 'Итого начислено по расчёту', '', '', Format::money($s['accrued'])]
        );
        if (Money::cmp($s['voided'], '0') > 0) {
            $h .= '<p class="note">Аннулированные после подтверждения работы (' . self::e(self::money($s['voided'], $cur)) . ') в итог не входят.</p>';
        }

        if ($d['adjustments']) {
            $h .= '<h2>Корректировки</h2>';
            $rows = [];
            foreach ($d['adjustments'] as $a) {
                $rows[] = [self::iso($a['created_at'], $tz), self::adjType($a['type']), $a['reason'], Format::money($a['amount']) . ($a['affects_balance'] ? '' : ' (справочно)')];
            }
            $h .= self::table(
                [['t' => 'Дата'], ['t' => 'Вид'], ['t' => 'Основание'], ['t' => 'Сумма, ' . $cur, 'num' => true]],
                $rows, ['', '', 'Итого корректировок (учитываются в расчёте)', Format::money($s['adjustments'])]
            );
        }

        $h .= '<h2>Итоги</h2><table class="data"><tbody>';
        $lines = [
            ['Начислено по расчёту', self::money($s['accrued'], $cur)],
            ['Корректировки', self::money($s['adjustments'], $cur)],
            ['Итого начислений', self::money($s['total_accrued'], $cur)],
            ['Выплачено', self::money($s['paid'], $cur)],
        ];
        foreach ($lines as [$l, $v]) {
            $h .= '<tr><td>' . self::e($l) . '</td><td class="num">' . self::e($v) . '</td></tr>';
        }
        $h .= '<tr class="total"><td>' . (Money::cmp($s['overpayment'], '0') > 0 ? 'Переплата' : 'Остаток к выплате') . '</td><td class="num">'
            . self::e(self::money(Money::cmp($s['overpayment'], '0') > 0 ? $s['overpayment'] : $s['remaining'], $cur)) . '</td></tr></tbody></table>';
        $h .= '<p class="note">Общий остаток исполнителя по всем начислениям и выплатам на дату формирования: <b>'
            . self::e(self::money($s['overall_balance'], $cur)) . '</b>.</p>';
        return $h;
    }

    // ------------------------------------------------------------------ 8.3

    public static function payments(array $d): string
    {
        $cur = $d['currency'];
        $tz = $d['tz'];
        $showStatus = $d['status'] !== 'posted';
        $rows = [];
        foreach ($d['items'] as $p) {
            $alloc = [];
            foreach ($p['allocations'] as $a) {
                $alloc[] = self::e(($a['door_number'] ?? ('работа №' . $a['work_record_id'])) . ' · ' . $a['work_type_name'] . ' — ' . Format::money($a['amount']));
            }
            $row = [self::iso($p['payment_date'], $tz), $p['executor'], Format::money($p['amount']), $p['method'], $p['reference'] ?? '', ['html' => implode('<br>', $alloc)]];
            if ($showStatus) {
                $row[] = self::payStatus($p['status']);
                if ($p['status'] === 'cancelled') {
                    $row['_class'] = 'struck';
                }
            }
            $rows[] = $row;
        }
        $cols = [['t' => 'Дата'], ['t' => 'Исполнитель'], ['t' => 'Сумма, ' . $cur, 'num' => true], ['t' => 'Способ'], ['t' => '№ операции / документа'], ['t' => 'Распределение по начислениям']];
        $total = ['Итого выплачено', '', Format::money($d['total_posted']), '', '', ''];
        if ($showStatus) {
            $cols[] = ['t' => 'Статус'];
            $total[] = '';
        }
        $h = self::table($cols, $rows, $total);
        if ($d['by_method']) {
            $h .= '<h2>По способам выплаты</h2>';
            $m = [];
            foreach ($d['by_method'] as $name => $sum) {
                $m[] = [(string) $name, Format::money($sum)];
            }
            $h .= self::table([['t' => 'Способ'], ['t' => 'Сумма, ' . $cur, 'num' => true]], $m, ['Итого', Format::money($d['total_posted'])]);
        }
        return $h;
    }

    // ------------------------------------------------------------------ 8.4

    public static function finance(array $d): string
    {
        $cur = $d['currency'];
        $s = $d['sum'];
        $h = self::kpi([
            ['Начислено', self::money($s['accrued'], $cur)], ['Выплачено', self::money($s['paid'], $cur)],
            ['Корректировки', self::money($s['adjustments'], $cur)], ['Задолженность', self::money($s['debt'], $cur)],
            ['Переплата', self::money($s['overpayment'], $cur)],
        ]);
        $h .= '<h2>Начисления, выплаты и задолженность по исполнителям</h2>';
        $rows = [];
        foreach ($d['rows'] as $r) {
            $rows[] = [$r['executor'], Format::money($r['accrued']), Format::money($r['paid']), Format::money($r['adjustments']), Format::money($r['debt']), Format::money($r['overpayment'])];
        }
        $h .= self::table(
            [['t' => 'Исполнитель'], ['t' => 'Начислено', 'num' => true], ['t' => 'Выплачено', 'num' => true], ['t' => 'Корректировки', 'num' => true], ['t' => 'Задолженность', 'num' => true], ['t' => 'Переплата', 'num' => true]],
            $rows, ['Итого', Format::money($s['accrued']), Format::money($s['paid']), Format::money($s['adjustments']), Format::money($s['debt']), Format::money($s['overpayment'])]
        );
        $h .= '<h2>Начисления по видам работ</h2>';
        $rows = [];
        foreach ($d['by_work_type'] as $t) {
            $rows[] = [$t['work_type']['name'], (string) $t['works_count'], Format::money($t['amount'])];
        }
        $h .= self::table([['t' => 'Вид работы'], ['t' => 'Работ', 'num' => true], ['t' => 'Начислено, ' . $cur, 'num' => true]],
            $rows, ['Итого', (string) $d['types_totals']['works_count'], Format::money($d['types_totals']['amount'])]);
        $h .= '<p class="note">Задолженность и переплата показаны на конец периода. Остаток = начислено − выплачено + ручные корректировки; '
            . 'начисления — по дате выполнения работ, выплаты — по дате выплаты. Суммы в ' . self::e($cur) . '.</p>';
        return $h;
    }

    // ------------------------------------------------------------------ 8.5

    public static function photos(array $d): string
    {
        $tz = $d['tz'];
        if (!$d['items']) {
            return '<p class="muted">Нет работ за выбранный период</p>';
        }
        $h = '<p class="note">Работ в отчёте: <b>' . count($d['items']) . '</b>.</p>';
        foreach ($d['items'] as $it) {
            $head = '<b>' . self::e($it['door_number'] !== null ? 'Дверь ' . $it['door_number'] : 'Работа № ' . $it['id']) . '</b> · '
                . self::e($it['work_type']) . ' · ' . self::e($it['executor']) . ' · ' . self::e(self::iso($it['completed_at'], $tz))
                . ' · <span style="color:#7a8794">работа № ' . (int) $it['id'] . '</span>';
            $h .= '<table class="rec"><tr><td class="rh" colspan="3">' . $head . '</td></tr>';
            if ($it['comment'] !== null && $it['comment'] !== '') {
                $h .= '<tr><td colspan="3" style="font-size:8.5pt">Комментарий: ' . self::e($it['comment']) . '</td></tr>';
            }
            $h .= '<tr>';
            for ($i = 0; $i < 3; $i++) {
                $h .= '<td class="ph" width="33%">' . (isset($it['photos'][$i]) ? '<img src="' . $it['photos'][$i] . '" style="width:170pt">' : '') . '</td>';
            }
            $h .= '</tr>';
            if (!$it['photos']) {
                $h .= '<tr><td colspan="3" class="muted">Фотография недоступна</td></tr>';
            } elseif ($it['photos_total'] > count($it['photos'])) {
                $h .= '<tr><td colspan="3" style="font-size:8pt;color:#7a8794">Показано ' . count($it['photos']) . ' из ' . (int) $it['photos_total'] . ' фотографий; остальные — в архиве системы.</td></tr>';
            }
            $h .= '</table>';
        }
        return $h;
    }

    // ------------------------------------------------------------------ вспомогательное

    private static function iso(?string $iso, string $tz, bool $dateOnly = false): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        $utc = str_replace(['T', 'Z'], [' ', ''], $iso);
        return $dateOnly ? Format::date($utc, $tz) : Format::dateTime($utc, $tz);
    }

    private static function range(string $from, string $to): string
    {
        return $from === $to ? Format::dateIso($from) : Format::dateIso($from) . ' — ' . Format::dateIso($to);
    }

    public static function adjType(string $t): string
    {
        return [
            'manual' => 'Ручная корректировка', 'overpayment' => 'Переплата', 'payroll_correction' => 'Корректировка расчёта',
            'void_paid_work' => 'Аннулирование оплаченной работы', 'cancel_payment' => 'Отмена выплаты',
        ][$t] ?? $t;
    }

    public static function payStatus(string $s): string
    {
        return ['posted' => 'Подтверждена', 'draft' => 'Черновик', 'cancelled' => 'Отменена'][$s] ?? $s;
    }
}
