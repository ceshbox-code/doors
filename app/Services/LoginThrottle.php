<?php
declare(strict_types=1);

namespace App\Services;

/** Правило ограничения попыток входа: окно 15 минут, 5 неудач на логин или 20 с одного IP. */
final class LoginThrottle
{
    public const WINDOW_MINUTES   = 15;
    public const MAX_PER_USERNAME = 5;
    public const MAX_PER_IP       = 20;

    public static function isBlocked(int $failuresByUsername, int $failuresByIp): bool
    {
        return $failuresByUsername >= self::MAX_PER_USERNAME || $failuresByIp >= self::MAX_PER_IP;
    }
}
