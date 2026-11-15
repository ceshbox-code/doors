<?php
declare(strict_types=1);

namespace App\Helpers;

final class PasswordHasher
{
    private static ?string $dummy = null;

    private static function algo()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    /** Тратит то же время, что и проверка реального пароля, чтобы нельзя было отличить «нет такого логина». */
    public static function burn(string $password): void
    {
        self::$dummy ??= self::hash('dummy-password-for-timing');
        password_verify($password, self::$dummy);
    }
}
