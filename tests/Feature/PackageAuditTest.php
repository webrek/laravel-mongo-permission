<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Webrek\MongoPermission\Exceptions\GuardDoesNotMatch;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\AuditTestCase;

#[Group('audit')]
class PackageAuditTest extends AuditTestCase
{
    public function test_role_sync_revokes_warm_permission(): void
    {
        $user = $this->user('editor');
        $this->assertTrue($user->hasPermissionTo('articles.create'));
        Role::findByName('editor')->syncPermissions(['articles.view']);
        $this->assertFalse($user->fresh()->hasPermissionTo('articles.create'));
    }

    public function test_role_revoke_invalidates_warm_cache(): void
    {
        $user = $this->user('editor');
        $this->assertTrue($user->hasPermissionTo('articles.create'));
        Role::findByName('editor')->revokePermissionTo('articles.create');
        $this->assertFalse($user->fresh()->hasPermissionTo('articles.create'));
    }

    public function test_role_delete_invalidates_warm_cache(): void
    {
        $user = $this->user('editor');
        $this->assertTrue($user->hasPermissionTo('articles.create'));
        Role::findByName('editor')->delete();
        $this->assertFalse($user->fresh()->hasPermissionTo('articles.create'));
    }

    public function test_role_name_resolves_in_active_team(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        setPermissionsTeamId('alpha');
        Role::create(['name' => 'reviewer']);
        setPermissionsTeamId('beta');
        $beta = Role::create(['name' => 'reviewer']);
        $this->assertSame((string) $beta->id, (string) Role::findByName('reviewer')->id);
    }

    public function test_same_role_can_be_granted_in_two_teams(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = $this->user('lector');
        $role = Role::findByName('editor');
        setPermissionsTeamId('alpha');
        $user->assignRole($role);
        setPermissionsTeamId('beta');
        $user->assignRole($role);
        $this->assertTrue($user->fresh()->hasRole($role));
    }

    public function test_removing_in_another_team_preserves_original_grant(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = $this->user('lector');
        $role = Role::findByName('editor');
        setPermissionsTeamId('alpha');
        $user->assignRole($role);
        setPermissionsTeamId('beta');
        $user->removeRole($role);
        setPermissionsTeamId('alpha');
        $this->assertTrue($user->fresh()->hasRole($role));
    }

    public function test_cache_reset_preserves_unrelated_application_data(): void
    {
        Cache::put('unrelated-application-data', 'keep', 600);
        $this->artisan('permission:cache-reset')->assertExitCode(0);
        $this->assertSame('keep', Cache::get('unrelated-application-data'));
    }

    public function test_missing_permission_can_return_false_when_configured(): void
    {
        config(['permission.throw_on_missing_permission' => false]);
        $this->assertFalse($this->user('lector')->hasPermissionTo('not.registered'));
    }

    public function test_expired_role_can_be_renewed(): void
    {
        $user = $this->user('lector');
        $user->assignRoleUntil('editor', now()->subMinute());
        $this->assertFalse($user->hasRole('editor'));
        $user->assignRoleUntil('editor', now()->addHour());
        $this->assertTrue($user->fresh()->hasRole('editor'));
    }

    public function test_role_rejects_permission_from_other_guard(): void
    {
        $permission = Permission::create(['name' => 'private.api', 'guard_name' => 'api']);
        $this->expectException(GuardDoesNotMatch::class);
        Role::findByName('editor')->givePermissionTo($permission);
    }
}
