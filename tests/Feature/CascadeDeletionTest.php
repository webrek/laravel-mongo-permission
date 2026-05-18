<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CascadeDeletionTest extends TestCase
{
    public function test_deleting_a_role_removes_role_refs_from_users(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        $role = Role::create(['name' => 'admin']);
        $user->assignRole('admin');
        $this->assertTrue($user->fresh()->hasRole('admin'));

        $role->delete();

        $this->assertEmpty($user->fresh()->role_ids ?? []);
    }

    public function test_deleting_a_permission_removes_direct_refs_from_users(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        $perm = Permission::create(['name' => 'edit articles']);
        $user->givePermissionTo('edit articles');
        $this->assertTrue($user->fresh()->hasDirectPermission('edit articles'));

        $perm->delete();

        $this->assertEmpty($user->fresh()->permission_ids ?? []);
    }

    public function test_deleting_a_permission_removes_it_from_roles(): void
    {
        $perm = Permission::create(['name' => 'edit articles']);
        $role = Role::create(['name' => 'editor']);
        $role->givePermissionTo('edit articles');
        $this->assertNotEmpty($role->fresh()->permission_ids);

        $perm->delete();

        $this->assertEmpty($role->fresh()->permission_ids ?? []);
    }
}
