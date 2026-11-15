<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Csrf;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\AuthService;

/** Проверка входа, CSRF и права на каждом запросе (ТЗ 9.4). Скрытие кнопок защитой не считается. */
final class Guard
{
    /** @param string|string[]|null $permission одно право или список (достаточно любого из них) */
    public static function auth(callable $handler, $permission = null): callable
    {
        return static function (Request $request) use ($handler, $permission): Response {
            $user = (new AuthService())->currentUser();
            if ($user === null) {
                throw new ApiException('UNAUTHENTICATED', 'Требуется вход в систему', 401);
            }
            if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                Csrf::verify($request);
            }
            $needed = $permission === null ? [] : (array) $permission;
            $allowed = $needed === [];
            foreach ($needed as $p) {
                if (Permissions::can($user['role'], $p)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                (new AuditService())->log(
                    (int) $user['id'], 'access.denied', 'route', null, null,
                    ['method' => $request->method, 'path' => $request->path, 'permission' => implode(',', $needed)],
                    null, $request->ip
                );
                throw new ApiException('FORBIDDEN', 'Недостаточно прав для этого действия', 403);
            }
            return $handler($request->withUser($user));
        };
    }
}
