<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Webrek\MongoPermission\Exceptions\RoleDoesNotExist;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class HasRolesTest extends TestCase
{
    public function test_user_can_be_assigned_a_role(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Role::create(['name' => 'admin']);

        $user->assignRole('admin');

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    public function test_assign_unknown_role_throws(): void
    {
        $user = TestUser::create(['name' => 'Victor']);

        $this->expectException(RoleDoesNotExist::class);

        $user->assignRole('ghost');
    }

    public function test_remove_role(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Role::create(['name' => 'admin']);

        $user->assignRole('admin');
        $user->removeRole('admin');

        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_sync_roles_replaces_all(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Role::create(['name' => 'a']);
        Role::create(['name' => 'b']);

        $user->assignRole('a');
        $user->syncRoles(['b']);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasRole('a'));
        $this->assertTrue($fresh->hasRole('b'));
    }

    public function test_has_any_role(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Role::create(['name' => 'a']);
        Role::create(['name' => 'b']);
        $user->assignRole('a');

        $this->assertTrue($user->fresh()->hasAnyRole('a', 'b'));
        $this->assertFalse($user->fresh()->hasAnyRole('b'));
    }

    public function test_has_all_roles(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Role::create(['name' => 'a']);
        Role::create(['name' => 'b']);
        $user->assignRole(['a', 'b']);

        $this->assertTrue($user->fresh()->hasAllRoles(['a', 'b']));
    }

    public function test_permission_is_inherited_from_role(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Permission::create(['name' => 'edit articles']);
        $role = Role::create(['name' => 'editor']);
        $role->givePermissionTo('edit articles');

        $user->assignRole('editor');

        $this->assertTrue($user->fresh()->hasPermissionTo('edit articles'));
        $this->assertFalse($user->fresh()->hasDirectPermission('edit articles'));
    }
}
