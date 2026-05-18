<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Webrek\MongoPermission\Exceptions\PermissionDoesNotExist;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class HasPermissionsTest extends TestCase
{
    public function test_user_can_be_given_a_direct_permission(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Permission::create(['name' => 'edit articles']);

        $user->givePermissionTo('edit articles');

        $this->assertTrue($user->fresh()->hasDirectPermission('edit articles'));
        $this->assertTrue($user->fresh()->hasPermissionTo('edit articles'));
    }

    public function test_revoke_removes_the_permission(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Permission::create(['name' => 'edit articles']);

        $user->givePermissionTo('edit articles');
        $user->revokePermissionTo('edit articles');

        $this->assertFalse($user->fresh()->hasDirectPermission('edit articles'));
    }

    public function test_sync_permissions_replaces_all(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Permission::create(['name' => 'edit articles']);
        Permission::create(['name' => 'delete articles']);

        $user->givePermissionTo('edit articles');
        $user->syncPermissions(['delete articles']);

        $this->assertFalse($user->fresh()->hasDirectPermission('edit articles'));
        $this->assertTrue($user->fresh()->hasDirectPermission('delete articles'));
    }

    public function test_has_permission_to_throws_when_permission_does_not_exist(): void
    {
        $user = TestUser::create(['name' => 'Victor']);

        $this->expectException(PermissionDoesNotExist::class);

        $user->hasPermissionTo('ghost');
    }

    public function test_has_any_permission(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Permission::create(['name' => 'a']);
        Permission::create(['name' => 'b']);
        $user->givePermissionTo('a');

        $this->assertTrue($user->fresh()->hasAnyPermission('a', 'b'));
        $this->assertFalse($user->fresh()->hasAnyPermission('b'));
    }

    public function test_has_all_permissions(): void
    {
        $user = TestUser::create(['name' => 'Victor']);
        Permission::create(['name' => 'a']);
        Permission::create(['name' => 'b']);
        $user->givePermissionTo(['a', 'b']);

        $this->assertTrue($user->fresh()->hasAllPermissions(['a', 'b']));

        $user->revokePermissionTo('b');

        $this->assertFalse($user->fresh()->hasAllPermissions(['a', 'b']));
    }
}
