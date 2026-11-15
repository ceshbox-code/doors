<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Core\Config;

/** Форматирование для отчётов (русская локаль). Деньги — только строки, без float. */
final class Format
{
    /** '1234567.5' -> '1 234 567,50' (неразрывные пробелы). */
    public static function money(?string $value): string
    {
        if ($value === null || $value === '') {
            return '0,00';
        }
        $v = bcadd($value, '0', 2);
        $neg = str_starts_with($v, '-');
        [$int, $dec] = explode('.', ltrim($v, '-'));
        $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{00A0}", $int);
        return ($neg ? '-' : '') . $int . ',' . $dec;
    }

    /** '2.500' -> '2,5'; '1.000' -> '1'. */
    public static function qty(?string $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        $s = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        return str_replace('.', ',', $s === '' ? '0' : $s);
    }

    public static function dateTime(?string $utc, string $timezone): string
    {
        return $utc === null ? '' : self::local($utc, $timezone)->format('d.m.Y H:i');
    }

    public static function date(?string $utc, string $timezone): string
    {
        return $utc === null ? '' : self::local($utc, $timezone)->format('d.m.Y');
    }

    /** 'YYYY-MM-DD' -> 'DD.MM.YYYY'. */
    public static function dateIso(string $ymd): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) ? "{$m[3]}.{$m[2]}.{$m[1]}" : $ymd;
    }

    public static function currency(): string
    {
        return (string) Config::get('app.currency', '₽');
    }

    private static function local(string $utc, string $timezone): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($timezone));
    }
}
