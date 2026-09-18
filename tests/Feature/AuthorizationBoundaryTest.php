<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Webrek\MongoPermission\Events\PermissionAttached;
use Webrek\MongoPermission\Events\PermissionDetached;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Support\TeamScope;
use Webrek\MongoPermission\Tests\AuditTestCase;

class AuthorizationBoundaryTest extends AuditTestCase
{
    public function test_role_identity_expires_at_exact_boundary_without_losing_permanent_roles(): void
    {
        $this->freezeTime();
        $user = $this->user('lector');
        $role = Role::findByName('editor');
        $user->assignRoleUntil($role, now()->addMinute());
        $reader = app(PermissionRegistrar::class);
        $this->assertTrue($user->hasRole($role));
        $this->assertContains((string) $role->id, $reader->getUserRoleIds($user));
        $this->travel(59)->seconds();
        $this->assertTrue($user->hasRole($role));
        $this->travel(1)->seconds();
        $this->assertFalse($user->hasRole($role));
        $this->assertNotContains((string) $role->id, $reader->getUserRoleIds($user));
        $this->assertTrue($user->hasRole(Role::findByName('lector')));
        $this->assertFalse($user->hasPermissionTo('articles.create'));
    }

    public function test_removing_first_or_middle_direct_grant_preserves_later_grants(): void
    {
        $user = $this->user('lector');
        $user->givePermissionTo(['articles.create', 'articles.publish', 'articles.delete']);
        $user->revokePermissionTo('articles.create');
        $this->assertSame(['articles.publish', 'articles.delete'], $user->getPermissionNames()->all());
        $user->givePermissionTo('articles.create');
        $user->revokePermissionTo('articles.delete');
        $this->assertEqualsCanonicalizing(['articles.publish', 'articles.create'], $user->fresh()->getPermissionNames()->all());
        $this->assertTrue($user->hasPermissionTo('articles.create'));
        $this->assertFalse($user->hasPermissionTo('articles.delete'));
    }

    public function test_sync_keeps_later_requested_grants_and_emits_every_change(): void
    {
        $user = $this->user('lector');
        $user->givePermissionTo(['articles.create', 'articles.publish', 'articles.delete']);
        Event::fake([PermissionAttached::class, PermissionDetached::class]);
        $user->syncPermissions('articles.publish', 'articles.delete', 'users.manage');
        $this->assertEqualsCanonicalizing(['articles.publish', 'articles.delete', 'users.manage'], $user->getPermissionNames()->all());
        Event::assertDispatched(PermissionDetached::class, fn ($e) => $e->permission->name === 'articles.create');
        Event::assertDispatched(PermissionAttached::class, fn ($e) => $e->permission->name === 'users.manage');
        Event::assertDispatchedTimes(PermissionDetached::class, 1);
        Event::assertDispatchedTimes(PermissionAttached::class, 1);
    }

    public function test_removing_first_role_preserves_following_roles(): void
    {
        $user = $this->user('lector');
        $user->assignRole(['editor', 'admin']);
        $user->removeRole('lector');
        $this->assertEqualsCanonicalizing(['editor', 'admin'], $user->fresh()->getRoleNames()->all());
        $user->removeRole('editor');
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('editor'));
    }

    public function test_team_scope_truth_table_including_disabled_teams_and_null_context(): void
    {
        foreach ([false, true] as $enabled) {
            foreach ([false, true] as $strict) {
                foreach ([null, 'alpha', 'beta'] as $active) {
                    config(['permission.teams' => $enabled, 'permission.strict_team_isolation' => $strict]);
                    setPermissionsTeamId($active);
                    $this->assertSame($enabled ? $active : null, TeamScope::active());
                    foreach ([null, 'alpha', 'beta'] as $owner) {
                        $expected = ! $enabled || $owner === $active || (! $strict && $owner === null);
                        $this->assertSame($expected, TeamScope::grant($owner));
                        $this->assertSame(! $enabled || $owner === $active, TeamScope::owned($owner));
                        $this->assertSame(! $enabled || $owner === null || $owner === $active, TeamScope::catalog((object) ['team_id' => $owner], $active));
                    }
                }
            }
        }
    }

    public function test_mixed_team_grants_survive_removal_and_sync_in_another_team(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $user = $this->user('lector');
        setPermissionsTeamId('alpha');
        $user->givePermissionTo('articles.create', 'articles.publish');
        setPermissionsTeamId('beta');
        $user->givePermissionTo('articles.delete', 'users.manage');
        setPermissionsTeamId('alpha');
        $user->revokePermissionTo('articles.create');
        $this->assertTrue($user->hasDirectPermission('articles.publish'));
        $this->assertFalse($user->hasDirectPermission('articles.create'));
        $user->syncPermissions('articles.view');
        setPermissionsTeamId('beta');
        $this->assertEqualsCanonicalizing(['articles.delete', 'users.manage'], $user->fresh()->getPermissionNames()->all());
        $this->assertTrue($user->hasPermissionTo('articles.delete'));
        $this->assertFalse($user->hasPermissionTo('articles.view'));
    }

    public function test_permission_model_identity_does_not_alias_same_named_team_permission(): void
    {
        $global = Permission::findByName('articles.publish');
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        setPermissionsTeamId('alpha');
        $local = Permission::create(['name' => 'articles.publish']);
        $user = $this->user('lector');
        $user->givePermissionTo($global);
        $this->assertTrue($user->hasPermissionTo($global));
        $this->assertFalse($user->hasDirectPermission($local));
        // Passing a model asks about that concrete permission, not another same-named id.
        $this->assertFalse($user->hasPermissionTo($local));
    }

    public function test_model_permission_obeys_inherited_expiry_and_actual_wildcards(): void
    {
        $this->freezeTime();
        $user = $this->user('lector');
        $permission = Permission::findByName('articles.create');
        $user->assignRoleUntil('editor', now()->addMinute());
        $this->assertTrue($user->hasPermissionTo($permission));
        $this->travel(1)->minutes();
        $this->assertFalse($user->hasPermissionTo($permission));
        config(['permission.enable_wildcard_permission' => true]);
        Permission::create(['name' => 'articles.*']);
        $user->givePermissionTo('articles.*');
        $this->assertTrue($user->hasPermissionTo($permission));
        $user->revokePermissionTo('articles.*');
        $this->assertFalse($user->hasPermissionTo($permission));
    }

    public function test_same_name_is_not_a_wildcard_substitute_for_model_identity(): void
    {
        $global = Permission::findByName('articles.publish');
        config(['permission.teams' => true, 'permission.enable_wildcard_permission' => true]);
        setPermissionsTeamId('alpha');
        $local = Permission::create(['name' => 'articles.publish']);
        $user = $this->user('lector');
        $user->givePermissionTo($global);
        $this->assertFalse($user->hasPermissionTo($local));
        $user->givePermissionTo($local);
        $this->assertTrue($user->hasPermissionTo($local));
    }
}
