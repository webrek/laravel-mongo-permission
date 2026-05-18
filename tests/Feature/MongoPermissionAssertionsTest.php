<?php

namespace Webrek\MongoPermission\Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Testing\MongoPermissionAssertions;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class MongoPermissionAssertionsTest extends TestCase
{
    use MongoPermissionAssertions;

    public function test_assertUserHasRole_passes(): void
    {
        Role::create(['name' => 'admin']);
        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('admin');

        $this->assertUserHasRole($user->fresh(), 'admin');
    }

    public function test_assertUserHasRole_fails(): void
    {
        Role::create(['name' => 'admin']);
        $user = TestUser::create(['name' => 'V']);

        $this->expectException(AssertionFailedError::class);
        $this->assertUserHasRole($user->fresh(), 'admin');
    }

    public function test_assertUserHasPermission_passes(): void
    {
        Permission::create(['name' => 'edit']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('edit');

        $this->assertUserHasPermission($user->fresh(), 'edit');
    }

    public function test_assertUserDoesNotHavePermission_passes_for_unknown_name(): void
    {
        $user = TestUser::create(['name' => 'V']);

        $this->assertUserDoesNotHavePermission($user->fresh(), 'this-does-not-exist');
    }

    public function test_assertRoleHasPermission_passes(): void
    {
        Permission::create(['name' => 'edit']);
        $role = Role::create(['name' => 'editor']);
        $role->givePermissionTo('edit');

        $this->assertRoleHasPermission($role->fresh(), 'edit');
    }

    public function test_assertUserHasAnyRole_passes(): void
    {
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'editor']);
        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('editor');

        $this->assertUserHasAnyRole($user->fresh(), ['admin', 'editor']);
    }

    public function test_assertUserHasAllRoles_passes(): void
    {
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'editor']);
        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('admin');
        $user->assignRole('editor');

        $this->assertUserHasAllRoles($user->fresh(), ['admin', 'editor']);
    }

    public function test_assertUserHasDirectPermission_passes(): void
    {
        Permission::create(['name' => 'edit']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('edit');

        $this->assertUserHasDirectPermission($user->fresh(), 'edit');
    }
}
