<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Webrek\MongoPermission\Events\PermissionAttached;
use Webrek\MongoPermission\Events\RoleAttached;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\AuditTestCase;

class GrantSafetyTest extends AuditTestCase
{
    public function test_sync_preserves_other_team_and_global_grants(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = $this->user('lector');
        setPermissionsTeamId('alpha');
        $user->givePermissionTo('articles.create');
        $user->assignRole('editor');
        setPermissionsTeamId('beta');
        $user->givePermissionTo('articles.publish');
        $user->assignRole('admin');
        $user->syncPermissions([]);
        $user->syncRoles([]);
        $this->assertFalse($user->hasPermissionTo('articles.publish'));
        setPermissionsTeamId('alpha');
        $this->assertTrue($user->hasPermissionTo('articles.create'));
        $this->assertTrue($user->hasRole('editor'));
        setPermissionsTeamId(null);
        $this->assertTrue($user->hasRole('lector'));
    }

    public function test_legacy_invalid_hierarchy_cannot_leak_permissions(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        setPermissionsTeamId('alpha');
        $perm = Permission::create(['name' => 'private.alpha']);
        $parent = Role::create(['name' => 'owner-alpha']);
        $parent->givePermissionTo($perm);
        setPermissionsTeamId('beta');
        $child = Role::create(['name' => 'reader-beta', 'parent_role_ids' => [(string) $parent->id]]);
        $user = $this->user('lector');
        $user->assignRole($child);
        $this->assertFalse($user->hasPermissionTo($perm));
        $this->assertFalse($user->getAllPermissions()->contains('name', 'private.alpha'));
    }

    public function test_two_stale_roles_keep_independent_permission_updates(): void
    {
        $one = Role::findByName('lector');
        $two = $one->fresh();
        $one->givePermissionTo('articles.create');
        $two->givePermissionTo('articles.publish');
        $this->assertTrue($one->fresh()->hasPermissionTo('articles.create'));
        $this->assertTrue($one->fresh()->hasPermissionTo('articles.publish'));
    }

    public function test_role_mutation_reaches_other_live_user_instance(): void
    {
        $one = $this->user('editor');
        $two = $one->fresh();
        $this->assertTrue($one->hasRole('editor'));
        $two->removeRole('editor');
        $this->assertFalse($one->hasRole('editor'));
    }

    public function test_null_team_and_literal_null_team_have_distinct_cache(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = $this->user('lector');
        $user->givePermissionTo('articles.create');
        $this->assertTrue($user->hasPermissionTo('articles.create'));
        setPermissionsTeamId('null');
        $this->assertFalse($user->hasPermissionTo('articles.create'));
    }

    public function test_sync_only_affects_current_guard(): void
    {
        $user = $this->user('lector');
        $api = Permission::create(['name' => 'api.read', 'guard_name' => 'api']);
        config(['auth.defaults.guard' => 'api']);
        $user->givePermissionTo($api);
        config(['auth.defaults.guard' => 'web']);
        $user->givePermissionTo('articles.create');
        $user->syncPermissions([]);
        config(['auth.defaults.guard' => 'api']);
        $this->assertTrue($user->fresh()->hasPermissionTo('api.read'));
    }

    public function test_old_cache_entries_expire_without_deleting_application_cache(): void
    {
        config(['permission.cache.expiration_time' => 1]);
        $reg = app(PermissionRegistrar::class);
        $user = $this->user('editor');
        $user->hasPermissionTo('articles.create');
        $key = $reg->cacheKey((string) $user->id, null, 'permissions');
        Cache::put('unrelated', 'preserved', 60);
        Role::findByName('editor')->save();
        $this->advanceCacheClock();
        $this->assertFalse(Cache::has($key));
        $this->assertSame('preserved', Cache::get('unrelated'));
    }

    public function test_role_model_check_uses_identity_not_just_name(): void
    {
        config(['permission.teams' => true]);
        $global = Role::create(['name' => 'same-name']);
        setPermissionsTeamId('alpha');
        $local = Role::create(['name' => 'same-name']);
        $user = $this->user('lector');
        $user->assignRole($global);
        $this->assertTrue($user->hasRole($global));
        $this->assertFalse($user->hasRole($local));
    }

    public function test_permission_model_check_rejects_another_guard_with_same_name(): void
    {
        $api = Permission::create(['name' => 'articles.view', 'guard_name' => 'api']);
        $this->assertFalse($this->user('lector')->hasPermissionTo($api));
    }

    public function test_repeating_the_same_expiry_is_idempotent(): void
    {
        $user = $this->user('lector');
        $until = now()->addHour();
        $user->assignRoleUntil('editor', $until);
        $user->givePermissionToUntil('articles.publish', $until);
        Event::fake([RoleAttached::class, PermissionAttached::class]);
        $user->assignRoleUntil('editor', $until);
        $user->givePermissionToUntil('articles.publish', $until);
        $this->assertTrue($user->hasPermissionTo('articles.publish'));
        Event::assertNotDispatched(RoleAttached::class);
        Event::assertNotDispatched(PermissionAttached::class);
    }
}
