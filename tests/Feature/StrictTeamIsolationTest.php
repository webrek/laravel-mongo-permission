<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class StrictTeamIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('permission.teams', true);
        config()->set('permission.strict_team_isolation', true);
    }

    public function test_global_role_does_not_apply_in_any_team(): void
    {
        app(PermissionRegistrar::class)->forgetTeamId();
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'superadmin']);
        $user->assignRole('superadmin');  // team_id = null

        setPermissionsTeamId('teamA');
        $this->assertFalse($user->fresh()->hasRole('superadmin'));

        setPermissionsTeamId('teamB');
        $this->assertFalse($user->fresh()->hasRole('superadmin'));
    }

    public function test_team_a_role_only_applies_in_team_a(): void
    {
        setPermissionsTeamId('teamA');
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'editor']);
        $user->assignRole('editor');

        $this->assertTrue($user->fresh()->hasRole('editor'));

        setPermissionsTeamId('teamB');
        $this->assertFalse($user->fresh()->hasRole('editor'));
    }

    public function test_direct_permission_in_team_a_does_not_apply_in_team_b(): void
    {
        setPermissionsTeamId('teamA');
        $user = TestUser::create(['name' => 'V']);
        Permission::create(['name' => 'edit']);
        $user->givePermissionTo('edit');

        $this->assertTrue($user->fresh()->hasDirectPermission('edit'));

        setPermissionsTeamId('teamB');
        $this->assertFalse($user->fresh()->hasDirectPermission('edit'));
    }
}
