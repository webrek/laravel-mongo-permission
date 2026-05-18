<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Tests\TestCase;

class CreatePermissionCommandTest extends TestCase
{
    public function test_creates_permission_with_default_guard(): void
    {
        $this->artisan('permission:create-permission', ['name' => 'edit articles'])
            ->assertExitCode(0);

        $this->assertTrue(Permission::query()->where('name', 'edit articles')->exists());
    }

    public function test_creates_permission_with_explicit_guard(): void
    {
        $this->artisan('permission:create-permission', ['name' => 'edit articles', '--guard' => 'api'])
            ->assertExitCode(0);

        $this->assertTrue(Permission::query()
            ->where('name', 'edit articles')
            ->where('guard_name', 'api')
            ->exists());
    }

    public function test_wildcard_name_prints_warning_but_still_succeeds(): void
    {
        $this->artisan('permission:create-permission', ['name' => 'posts.*'])
            ->expectsOutputToContain('wildcard')
            ->assertExitCode(0);

        $this->assertTrue(Permission::query()->where('name', 'posts.*')->exists());
    }
}
