<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Webrek\MongoPermission\Exceptions\PermissionAlreadyExists;
use Webrek\MongoPermission\Exceptions\PermissionDoesNotExist;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Tests\TestCase;

class PermissionTest extends TestCase
{
    public function test_it_creates_a_permission_with_default_guard(): void
    {
        $perm = Permission::create(['name' => 'edit articles']);

        $this->assertSame('edit articles', $perm->getName());
        $this->assertSame('web', $perm->getGuardName());
        $this->assertNotEmpty($perm->getKey());
    }

    public function test_it_can_find_a_permission_by_name(): void
    {
        Permission::create(['name' => 'edit articles']);

        $found = Permission::findByName('edit articles');

        $this->assertSame('edit articles', $found->getName());
    }

    public function test_find_by_name_throws_when_missing(): void
    {
        $this->expectException(PermissionDoesNotExist::class);

        Permission::findByName('nope');
    }

    public function test_creating_a_duplicate_throws(): void
    {
        Permission::create(['name' => 'edit articles']);

        $this->expectException(PermissionAlreadyExists::class);

        Permission::create(['name' => 'edit articles']);
    }
}
