<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Webrek\MongoPermission\Exceptions\RoleAlreadyExists;
use Webrek\MongoPermission\Exceptions\RoleDoesNotExist;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\TestCase;

class RoleTest extends TestCase
{
    public function test_it_creates_a_role(): void
    {
        $role = Role::create(['name' => 'admin']);

        $this->assertSame('admin', $role->getName());
        $this->assertSame('web', $role->getGuardName());
    }

    public function test_find_by_name_throws_when_missing(): void
    {
        $this->expectException(RoleDoesNotExist::class);

        Role::findByName('nope');
    }

    public function test_creating_duplicate_role_throws(): void
    {
        Role::create(['name' => 'admin']);

        $this->expectException(RoleAlreadyExists::class);

        Role::create(['name' => 'admin']);
    }

    public function test_it_gives_and_revokes_permission(): void
    {
        $role = Role::create(['name' => 'editor']);
        Permission::create(['name' => 'edit articles']);

        $role->givePermissionTo('edit articles');

        $this->assertTrue($role->fresh()->hasPermissionTo('edit articles'));

        $role->revokePermissionTo('edit articles');

        $this->assertFalse($role->fresh()->hasPermissionTo('edit articles'));
    }

    public function test_sync_permissions_replaces_all(): void
    {
        $role = Role::create(['name' => 'editor']);
        Permission::create(['name' => 'edit articles']);
        Permission::create(['name' => 'delete articles']);

        $role->givePermissionTo('edit articles');
        $role->syncPermissions(['delete articles']);

        $fresh = $role->fresh();
        $this->assertFalse($fresh->hasPermissionTo('edit articles'));
        $this->assertTrue($fresh->hasPermissionTo('delete articles'));
    }
}
