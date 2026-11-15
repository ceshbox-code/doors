<?php
declare(strict_types=1);

namespace App\Helpers;

final class Dates
{
    /** 'Y-m-d H:i:s' (UTC из БД) -> ISO 8601 с суффиксом Z. */
    public static function toIso(?string $dbValue): ?string
    {
        return $dbValue === null ? null : str_replace(' ', 'T', $dbValue) . 'Z';
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** ISO 8601 с обязательным часовым поясом (Z или смещение) -> 'Y-m-d H:i:s' в UTC; иначе null. */
    public static function parseToUtc(string $value): ?string
    {
        $v = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $v)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /** Начало календарного дня 'YYYY-MM-DD' в часовом поясе предприятия, переведённое в UTC (или начало следующего дня). */
    public static function localDayToUtc(string $date, string $timezone, bool $nextDay = false): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone($timezone));
        } catch (\Exception) {
            return null;
        }
        if ($nextDay) {
            $d = $d->modify('+1 day');
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function validDate(string $date): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Разница в днях между двумя датами YYYY-MM-DD (to - from). */
    public static function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }
}
