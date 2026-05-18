<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CacheInvalidationTest extends TestCase
{
    public function test_revoke_invalidates_cached_permission(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Permission::create(['name' => 'edit']);
        $user->givePermissionTo('edit');

        $user = $user->fresh();
        $this->assertTrue($user->hasPermissionTo('edit'));   // warm cache

        $user->revokePermissionTo('edit');

        $this->assertFalse($user->fresh()->hasPermissionTo('edit'));
    }

    public function test_remove_role_invalidates_cached_role_derived_permission(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Permission::create(['name' => 'edit']);
        $role = Role::create(['name' => 'editor']);
        $role->givePermissionTo('edit');
        $user->assignRole('editor');

        $user = $user->fresh();
        $this->assertTrue($user->hasPermissionTo('edit'));

        $user->removeRole('editor');

        $this->assertFalse($user->fresh()->hasPermissionTo('edit'));
    }
}
