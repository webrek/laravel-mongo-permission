<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Webrek\MongoPermission\Exceptions\GuardDoesNotMatch;
use Webrek\MongoPermission\Exceptions\RoleHierarchyCycle;
use Webrek\MongoPermission\Exceptions\RoleHierarchyTooDeep;
use Webrek\MongoPermission\Exceptions\TeamDoesNotMatch;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\AuditTestCase;

#[Group('audit')]
class ExtendedAuditTest extends AuditTestCase
{
    public function test_permission_lookup_respects_active_team(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        setPermissionsTeamId('alpha');
        Permission::create(['name' => 'invoices.pay']);
        setPermissionsTeamId('beta');
        $beta = Permission::create(['name' => 'invoices.pay']);
        $this->assertSame((string) $beta->id, (string) Permission::findByName('invoices.pay')->id);
    }

    public function test_direct_permission_can_be_granted_in_two_teams(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = $this->user('lector');
        $permission = Permission::findByName('articles.create');
        setPermissionsTeamId('alpha');
        $user->givePermissionTo($permission);
        setPermissionsTeamId('beta');
        $user->givePermissionTo($permission);
        $this->assertTrue($user->fresh()->hasDirectPermission($permission));
    }

    public function test_direct_permission_revocation_does_not_touch_other_team(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = $this->user('lector');
        $permission = Permission::findByName('articles.create');
        setPermissionsTeamId('alpha');
        $user->givePermissionTo($permission);
        setPermissionsTeamId('beta');
        $user->revokePermissionTo($permission);
        setPermissionsTeamId('alpha');
        $this->assertTrue($user->fresh()->hasDirectPermission($permission));
    }

    public function test_team_revocation_invalidates_cached_authorization(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = $this->user('lector');
        $permission = Permission::findByName('articles.create');
        setPermissionsTeamId('alpha');
        $user->givePermissionTo($permission);
        $this->assertTrue($user->hasPermissionTo($permission));
        $user->revokePermissionTo($permission);
        $this->assertFalse($user->fresh()->hasDirectPermission($permission));
        $this->assertFalse($user->fresh()->hasPermissionTo($permission));
    }

    public function test_permission_listing_agrees_with_active_team(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = $this->user('lector');
        $permission = Permission::findByName('articles.create');
        setPermissionsTeamId('alpha');
        $user->givePermissionTo($permission);
        setPermissionsTeamId('beta');
        $this->assertFalse($user->hasDirectPermission($permission));
        $this->assertNotContains('articles.create', $user->getAllPermissions()->pluck('name')->all());
    }

    public function test_expired_direct_permission_can_be_renewed(): void
    {
        $user = $this->user('lector');
        $user->givePermissionToUntil('articles.publish', now()->subMinute());
        $user->givePermissionToUntil('articles.publish', now()->addHour());
        $this->assertTrue($user->fresh()->hasDirectPermission('articles.publish'));
    }

    public function test_hierarchy_rejects_cross_guard_parent(): void
    {
        $parent = Role::create(['name' => 'api-admin', 'guard_name' => 'api']);
        $this->expectException(GuardDoesNotMatch::class);
        Role::findByName('editor')->inheritsFrom($parent);
    }

    public function test_hierarchy_cannot_grant_other_tenant_permissions(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        setPermissionsTeamId('alpha');
        $permission = Permission::create(['name' => 'tenant.secret']);
        $parent = Role::create(['name' => 'alpha-owner']);
        $parent->givePermissionTo($permission);
        setPermissionsTeamId('beta');
        $child = Role::create(['name' => 'beta-reader']);
        $this->expectException(TeamDoesNotMatch::class);
        $child->inheritsFrom($parent);
    }

    public function test_extending_existing_ancestor_respects_total_depth(): void
    {
        config(['permission.role_hierarchy_max_depth' => 2]);
        $a = Role::create(['name' => 'depth-a']);
        $b = Role::create(['name' => 'depth-b']);
        $c = Role::create(['name' => 'depth-c']);
        $d = Role::create(['name' => 'depth-d']);
        $a->inheritsFrom($b);
        $b->inheritsFrom($c);
        $this->expectException(RoleHierarchyTooDeep::class);
        $c->inheritsFrom($d);
    }

    public function test_two_loaded_users_do_not_lose_independent_grants(): void
    {
        $first = $this->user('lector');
        $second = $first->fresh();
        $first->givePermissionTo('articles.create');
        $second->givePermissionTo('articles.publish');
        $fresh = $first->fresh();
        $this->assertTrue($fresh->hasDirectPermission('articles.publish'));
        $this->assertTrue($fresh->hasDirectPermission('articles.create'));
    }

    public function test_overlapping_registrars_do_not_reuse_cache_generation(): void
    {
        $first = new PermissionRegistrar;
        $second = new PermissionRegistrar;
        $first->cacheVersion();
        $second->cacheVersion();
        $first->bumpCacheVersion();
        $afterFirst = (new PermissionRegistrar)->cacheVersion();
        $second->bumpCacheVersion();
        $afterSecond = (new PermissionRegistrar)->cacheVersion();
        $this->assertGreaterThan($afterFirst, $afterSecond);
    }

    public function test_long_lived_registrar_sees_external_role_revocation(): void
    {
        $reader = new PermissionRegistrar;
        $user = $this->user('editor');
        $this->assertContains('articles.create', $reader->getUserPermissionSlugs($user));
        Role::findByName('editor')->revokePermissionTo('articles.create');
        $this->assertNotContains('articles.create', $reader->getUserPermissionSlugs($user->fresh()));
    }

    public function test_configured_cache_store_is_used(): void
    {
        config(['cache.stores.audit_permissions' => ['driver' => 'array'], 'permission.cache.store' => 'audit_permissions']);
        $user = $this->user('editor');
        $registrar = app(PermissionRegistrar::class);
        $user->hasPermissionTo('articles.create');
        $key = $registrar->cacheKey((string) $user->id, null, 'permissions');
        $this->assertTrue(Cache::store('audit_permissions')->has($key));
    }

    public function test_configured_cache_ttl_is_respected(): void
    {
        config(['permission.cache.expiration_time' => 1]);
        $user = $this->user('editor');
        $registrar = app(PermissionRegistrar::class);
        $user->hasPermissionTo('articles.create');
        $key = $registrar->cacheKey((string) $user->id, null, 'permissions');
        $this->assertTrue(Cache::has($key));
        $this->travel(2)->seconds();
        $this->assertFalse(Cache::has($key));
    }

    public function test_cli_lists_users_with_inherited_permission(): void
    {
        $permission = Permission::create(['name' => 'inherited.read']);
        $parent = Role::create(['name' => 'base-reader']);
        $parent->givePermissionTo($permission);
        $child = Role::create(['name' => 'child-reader']);
        $child->inheritsFrom($parent);
        $user = $this->user('lector');
        $user->assignRole($child);
        $this->assertTrue($user->hasPermissionTo('inherited.read'));
        Artisan::call('permission:list-users', ['--permission' => 'inherited.read']);
        $this->assertStringContainsString($user->email, Artisan::output());
    }

    public function test_cli_lists_legacy_flat_role_assignments(): void
    {
        $user = $this->user('lector');
        $role = Role::findByName('editor');
        $user->role_ids = [(string) $role->id];
        $user->save();
        $this->assertTrue($user->fresh()->hasRole('editor'));
        Artisan::call('permission:list-users', ['role' => 'editor']);
        $this->assertStringContainsString($user->email, Artisan::output());
    }

    public function test_permission_expiry_still_denies_with_warm_cache(): void
    {
        $user = $this->user('lector');
        $user->givePermissionToUntil('articles.publish', now()->addSecond());
        $this->assertTrue($user->hasPermissionTo('articles.publish'));
        $this->travel(2)->seconds();
        $this->assertFalse($user->hasPermissionTo('articles.publish'));
    }

    public function test_cycle_detection_remains_effective(): void
    {
        $parent = Role::findByName('lector');
        $child = Role::findByName('editor');
        $child->inheritsFrom($parent);
        $this->expectException(RoleHierarchyCycle::class);
        $parent->inheritsFrom($child);
    }
}
