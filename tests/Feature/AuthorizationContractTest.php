<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Carbon;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Support\Expiry;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class AuthorizationContractTest extends TestCase
{
    public function test_invalid_and_expired_entries_do_not_hide_later_valid_grants(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01'));
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        setPermissionsTeamId('A');
        $expired = Permission::create(['name' => 'expired']);
        $direct = Permission::create(['name' => 'direct']);
        $inherited = Permission::create(['name' => 'inherited']);
        $role = Role::create(['name' => 'valid']);
        $role->givePermissionTo($inherited);
        $user = TestUser::create(['name' => 'ordered', 'permission_ids' => [
            ['permission_id' => (string) $direct->id, 'team_id' => 'B'],
            ['permission_id' => (string) $expired->id, 'team_id' => 'A', 'expires_at' => now()->subSecond()],
            ['permission_id' => 'missing', 'team_id' => 'A'],
            ['permission_id' => (string) $direct->id, 'team_id' => 'A'],
        ], 'role_ids' => [
            ['role_id' => (string) $role->id, 'team_id' => 'B'],
            ['role_id' => 'missing', 'team_id' => 'A'],
            ['role_id' => (string) $role->id, 'team_id' => 'A'],
        ]]);
        $this->assertFalse($user->hasPermissionTo($expired));
        $this->assertTrue($user->hasPermissionTo($direct));
        $this->assertTrue($user->hasPermissionTo('direct'));
        $this->assertTrue($user->hasPermissionTo($inherited));
        $this->assertTrue($user->hasRole($role));
        $this->assertSame(['inherited'], $user->getPermissionsViaRoles()->pluck('name')->all());
    }

    public function test_explicit_guard_is_preserved_across_role_names_models_and_cache_entries(): void
    {
        $web = Role::create(['name' => 'web-only', 'guard_name' => 'web']);
        $api = Role::create(['name' => 'api-only', 'guard_name' => 'api']);
        $user = TestUser::create(['name' => 'guards', 'role_ids' => [(string) $web->id, (string) $api->id]]);
        foreach ([$api, 'api-only'] as $role) {
            $this->assertTrue($user->hasRole($role, 'api'));
            $this->assertFalse($user->hasRole($role, 'web'));
            $this->assertTrue($user->hasAllRoles($role, 'api'));
            $this->assertFalse($user->hasAllRoles($role, 'web'));
        }
        $this->assertTrue($user->hasRole('web-only'));
        $r = app(PermissionRegistrar::class);
        $this->assertNotSame($r->cacheKey((string) $user->id, null, 'roles', 'web'), $r->cacheKey((string) $user->id, null, 'roles', 'api'));
        $this->assertSame([(string) $api->id], $r->getUserRoleIds($user, 'api'));
    }

    public function test_role_alternatives_and_all_roles_have_distinct_semantics(): void
    {
        $missing = Role::create(['name' => 'unassigned']);
        $owned = Role::create(['name' => 'owned']);
        $user = TestUser::create(['name' => 'roles']);
        $user->assignRole($owned);
        $this->assertTrue($user->hasRole([$missing, $owned]));
        $this->assertTrue($user->hasAnyRole([$missing], [$owned]));
        $this->assertFalse($user->hasAllRoles([$owned, $missing]));
        $this->assertFalse($user->hasAllRoles('unassigned'));
        $this->assertTrue($user->hasAllRoles('owned'));
        $this->assertTrue($user->hasAllRoles([]));
    }

    public function test_multiple_input_arrays_preserve_all_permissions_and_roles(): void
    {
        $a = Permission::create(['name' => 'a']);
        $b = Permission::create(['name' => 'b']);
        $r = Role::create(['name' => 'r']);
        $s = Role::create(['name' => 's']);
        $u = TestUser::create(['name' => 'arrays']);
        $u->givePermissionTo([$a], [$b]);
        $u->assignRole([$r], [$s]);
        $this->assertTrue($u->hasAllPermissions([$a], [$b]));
        $this->assertCount(2, $u->permissions());
        $this->assertCount(2, $u->roles());
        $r->givePermissionTo([$a], [$b]);
        $this->assertSame([(string) $a->id, (string) $b->id], $r->permission_ids);
    }

    public function test_non_strict_direct_checks_accept_global_and_current_but_not_other_team(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => false]);
        setPermissionsTeamId(null);
        $p = Permission::create(['name' => 'read']);
        foreach ([null, 'A', 'B'] as $team) {
            $u = TestUser::create(['name' => 'scope', 'permission_ids' => [['permission_id' => (string) $p->id, 'team_id' => $team]]]);
            setPermissionsTeamId('A');
            $this->assertSame($team !== 'B', $u->hasDirectPermission($p));
            $this->assertSame($team !== 'B', $u->hasPermissionTo($p));
        }
    }

    public function test_reassigning_live_grants_can_extend_or_shorten_expiry_without_duplicate_events(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01'));
        $u = TestUser::create(['name' => 'expiry']);
        $p = Permission::create(['name' => 'temporary']);
        $r = Role::create(['name' => 'temporary']);
        foreach (['permission' => $p, 'role' => $r] as $kind => $model) {
            $method = $kind === 'role' ? 'assignRoleUntil' : 'givePermissionToUntil';
            $u->$method($model, now()->addHour());
            $u->$method($model, now()->addHours(2));
            $field = $kind.'_ids';
            $this->assertCount(1, $u->fresh()->$field);
            $this->assertSame(now()->addHours(2)->timestamp, Expiry::toDateTime($u->fresh()->$field[0]['expires_at'])->getTimestamp());
            $u->$method($model, now()->addMinute());
            $this->travel(2)->minutes();
            $this->assertFalse($kind === 'role' ? $u->hasRole($model) : $u->hasPermissionTo($model));
        }
    }
}
