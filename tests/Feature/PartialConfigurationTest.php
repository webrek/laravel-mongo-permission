<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Exceptions\RoleHierarchyTooDeep;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class PartialConfigurationTest extends TestCase
{
    public function test_missing_team_flag_disables_scoping_even_with_a_resolved_team(): void
    {
        $config = config('permission');
        Arr::forget($config, 'teams');
        config(['permission' => $config]);
        setPermissionsTeamId('A');
        $p = Permission::create(['name' => 'p', 'team_id' => 'B']);
        $r = Role::create(['name' => 'r', 'team_id' => 'B']);
        $r->givePermissionTo($p);
        $u = TestUser::create(['name' => 'u', 'permission_ids' => [['permission_id' => (string) $p->id, 'team_id' => 'C']], 'role_ids' => [['role_id' => (string) $r->id, 'team_id' => 'C']]]);
        $this->assertTrue($u->hasPermissionTo($p));
        $this->assertTrue($u->hasDirectPermission($p));
        $this->assertTrue($u->hasRole($r));
        $this->assertCount(1, $u->roles());
        $this->assertCount(1, $u->permissions());
        $this->assertSame(1, app(PermissionRegistrar::class)->catalog(Permission::class, 'web')->count());
        $new = Permission::create(['name' => 'global']);
        $this->assertNull($new->team_id);
        $this->assertNull(Role::create(['name' => 'global'])->team_id);
    }

    public function test_missing_wildcard_flag_does_not_enable_implicit_permissions(): void
    {
        $config = config('permission');
        Arr::forget($config, 'enable_wildcard_permission');
        config(['permission' => $config, 'permission.throw_on_missing_permission' => false]);
        $p = Permission::create(['name' => 'docs.*']);
        $u = TestUser::create(['name' => 'u']);
        $u->givePermissionTo($p);
        $this->assertFalse($u->hasPermissionTo('docs.write'));
        $this->assertSame(0, Artisan::call('permission:check', ['user_id' => (string) $u->id, 'permission' => 'docs.write']));
        $this->assertStringContainsString('Wildcard matching is disabled.', Artisan::output());
    }

    public function test_missing_strict_flag_keeps_global_grants_available(): void
    {
        $config = config('permission');
        Arr::forget($config, 'strict_team_isolation');
        config(['permission' => $config, 'permission.teams' => true]);
        $p = Permission::create(['name' => 'p']);
        $r = Role::create(['name' => 'r']);
        $r->givePermissionTo($p);
        $u = TestUser::create(['name' => 'u']);
        $u->assignRole($r)->givePermissionTo($p);
        setPermissionsTeamId('A');
        $this->assertTrue($u->hasPermissionTo($p));
        $this->assertTrue($u->hasDirectPermission($p));
        $this->assertTrue($u->hasRole($r));
        $this->assertCount(1, $u->roles());
        $this->assertCount(1, $u->permissions());
    }

    public function test_missing_depth_setting_uses_five_edges_for_reads_and_writes(): void
    {
        $config = config('permission');
        Arr::forget($config, 'role_hierarchy_max_depth');
        config(['permission' => $config]);
        $roles = [];
        for ($i = 0; $i < 7; $i++) {
            $roles[] = Role::create(['name' => 'depth-'.$i]);
        }
        for ($i = 1; $i < 6; $i++) {
            $roles[$i]->inheritsFrom($roles[$i - 1]);
        }
        try {
            $roles[6]->inheritsFrom($roles[5]);
            $this->fail('Six edges exceed default limit');
        } catch (RoleHierarchyTooDeep $e) {
            $this->assertStringContainsString('5', $e->getMessage());
        }
        Role::whereKey($roles[6]->id)->update(['parent_role_ids' => [(string) $roles[5]->id]]);
        $this->assertSame(array_map(fn ($r) => (string) $r->id, array_reverse(array_slice($roles, 1, 5))), $roles[6]->fresh()->getAncestors()->pluck('id')->all());
    }
}
