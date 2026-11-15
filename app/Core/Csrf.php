<?php
declare(strict_types=1);

namespace App\Core;

/** CSRF: токен хранится в сессии и передаётся клиентом в заголовке X-CSRF-Token для всех небезопасных методов. */
final class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function rotate(): string
    {
        Session::start();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function verify(Request $request): void
    {
        $sent = (string) $request->header('X-CSRF-Token');
        $real = (string) ($_SESSION['csrf'] ?? '');
        if ($real === '' || !hash_equals($real, $sent)) {
            throw new ApiException('CSRF_INVALID', 'Недействительный токен безопасности. Обновите страницу и повторите.', 403);
        }
    }
}
