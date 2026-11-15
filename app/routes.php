<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DoorController;
use App\Controllers\ExportController;
use App\Controllers\FinanceController;
use App\Controllers\PaymentController;
use App\Controllers\PayrollController;
use App\Controllers\PhotoController;
use App\Controllers\RateController;
use App\Controllers\StatsController;
use App\Controllers\WorkRecordController;
use App\Controllers\WorkTypeController;
use App\Controllers\UserController;
use App\Core\{Database, Logger, Request, Response, Router};
use App\Middleware\Guard;

return static function (Router $router): void {
    $auth  = new AuthController();
    $users = new UserController();
    $doors = new DoorController();
    $types = new WorkTypeController();
    $rates = new RateController();
    $works = new WorkRecordController();
    $photos = new PhotoController();
    $fin = new FinanceController();
    $runs = new PayrollController();
    $pays = new PaymentController();
    $stat = new StatsController();
    $exp = new ExportController();
    $view = ['finance.view_all', 'finance.view_own'];     // исполнитель видит только своё — проверка в сервисах

    // Проверка работоспособности (без входа; без внутренних подробностей)
    $router->get('/api/v1/health', static function (Request $r): Response {
        try {
            Database::pdo()->query('SELECT 1');
            $db = 'ok';
        } catch (Throwable $e) {
            Logger::error('Health: БД недоступна: ' . $e->getMessage());
            $db = 'error';
        }
        return Response::ok(['app' => 'ok', 'db' => $db], $db === 'ok' ? 200 : 503);
    });

    // 12.1 Авторизация
    $router->post('/api/v1/auth/login',  [$auth, 'login']);
    $router->post('/api/v1/auth/logout', Guard::auth([$auth, 'logout']));
    $router->get('/api/v1/auth/me',      Guard::auth([$auth, 'me']));

    // 12.2 Пользователи — только администратор
    $router->get('/api/v1/users',                    Guard::auth([$users, 'index'],      'users.manage'));
    $router->post('/api/v1/users',                   Guard::auth([$users, 'store'],      'users.manage'));
    $router->get('/api/v1/users/{id}',               Guard::auth([$users, 'show'],       'users.manage'));
    $router->patch('/api/v1/users/{id}',             Guard::auth([$users, 'update'],     'users.manage'));
    $router->post('/api/v1/users/{id}/deactivate',   Guard::auth([$users, 'deactivate'], 'users.manage'));
    $router->post('/api/v1/users/{id}/activate',     Guard::auth([$users, 'activate'],   'users.manage'));

    // 12.3 Двери (номер двери создаётся автоматически; ручное создание — только администратор)
    $router->post('/api/v1/doors',                    Guard::auth([$doors, 'store'],    'doors.create'));
    $router->get('/api/v1/doors',                     Guard::auth([$doors, 'index'],    'doors.view'));
    $router->get('/api/v1/doors/number/{number}',     Guard::auth([$doors, 'byNumber'], 'doors.lookup'));
    $router->get('/api/v1/doors/{id}',                Guard::auth([$doors, 'show'],     'doors.view'));
    $router->get('/api/v1/doors/{id}/history',        Guard::auth([$doors, 'history'],  'doors.view'));

    // 12.4 Виды работ
    $router->get('/api/v1/work-types',                Guard::auth([$types, 'index'],      'work_types.view'));
    $router->post('/api/v1/work-types',               Guard::auth([$types, 'store'],      'work_types.manage'));
    $router->get('/api/v1/work-types/{id}',           Guard::auth([$types, 'show'],       'work_types.view'));
    $router->patch('/api/v1/work-types/{id}',         Guard::auth([$types, 'update'],     'work_types.manage'));
    $router->post('/api/v1/work-types/{id}/deactivate', Guard::auth([$types, 'deactivate'], 'work_types.manage'));
    $router->post('/api/v1/work-types/{id}/activate',   Guard::auth([$types, 'activate'],   'work_types.manage'));

    // 12.5 Расценки (в объёме, необходимом для регистрации работ; остальное — этап 5)
    $router->get('/api/v1/rates',                     Guard::auth([$rates, 'index'],   'rates.manage'));
    $router->post('/api/v1/rates',                    Guard::auth([$rates, 'store'],   'rates.manage'));
    $router->get('/api/v1/rates/history',             Guard::auth([$rates, 'history'], 'rates.manage'));
    $router->get('/api/v1/rates/{id}',                Guard::auth([$rates, 'show'],    'rates.manage'));
    $router->post('/api/v1/rates/{id}/close',         Guard::auth([$rates, 'close'],   'rates.manage'));
    $router->get('/api/v1/users/{id}/rates',          Guard::auth([$rates, 'forUser'], 'rates.manage'));

    // 12.6 Выполненные работы (исполнитель видит только свои — проверка в сервисе)
    $router->post('/api/v1/work-records',             Guard::auth([$works, 'store'],  'work.register'));
    $router->get('/api/v1/work-records',              Guard::auth([$works, 'index'],  ['work.view_own', 'work.view_all']));
    $router->get('/api/v1/work-records/{id}',         Guard::auth([$works, 'show'],   ['work.view_own', 'work.view_all']));
    $router->post('/api/v1/work-records/{id}/void',   Guard::auth([$works, 'void'],   'work.void'));

    $router->get('/api/v1/work-records/{id}/photos',  Guard::auth([$photos, 'forRecord'], ['photos.view', 'work.view_own']));

    // 12.7 Фотографии (файлы выдаются только через PHP после проверки прав; storage/ вне публичного каталога)
    $router->post('/api/v1/photos',                   Guard::auth([$photos, 'store'],   'work.register'));
    $router->get('/api/v1/photos/{id}',               Guard::auth([$photos, 'show'],    ['photos.view', 'work.view_own']));
    $router->delete('/api/v1/photos/{id}',            Guard::auth([$photos, 'destroy'], 'photos.delete'));

    // 12.8 Финансовый учёт (изменения — только администратор; директор — просмотр; исполнитель — своё)
    $router->get('/api/v1/finance/summary',           Guard::auth([$fin, 'summary'],  'finance.view_all'));
    $router->get('/api/v1/finance/accruals',          Guard::auth([$fin, 'accruals'], $view));
    $router->get('/api/v1/finance/balances',          Guard::auth([$fin, 'balances'], 'finance.view_all'));
    $router->get('/api/v1/finance/users/{id}',        Guard::auth([$fin, 'user'],     $view));
    $router->get('/api/v1/finance/adjustments',       Guard::auth([$fin, 'adjustments'],      $view));
    $router->post('/api/v1/finance/adjustments',      Guard::auth([$fin, 'createAdjustment'], 'finance.manage'));

    $router->post('/api/v1/payroll-runs',                 Guard::auth([$runs, 'store'],   'finance.manage'));
    $router->get('/api/v1/payroll-runs',                  Guard::auth([$runs, 'index'],   $view));
    $router->get('/api/v1/payroll-runs/{id}',             Guard::auth([$runs, 'show'],    $view));
    $router->post('/api/v1/payroll-runs/{id}/confirm',    Guard::auth([$runs, 'confirm'], 'finance.manage'));
    $router->post('/api/v1/payroll-runs/{id}/cancel',     Guard::auth([$runs, 'cancel'],  'finance.manage'));

    $router->post('/api/v1/payments',                     Guard::auth([$pays, 'store'],       'finance.manage'));
    $router->get('/api/v1/payments',                      Guard::auth([$pays, 'index'],       $view));
    $router->get('/api/v1/payments/{id}',                 Guard::auth([$pays, 'show'],        $view));
    $router->get('/api/v1/payments/{id}/allocations',     Guard::auth([$pays, 'allocations'], $view));
    $router->put('/api/v1/payments/{id}/allocations',     Guard::auth([$pays, 'replaceAllocations'], 'finance.manage'));
    $router->post('/api/v1/payments/{id}/post',           Guard::auth([$pays, 'post'],        'finance.manage'));
    $router->post('/api/v1/payments/{id}/cancel',         Guard::auth([$pays, 'cancel'],      'finance.manage'));

    // 12.9 Статистика — администратор и директор
    $router->get('/api/v1/stats/summary',     Guard::auth([$stat, 'summary'],   'stats.view'));
    $router->get('/api/v1/stats/daily',       Guard::auth([$stat, 'daily'],     'stats.view'));
    $router->get('/api/v1/stats/work-types',  Guard::auth([$stat, 'workTypes'], 'stats.view'));
    $router->get('/api/v1/stats/users',       Guard::auth([$stat, 'users'],     'stats.view'));
    $router->get('/api/v1/stats/doors',       Guard::auth([$stat, 'doors'],     'stats.view'));

    // 12.10 Экспорт. Расчётный лист доступен и исполнителю (только свой подтверждённый — проверка в сервисе)
    $router->get('/api/v1/export/pdf/production',   Guard::auth([$exp, 'production'], 'reports.export'));
    $router->get('/api/v1/export/pdf/payments',     Guard::auth([$exp, 'payments'],   'reports.export'));
    $router->get('/api/v1/export/pdf/finance',      Guard::auth([$exp, 'finance'],    'reports.export'));
    $router->get('/api/v1/export/pdf/photos',       Guard::auth([$exp, 'photos'],     'reports.export'));
    $router->get('/api/v1/export/pdf/payroll/{id}', Guard::auth([$exp, 'payslip'],    ['reports.export', 'finance.view_own']));
    $router->get('/api/v1/export/csv',              Guard::auth([$exp, 'csv'],        'reports.export'));
    $router->get('/api/v1/export/xlsx',             Guard::auth([$exp, 'xlsx'],       'reports.export'));
};
