<?php
declare(strict_types=1);

namespace App\Core;

/** Простой файловый журнал ошибок (ТЗ, п. 16). Полные технические ошибки — только сюда. */
final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        $dir = Config::get('paths.logs');
        $line = sprintf("[%s] %s %s\n", gmdate('Y-m-d H:i:s'), $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : '');
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
