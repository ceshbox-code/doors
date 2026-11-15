<?php
declare(strict_types=1);

namespace App\Helpers;

/** Деньги и количества только в виде десятичных строк (bcmath), без float. */
final class Money
{
    /** @param mixed $v */
    private static function normalize($v, int $maxInt, int $maxScale): ?string
    {
        if (is_int($v)) {
            $s = (string) $v;
        } elseif (is_float($v)) {
            if (!is_finite($v)) {
                return null;
            }
            $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
            if ($s === '') {
                $s = '0';
            }
        } elseif (is_string($v)) {
            $s = trim($v);
        } else {
            return null;
        }
        return preg_match('/^\d{1,' . $maxInt . '}(\.\d{1,' . $maxScale . '})?$/', $s) ? $s : null;
    }

    /** Цена за единицу: до 7 цифр целой части, 2 знака. Вернёт 'N.NN' или null. @param mixed $v */
    public static function price($v): ?string
    {
        $s = self::normalize($v, 7, 2);
        return $s === null ? null : bcadd($s, '0', 2);
    }

    /** Количество: до 5 цифр целой части, 3 знака. Вернёт 'N.NNN' или null. @param mixed $v */
    public static function quantity($v): ?string
    {
        $s = self::normalize($v, 5, 3);
        return $s === null ? null : bcadd($s, '0', 3);
    }

    public static function isPositive(string $decimal): bool
    {
        return bccomp($decimal, '0', 3) > 0;
    }

    /** Начисление = количество × расценка, округление до копеек «вверх от половины». */
    public static function multiply(string $quantity, string $unitPrice): string
    {
        return bcadd(bcmul($quantity, $unitPrice, 5), '0.005', 2);
    }

    /** Денежная сумма операции: положительная, до 12 цифр целой части, 2 знака. @param mixed $v */
    public static function amount($v): ?string
    {
        $s = self::normalize($v, 12, 2);
        if ($s === null) {
            return null;
        }
        $n = bcadd($s, '0', 2);
        return bccomp($n, '0', 2) > 0 ? $n : null;
    }

    /** Знаковая сумма корректировки: не ноль, может быть отрицательной. @param mixed $v */
    public static function signed($v): ?string
    {
        $neg = false;
        if (is_int($v) || is_float($v)) {
            if ($v < 0) {
                $neg = true;
                $v = -$v;
            }
        } elseif (is_string($v)) {
            $v = trim($v);
            if (str_starts_with($v, '-')) {
                $neg = true;
                $v = substr($v, 1);
            } elseif (str_starts_with($v, '+')) {
                $v = substr($v, 1);
            }
        } else {
            return null;
        }
        $s = self::normalize($v, 12, 2);
        if ($s === null) {
            return null;
        }
        $n = bcadd($s, '0', 2);
        return bccomp($n, '0', 2) === 0 ? null : ($neg ? '-' . $n : $n);
    }

    public static function add(string $a, string $b): string { return bcadd($a, $b, 2); }
    public static function sub(string $a, string $b): string { return bcsub($a, $b, 2); }
    public static function cmp(string $a, string $b): int    { return bccomp($a, $b, 2); }

    /** Положительная часть: долг. */
    public static function positive(string $a): string { return bccomp($a, '0', 2) > 0 ? bcadd($a, '0', 2) : '0.00'; }

    /** Модуль отрицательной части: переплата. */
    public static function negativePart(string $a): string { return bccomp($a, '0', 2) < 0 ? bcmul($a, '-1', 2) : '0.00'; }
}
