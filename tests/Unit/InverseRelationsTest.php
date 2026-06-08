<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class InverseRelationsTest extends TestCase
{
    public function test_role_users_matches_flat_and_structured(): void
    {
        $role = Role::create(['name' => 'admin']);

        $structured = TestUser::create(['name' => 'Structured']);
        $structured->assignRole('admin'); // structured role_ids subdocuments

        $flat = TestUser::create(['name' => 'Flat']);
        $flat->role_ids = [(string) $role->getKey()]; // legacy flat form
        $flat->save();

        TestUser::create(['name' => 'None']);

        $this->assertEqualsCanonicalizing(['Structured', 'Flat'], $role->users()->pluck('name')->all());
    }

    public function test_permission_roles(): void
    {
        $permission = Permission::create(['name' => 'edit']);
        Role::create(['name' => 'editor', 'permission_ids' => [(string) $permission->getKey()]]);
        Role::create(['name' => 'other']);

        $this->assertSame(['editor'], $permission->roles()->pluck('name')->all());
    }

    public function test_role_permissions_method(): void
    {
        $permission = Permission::create(['name' => 'edit']);
        $role = Role::create(['name' => 'editor', 'permission_ids' => [(string) $permission->getKey()]]);

        $this->assertSame(['edit'], $role->permissions()->pluck('name')->all());
    }

    public function test_user_roles_and_permissions_methods(): void
    {
        Role::create(['name' => 'admin']);
        Permission::create(['name' => 'edit']);

        $user = TestUser::create(['name' => 'A']);
        $user->assignRole('admin');
        $user->givePermissionTo('edit');

        $fresh = $user->fresh();
        $this->assertContains('admin', $fresh->roles()->pluck('name')->all());
        $this->assertContains('edit', $fresh->permissions()->pluck('name')->all());
    }
}
