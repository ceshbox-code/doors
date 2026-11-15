<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Format;

/**
 * CSV для русской локали Excel: UTF-8 с BOM, разделитель «;». Десятичный разделитель по умолчанию запятая
 * (decimal=dot — для машинной обработки). Текстовые ячейки защищены от формульных инъекций.
 */
final class CsvWriter
{
    public static function build(Dataset $d, string $decimal = 'comma', string $timezone = 'UTC'): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_map(static fn(array $c): string => self::text($c['title']), $d->columns), ';', '"', '');
        foreach ($d->rows as $row) {
            $line = [];
            foreach ($d->columns as $c) {
                $line[] = self::cell($c['type'], $row[$c['key']] ?? null, $decimal, $timezone);
            }
            fputcsv($fh, $line, ';', '"', '');
        }
        if ($d->totals !== null && $d->rows) {          // для пустой выборки строка итогов не нужна (как и в XLSX)
            $line = [];
            foreach ($d->columns as $i => $c) {
                $line[] = array_key_exists($c['key'], $d->totals)
                    ? self::cell($c['type'], $d->totals[$c['key']], $decimal, $timezone)
                    : ($i === 0 ? 'ИТОГО' : '');
            }
            fputcsv($fh, $line, ';', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /** @param mixed $v */
    public static function cell(string $type, $v, string $decimal, string $timezone): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        switch ($type) {
            case 'int':
                return (string) (int) $v;
            case 'money':
                $s = bcadd((string) $v, '0', 2);
                return $decimal === 'dot' ? $s : str_replace('.', ',', $s);
            case 'qty':
                $s = str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
                $s = $s === '' ? '0' : $s;
                return $decimal === 'dot' ? $s : str_replace('.', ',', $s);
            case 'datetime':
                return Format::dateTime((string) $v, $timezone);
            case 'date':
                return Format::date((string) $v, $timezone);
            case 'localdate':
                return Format::dateIso((string) $v);
            default:
                return self::text((string) $v);
        }
    }

    /** Текст, начинающийся с = + - @ (или управляющим символом), Excel выполнил бы как формулу — ставим апостроф. */
    public static function text(string $s): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $s) ? "'" . $s : $s;
    }
}
