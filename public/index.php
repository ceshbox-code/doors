<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\{Config, Logger, Request, Response, Router};

date_default_timezone_set('UTC');

// Непредвиденные ошибки: подробности — только в журнал, пользователю — общий текст (ТЗ п. 16)
set_exception_handler(static function (Throwable $e): void {
    Logger::error($e::class . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    $msg = Config::get('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера';
    Response::error('INTERNAL_ERROR', $msg, 500)->send();
});

$router = new Router();
(require dirname(__DIR__) . '/app/routes.php')($router);
$router->dispatch(Request::fromGlobals())->send();
