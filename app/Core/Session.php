<?php
declare(strict_types=1);

namespace App\Core;

/** Безопасные сессии: HttpOnly, Secure (настраивается), SameSite=Lax, таймаут простоя. */
final class Session
{
    public const COOKIE = 'DOORSESS';

    public static function hasCookie(): bool
    {
        return isset($_COOKIE[self::COOKIE]);
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $idle = max(60, (int) Config::get('session.idle_minutes', 720) * 60);

        // Сессии хранятся в постоянном томе, чтобы переживать перезапуск контейнера.
        $dir = Config::get('paths.storage') . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }

        session_name(self::COOKIE);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => (bool) Config::get('session.cookie_secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', (string) $idle);
        session_start();

        $now = time();
        if (isset($_SESSION['last_activity']) && $now - (int) $_SESSION['last_activity'] > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = $now;
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}
