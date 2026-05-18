<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class WildcardIntegrationTest extends TestCase
{
    public function test_role_with_wildcard_permission_implies_specific_permission(): void
    {
        Permission::create(['name' => 'posts.*']);
        Permission::create(['name' => 'posts.edit']);
        $role = Role::create(['name' => 'editor']);
        $role->givePermissionTo('posts.*');

        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('editor');

        $this->assertTrue($user->fresh()->hasPermissionTo('posts.edit'));
    }

    public function test_direct_wildcard_permission_implies_specific(): void
    {
        Permission::create(['name' => 'posts.*']);
        Permission::create(['name' => 'posts.delete']);

        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('posts.*');

        $this->assertTrue($user->fresh()->hasPermissionTo('posts.delete'));
    }

    public function test_super_admin_star_implies_anything(): void
    {
        Permission::create(['name' => '*']);
        Permission::create(['name' => 'unrelated.thing']);

        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('*');

        $this->assertTrue($user->fresh()->hasPermissionTo('unrelated.thing'));
    }

    public function test_wildcard_does_not_match_when_disabled(): void
    {
        config()->set('permission.enable_wildcard_permission', false);

        Permission::create(['name' => 'posts.*']);
        Permission::create(['name' => 'posts.edit']);

        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('posts.*');

        $this->assertFalse($user->fresh()->hasPermissionTo('posts.edit'));
    }
}
