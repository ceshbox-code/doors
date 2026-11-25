<?php
declare(strict_types=1);

namespace App\Core;

/** Серверная матрица прав. Владение собственными записями дополнительно проверяется сервисами. */
final class Permissions
{
    public const ALL = [
        'users.manage', 'executors.view',
        'work_types.manage', 'work_types.view', 'work_types.view_all', 'rates.manage',
        'work.register', 'work.register_for_others', 'work.view_own', 'work.view_all', 'work.void',
        'doors.lookup', 'doors.view', 'doors.create',
        'photos.view', 'photos.delete',
        'finance.view_own', 'finance.view_all', 'finance.manage',
        'stats.view', 'stats.view_details', 'reports.export', 'reports.export_own', 'audit.view', 'settings.manage',
    ];

    private const MATRIX = [
        'owner' => ['users.manage', 'stats.view'],
        'master' => ['work.register', 'work.view_own', 'work_types.view', 'doors.lookup', 'finance.view_own', 'stats.view', 'reports.export_own'],
        'director' => [
            'executors.view', 'work.view_all',
            'work_types.view', 'work_types.view_all', 'work_types.manage', 'rates.manage',
            'doors.view', 'doors.lookup', 'photos.view', 'photos.delete',
            'finance.view_all', 'finance.manage',
            'stats.view', 'stats.view_details', 'reports.export', 'work.void',
        ],
    ];

    /** @return string[] */
    public static function forRole(string $role): array
    {
        return self::MATRIX[$role] ?? [];
    }

    public static function can(string $role, string $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }
}
