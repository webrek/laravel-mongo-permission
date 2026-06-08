<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

/**
 * Data written by older tooling (e.g. Maklad) stores role_ids / permission_ids
 * as a flat array of id strings, not the structured subdocuments this package
 * writes. These tests pin that backward-compatible behaviour.
 */
class LegacyFlatDataTest extends TestCase
{
    public function test_has_role_reads_flat_role_ids(): void
    {
        $role = Role::create(['name' => 'admin']);

        $user = TestUser::create(['name' => 'Victor']);
        $user->role_ids = [(string) $role->getKey()]; // legacy flat form
        $user->save();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('admin'));
        $this->assertSame(['admin'], $fresh->getRoleNames()->all());
    }

    public function test_has_direct_permission_reads_flat_permission_ids(): void
    {
        $permission = Permission::create(['name' => 'edit-posts']);

        $user = TestUser::create(['name' => 'Victor']);
        $user->permission_ids = [(string) $permission->getKey()]; // legacy flat form
        $user->save();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasDirectPermission('edit-posts'));
        $this->assertTrue($fresh->hasPermissionTo('edit-posts'));
    }

    public function test_permission_resolves_through_a_flat_role_assignment(): void
    {
        $permission = Permission::create(['name' => 'reports.view']);
        $role = Role::create(['name' => 'manager']);
        $role->permission_ids = [(string) $permission->getKey()]; // role stores flat ids
        $role->save();

        $user = TestUser::create(['name' => 'Victor']);
        $user->role_ids = [(string) $role->getKey()]; // legacy flat form
        $user->save();

        $this->assertTrue($user->fresh()->hasPermissionTo('reports.view'));
    }

    public function test_remove_role_works_on_flat_data(): void
    {
        $role = Role::create(['name' => 'admin']);

        $user = TestUser::create(['name' => 'Victor']);
        $user->role_ids = [(string) $role->getKey()];
        $user->save();

        $user->fresh()->removeRole('admin');

        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_assigning_a_role_to_flat_data_upgrades_without_loss(): void
    {
        $admin = Role::create(['name' => 'admin']);
        $editor = Role::create(['name' => 'editor']);

        $user = TestUser::create(['name' => 'Victor']);
        $user->role_ids = [(string) $admin->getKey()]; // legacy flat form
        $user->save();

        $user->fresh()->assignRole('editor');

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('admin'));   // legacy role preserved
        $this->assertTrue($fresh->hasRole('editor'));  // new role added
    }

    public function test_deleting_a_role_clears_flat_references_from_users(): void
    {
        $role = Role::create(['name' => 'admin']);

        $user = TestUser::create(['name' => 'Victor']);
        $user->role_ids = [(string) $role->getKey()]; // legacy flat form
        $user->save();

        $role->delete();

        $this->assertSame([], $user->fresh()->role_ids ?? []);
    }

    public function test_deleting_a_permission_clears_flat_references_from_users(): void
    {
        $permission = Permission::create(['name' => 'edit-posts']);

        $user = TestUser::create(['name' => 'Victor']);
        $user->permission_ids = [(string) $permission->getKey()]; // legacy flat form
        $user->save();

        $permission->delete();

        $this->assertSame([], $user->fresh()->permission_ids ?? []);
    }

    public function test_editing_a_role_permission_set_invalidates_cached_user_permissions(): void
    {
        $view = Permission::create(['name' => 'reports.view']);
        $edit = Permission::create(['name' => 'reports.edit']);

        $role = Role::create(['name' => 'manager']);
        $role->permission_ids = [(string) $view->getKey()];
        $role->save();

        $user = TestUser::create(['name' => 'Victor']);
        $user->assignRole('manager');

        // Warm the cache: the user has view but not edit.
        $this->assertTrue($user->fresh()->hasPermissionTo('reports.view'));
        $this->assertFalse($user->fresh()->hasPermissionTo('reports.edit'));

        // Admin edits the role's permission set directly (e.g. via the Filament panel).
        $role->permission_ids = [(string) $view->getKey(), (string) $edit->getKey()];
        $role->save();

        // Must reflect immediately — stale cache would still report false.
        $this->assertTrue($user->fresh()->hasPermissionTo('reports.edit'));
    }
}
