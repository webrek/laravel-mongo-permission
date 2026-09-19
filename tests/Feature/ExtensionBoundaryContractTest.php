<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use MongoDB\BSON\ObjectId;
use Webrek\MongoPermission\Events\PermissionAttached;
use Webrek\MongoPermission\Events\PermissionDetached;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class NativePermission extends Permission
{
    public function getKey()
    {
        return new ObjectId(parent::getKey());
    }
}
class NativeRole extends Role
{
    public function getKey()
    {
        return new ObjectId(parent::getKey());
    }
}
class ResolvingRole extends Role
{
    public function permissionIdsFor(array $entries): array
    {
        return $this->resolvePermissionIds($entries);
    }
}
class BoundaryUser extends TestUser
{
    public function permissionIdsFor(array $entries): array
    {
        return $this->resolvePermissionIds($entries);
    }

    public function modelsFor(array $entries, string $kind, ?string $guard = null): array
    {
        return $this->resolveGrantModels($entries, $kind, $guard);
    }

    public function currentTeam(): ?string
    {
        return $this->activeTeamId();
    }
}

class ExtensionBoundaryContractTest extends TestCase
{
    public function test_resolution_hooks_return_dense_unique_role_permissions_and_string_user_ids(): void
    {
        config(['permission.models.permission' => NativePermission::class]);
        $a = NativePermission::create(['name' => 'a']);
        $b = NativePermission::create(['name' => 'b']);
        $role = new ResolvingRole(['guard_name' => 'web']);
        $this->assertSame([(string) $a->getKey(), (string) $b->getKey()], $role->permissionIdsFor([$a, 'a', $b]));
        $u = new BoundaryUser;
        $this->assertSame([(string) $a->getKey(), (string) $b->getKey()], $u->permissionIdsFor([$a, 'b']));
        $this->assertSame([$a, $b], $u->modelsFor([$a, $a, $b], 'permission'));
        $api = NativePermission::create(['name' => 'api', 'guard_name' => 'api']);
        $this->assertSame([$api], $u->modelsFor([$api], 'permission', 'api'));
    }

    public function test_native_permission_events_distinguish_attach_from_detach(): void
    {
        config(['permission.models.permission' => NativePermission::class]);
        $p = NativePermission::create(['name' => 'p']);
        $r = Role::create(['name' => 'r']);
        Event::fake([PermissionAttached::class, PermissionDetached::class]);
        $r->givePermissionTo($p);
        Event::assertDispatchedTimes(PermissionAttached::class, 1);
        Event::assertNotDispatched(PermissionDetached::class);
        $r->revokePermissionTo($p);
        Event::assertDispatchedTimes(PermissionAttached::class, 1);
        Event::assertDispatchedTimes(PermissionDetached::class, 1);
    }

    public function test_missing_team_flag_makes_writes_global_and_sync_replaces_all_old_scopes(): void
    {
        $config = config('permission');
        Arr::forget($config, 'teams');
        config(['permission' => $config]);
        setPermissionsTeamId('A');
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b']);
        $u = BoundaryUser::create(['name' => 'u', 'role_ids' => [['role_id' => $a->id, 'team_id' => 'B']]]);
        $this->assertNull($u->currentTeam());
        $u->syncRoles($b);
        $entries = $u->fresh()->role_ids;
        $this->assertCount(1, $entries);
        $this->assertSame($b->id, $entries[0]['role_id']);
        $this->assertNull($entries[0]['team_id']);
    }

    public function test_a_mismatched_first_role_does_not_hide_a_later_valid_alternative(): void
    {
        $foreign = Role::create(['name' => 'foreign', 'guard_name' => 'api']);
        $owned = Role::create(['name' => 'owned']);
        $u = TestUser::create(['name' => 'u']);
        $u->assignRole($owned);
        $this->assertTrue($u->hasRole([$foreign, $owned]));
    }

    public function test_reverse_relations_find_string_and_native_bson_references(): void
    {
        config(['permission.models.permission' => NativePermission::class, 'permission.models.role' => NativeRole::class]);
        $p = NativePermission::create(['name' => 'p']);
        $a = NativeRole::create(['name' => 'a', 'permission_ids' => [(string) $p->getKey()]]);
        $b = NativeRole::create(['name' => 'b', 'permission_ids' => [$p->getKey()]]);
        $flat = TestUser::create(['name' => 'flat', 'role_ids' => [(string) $a->getKey()]]);
        $structured = TestUser::create(['name' => 'structured', 'role_ids' => [['role_id' => $a->getKey()]]]);
        $this->assertEqualsCanonicalizing(['a', 'b'], $p->roles()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['flat', 'structured'], $a->users()->pluck('name')->all());
    }
}
