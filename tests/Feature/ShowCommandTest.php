<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\TestCase;

class ShowCommandTest extends TestCase
{
    public function test_prints_roles_and_permissions(): void
    {
        Permission::create(['name' => 'edit']);
        Permission::create(['name' => 'delete']);
        $admin = Role::create(['name' => 'admin']);
        $admin->givePermissionTo('edit', 'delete');
        Role::create(['name' => 'viewer']);

        $this->artisan('permission:show')
            ->expectsOutputToContain('admin')
            ->expectsOutputToContain('viewer')
            ->expectsOutputToContain('edit')
            ->expectsOutputToContain('delete')
            ->assertExitCode(0);
    }

    public function test_filters_by_guard(): void
    {
        Permission::create(['name' => 'web-only', 'guard_name' => 'web']);
        Permission::create(['name' => 'api-only', 'guard_name' => 'api']);
        Role::create(['name' => 'web-role', 'guard_name' => 'web']);
        Role::create(['name' => 'api-role', 'guard_name' => 'api']);

        $this->artisan('permission:show', ['--guard' => 'api'])
            ->expectsOutputToContain('api-role')
            ->expectsOutputToContain('api-only')
            ->doesntExpectOutputToContain('web-role')
            ->assertExitCode(0);
    }
}
