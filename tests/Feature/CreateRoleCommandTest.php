<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\TestCase;

class CreateRoleCommandTest extends TestCase
{
    public function test_creates_role_with_default_guard(): void
    {
        $this->artisan('permission:create-role', ['name' => 'admin'])
            ->assertExitCode(0);

        $this->assertTrue(Role::query()->where('name', 'admin')->where('guard_name', 'web')->exists());
    }

    public function test_creates_role_with_explicit_guard(): void
    {
        $this->artisan('permission:create-role', ['name' => 'admin', '--guard' => 'api'])
            ->assertExitCode(0);

        $this->assertTrue(Role::query()->where('name', 'admin')->where('guard_name', 'api')->exists());
    }

    public function test_creates_role_and_attaches_permissions(): void
    {
        Permission::create(['name' => 'edit']);
        Permission::create(['name' => 'delete']);

        $this->artisan('permission:create-role', [
            'name' => 'editor',
            'permissions' => ['edit', 'delete'],
        ])->assertExitCode(0);

        $role = Role::findByName('editor');
        $this->assertCount(2, $role->permission_ids ?? []);
    }
}
