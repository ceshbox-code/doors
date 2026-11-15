<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Матрица прав по ролям (ТЗ, раздел 9). Единственный источник истины:
 * проверка выполняется на сервере в Guard при каждом запросе.
 * Права «_own» дополнительно требуют проверки владельца записи в самом сервисе.
 */
final class Permissions
{
    public const ALL = [
        'users.manage', 'work_types.manage', 'work_types.view', 'work_types.view_all', 'rates.manage',
        'work.register', 'work.register_for_others', 'work.view_own', 'work.view_all', 'work.void',
        'doors.lookup', 'doors.view', 'doors.create',
        'photos.view', 'photos.delete',
        'finance.view_own', 'finance.view_all', 'finance.manage',
        'stats.view', 'reports.export', 'audit.view', 'settings.manage',
    ];

    private const MATRIX = [
        'master' => [
            'work.register', 'work.view_own', 'work_types.view', 'doors.lookup', 'finance.view_own',
        ],
        'director' => [
            'work.view_all', 'work_types.view', 'work_types.view_all', 'doors.view', 'doors.lookup', 'photos.view',
            'finance.view_all', 'stats.view', 'reports.export',
        ],
    ];

    /** @return string[] */
    public static function forRole(string $role): array
    {
        if ($role === 'owner') {
            return self::ALL;
        }
        return self::MATRIX[$role] ?? [];
    }

    public static function can(string $role, string $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }
}
