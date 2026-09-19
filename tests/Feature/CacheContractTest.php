<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Webrek\MongoPermission\Events\PermissionAttached;
use Webrek\MongoPermission\Events\PermissionDetached;
use Webrek\MongoPermission\Events\RoleAttached;
use Webrek\MongoPermission\Events\RoleDetached;
use Webrek\MongoPermission\Events\RoleParentChanged;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CacheContractTest extends TestCase
{
    public function test_flush_invalidates_another_readers_cache_and_preserves_unrelated_data(): void
    {
        $p = Permission::create(['name' => 'read']);
        $u = TestUser::create(['name' => 'reader']);
        $u->givePermissionTo($p);
        $reader = new PermissionRegistrar;
        $writer = new PermissionRegistrar;
        $this->assertSame(['read'], $reader->getUserPermissionSlugs($u));
        TestUser::whereKey($u->id)->update(['permission_ids' => []]);
        Cache::put('unrelated', 'keep');
        $writer->flush();
        $this->assertSame([], $reader->getUserPermissionSlugs($u));
        $this->assertSame('keep', Cache::get('unrelated'));
        $this->assertSame(Cache::store(), $writer->store());
    }

    public function test_cache_generations_remain_stable_on_read_and_increment_exactly_on_changes(): void
    {
        $a = new PermissionRegistrar;
        $b = new PermissionRegistrar;
        $before = $a->cacheVersion();
        $this->assertSame($before, $b->cacheVersion());
        $a->bumpCacheVersion();
        $this->assertSame($before + 1, $b->cacheVersion());
        $a->bumpCacheVersion();
        $this->assertSame($before + 2, $b->cacheVersion());
    }

    public function test_catalog_filters_guard_and_team_and_prefers_local_over_global(): void
    {
        $global = Permission::create(['name' => 'same']);
        config(['permission.teams' => true]);
        setPermissionsTeamId('A');
        $local = Permission::create(['name' => 'same']);
        Permission::create(['name' => 'foreign', 'team_id' => 'B']);
        Permission::create(['name' => 'api', 'guard_name' => 'api']);
        $r = new PermissionRegistrar;
        $this->assertSame([(string) $local->id, (string) $global->id], $r->catalog(Permission::class, 'web')->modelKeys());
        setPermissionsTeamId('B');
        $this->assertSame(['foreign', 'same'], $r->catalog(Permission::class, 'web')->pluck('name')->all());
        config(['permission.teams' => false]);
        $this->assertCount(3, $r->catalog(Permission::class, 'web'));
    }

    public function test_event_driven_imports_invalidate_grants_without_model_save_events(): void
    {
        $u = TestUser::create(['name' => 'events']);
        $p = Permission::create(['name' => 'p']);
        $r = Role::create(['name' => 'r']);
        $r->givePermissionTo($p);
        $reader = new PermissionRegistrar;
        $this->assertSame([], $reader->getUserPermissionSlugs($u));
        TestUser::whereKey($u->id)->update(['role_ids' => [(string) $r->id]]);
        event(new RoleAttached($u, $r, null, 'web'));
        $this->assertSame(['p'], $reader->getUserPermissionSlugs($u));
        TestUser::whereKey($u->id)->update(['role_ids' => []]);
        event(new RoleDetached($u, $r, null, 'web'));
        $this->assertSame([], $reader->getUserPermissionSlugs($u));
        TestUser::whereKey($u->id)->update(['permission_ids' => [(string) $p->id]]);
        event(new PermissionAttached($u, $p, null, 'web'));
        $this->assertSame(['p'], $reader->getUserPermissionSlugs($u));
        TestUser::whereKey($u->id)->update(['permission_ids' => []]);
        event(new PermissionDetached($u, $p, null, 'web'));
        $this->assertSame([], $reader->getUserPermissionSlugs($u));
    }

    public function test_parent_change_event_invalidates_descendant_access_after_external_write(): void
    {
        $p = Permission::create(['name' => 'p']);
        $parent = Role::create(['name' => 'parent']);
        $parent->givePermissionTo($p);
        $child = Role::create(['name' => 'child']);
        $u = TestUser::create(['name' => 'user']);
        $u->assignRole($child);
        $reader = new PermissionRegistrar;
        $this->assertSame([], $reader->getUserPermissionSlugs($u));
        Role::whereKey($child->id)->update(['parent_role_ids' => [(string) $parent->id]]);
        event(new RoleParentChanged($child, $parent, 'attached'));
        $this->assertSame(['p'], $reader->getUserPermissionSlugs($u));
    }

    public function test_named_locks_do_not_block_unrelated_names_or_namespaces(): void
    {
        $r = new PermissionRegistrar;
        $this->assertSame('different', $r->withLock('one', fn () => $r->withLock('two', fn () => 'different')));
        config(['permission.cache.key' => 'namespace-one']);
        $result = $r->withLock('one', function () use ($r) {
            config(['permission.cache.key' => 'namespace-two']);

            return $r->withLock('one', fn () => 'namespace');
        });
        $this->assertSame('namespace', $result);
    }
}
