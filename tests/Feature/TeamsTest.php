<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class TeamsTest extends TestCase
{
    public function test_role_assignment_stores_active_team_id_in_subdoc(): void
    {
        config()->set('permission.teams', true);
        setPermissionsTeamId('teamA');

        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);

        $user->assignRole('admin');

        $stored = $user->fresh()->role_ids[0]['team_id'] ?? null;
        $this->assertSame('teamA', $stored);
    }

    public function test_has_role_filters_by_active_team(): void
    {
        config()->set('permission.teams', true);

        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);

        setPermissionsTeamId('teamA');
        $user->assignRole('admin');

        $this->assertTrue($user->fresh()->hasRole('admin'));

        setPermissionsTeamId('teamB');
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_role_with_null_team_counts_in_any_team_when_not_strict(): void
    {
        config()->set('permission.teams', true);
        config()->set('permission.strict_team_isolation', false);

        // Global role: created with no active team
        app(PermissionRegistrar::class)->forgetTeamId();
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'superadmin']);
        $user->assignRole('superadmin');   // stored with team_id = null

        setPermissionsTeamId('teamA');
        $this->assertTrue($user->fresh()->hasRole('superadmin'));

        setPermissionsTeamId('teamB');
        $this->assertTrue($user->fresh()->hasRole('superadmin'));
    }

    public function test_team_resolver_supplies_team_id_when_not_set_explicitly(): void
    {
        config()->set('permission.teams', true);
        config()->set('permission.team_resolver', fn () => 'fromResolver');
        app(PermissionRegistrar::class)->forgetTeamId();

        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);

        $user->assignRole('admin');

        $stored = $user->fresh()->role_ids[0]['team_id'] ?? null;
        $this->assertSame('fromResolver', $stored);
    }
}
