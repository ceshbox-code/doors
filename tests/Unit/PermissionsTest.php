<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Permissions;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    public function testAdministratorCanOnlyManageUsersAndViewAggregateStats(): void
    {
        $this->assertTrue(Permissions::can('owner', 'users.manage'));
        $this->assertTrue(Permissions::can('owner', 'stats.view'));
        foreach ([
            'executors.view', 'work.register', 'work.view_own', 'work.view_all',
            'work_types.view', 'work_types.manage', 'rates.manage',
            'doors.lookup', 'doors.view', 'photos.view', 'photos.delete',
            'finance.view_own', 'finance.view_all', 'finance.manage',
            'stats.view_details', 'reports.export', 'work.void',
        ] as $permission) {
            $this->assertFalse(Permissions::can('owner', $permission), $permission);
        }
    }

    public function testMasterCanRegisterAndViewOnlyOwnScope(): void
    {
        foreach (['work.register', 'work.view_own', 'work_types.view', 'doors.lookup', 'finance.view_own'] as $permission) {
            $this->assertTrue(Permissions::can('master', $permission), $permission);
        }
        $this->assertTrue(Permissions::can('master', 'stats.view'));

        foreach ([
            'users.manage', 'executors.view', 'rates.manage', 'finance.manage',
            'finance.view_all', 'work.view_all', 'work.void',
            'stats.view_details', 'reports.export', 'photos.view', 'photos.delete',
        ] as $permission) {
            $this->assertFalse(Permissions::can('master', $permission), $permission);
        }
    }

    public function testDirectorHasProductionAndFinancialControlButNoUserAdministration(): void
    {
        foreach ([
            'executors.view', 'work.view_all', 'work_types.view', 'work_types.view_all',
            'work_types.manage', 'rates.manage', 'doors.view', 'doors.lookup',
            'photos.view', 'photos.delete', 'finance.view_all', 'finance.manage',
            'stats.view', 'stats.view_details', 'reports.export', 'work.void',
        ] as $permission) {
            $this->assertTrue(Permissions::can('director', $permission), $permission);
        }
        foreach (['users.manage', 'work.register', 'work.register_for_others', 'finance.view_own'] as $permission) {
            $this->assertFalse(Permissions::can('director', $permission), $permission);
        }
    }

    public function testUnknownRoleHasNoPermissions(): void
    {
        $this->assertSame([], Permissions::forRole('hacker'));
    }

    public function testMatrixOnlyUsesKnownPermissions(): void
    {
        foreach (['owner', 'master', 'director'] as $role) {
            foreach (Permissions::forRole($role) as $permission) {
                $this->assertContains($permission, Permissions::ALL, "{$role}: {$permission}");
            }
        }
    }
}
