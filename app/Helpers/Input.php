<?php
declare(strict_types=1);

namespace App\Helpers;

/** Аккуратное приведение входных значений JSON. */
final class Input
{
    /** @param mixed $v */
    public static function id($v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v) && strlen($v) <= 18 && (int) $v > 0) {
            return (int) $v;
        }
        return null;
    }

    /** @param mixed $v */
    public static function bool($v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 1 || $v === '1' || $v === 'true') {
            return true;
        }
        if ($v === 0 || $v === '0' || $v === 'false') {
            return false;
        }
        return null;
    }

    /** @param mixed $v */
    public static function str($v): ?string
    {
        return is_string($v) ? trim($v) : null;
    }

    /** @return array{0:int,1:int} [страница, на странице] */
    public static function paging(array $query, int $defaultPer = 50, int $maxPer = 200): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $per  = min($maxPer, max(1, (int) ($query['per_page'] ?? $defaultPer)));
        return [$page, $per];
    }
}
