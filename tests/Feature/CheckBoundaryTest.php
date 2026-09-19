<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CheckBoundaryTest extends TestCase
{
    private function trace(TestUser $user, string $permission, array $options = []): string
    {
        $this->assertSame(0, Artisan::call('permission:check', ['user_id' => (string) $user->id, 'permission' => $permission] + $options));

        return Artisan::output();
    }

    public function test_legacy_flat_and_inherited_permissions_are_traced(): void
    {
        $p = Permission::create(['name' => 'read']);
        $user = TestUser::create(['name' => 'Reader', 'permission_ids' => [(string) $p->id]]);
        $this->assertStringContainsString('YES', $this->trace($user, 'read'));
        $parent = Role::create(['name' => 'parent']);
        $parent->givePermissionTo($p);
        $child = Role::create(['name' => 'child']);
        $child->inheritsFrom($parent);
        $user->permission_ids = [];
        $user->role_ids = [(string) $child->id];
        $user->save();
        $out = $this->trace($user, 'read');
        $this->assertStringContainsString('YES', $out);
        $this->assertStringContainsString('via role "child"', $out);
    }

    public function test_current_team_and_explicit_null_are_distinct(): void
    {
        $p = Permission::create(['name' => 'read']);
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = TestUser::create(['name' => 'Reader']);
        setPermissionsTeamId('alpha');
        $user->givePermissionTo($p);
        setPermissionsTeamId('beta');
        $this->assertStringContainsString('NO', $this->trace($user, 'read'));
        $this->assertStringContainsString('YES', $this->trace($user, 'read', ['--team' => 'alpha']));
        $this->assertStringContainsString('NO', $this->trace($user, 'read', ['--team' => 'null']));
    }

    public function test_non_strict_global_grant_is_available_in_explicit_team(): void
    {
        $p = Permission::create(['name' => 'read']);
        $user = TestUser::create(['name' => 'Reader']);
        $user->givePermissionTo($p);
        config(['permission.teams' => true, 'permission.strict_team_isolation' => false]);
        $this->assertStringContainsString('YES', $this->trace($user, 'read', ['--team' => 'alpha']));
    }

    public function test_unknown_permission_wildcard_search_respects_team_and_expiry(): void
    {
        $this->freezeTime();
        $p = Permission::create(['name' => 'posts.*']);
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = TestUser::create(['name' => 'Reader']);
        setPermissionsTeamId('alpha');
        $user->givePermissionToUntil($p, now()->addMinute());
        $this->assertStringNotContainsString('[ok]', $this->trace($user, 'posts.edit', ['--team' => 'beta']));
        $this->assertStringContainsString('[ok]', $this->trace($user, 'posts.edit', ['--team' => 'alpha']));
        $this->travel(1)->minutes();
        $this->assertStringNotContainsString('[ok]', $this->trace($user, 'posts.edit', ['--team' => 'alpha']));
    }

    public function test_same_name_catalogs_select_the_requested_team(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        setPermissionsTeamId('alpha');
        Permission::create(['name' => 'read']);
        setPermissionsTeamId('beta');
        $p = Permission::create(['name' => 'read']);
        $user = TestUser::create(['name' => 'Reader']);
        $user->givePermissionTo($p);
        $this->assertStringContainsString('YES', $this->trace($user, 'read', ['--team' => 'beta']));
        $this->assertStringContainsString('NO', $this->trace($user, 'read', ['--team' => 'alpha']));
    }
}
