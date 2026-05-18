<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class ListUsersCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function runCommand(array $args): array
    {
        $exit = Artisan::call('permission:list-users', $args);
        return [$exit, Artisan::output()];
    }

    public function test_lists_users_with_role(): void
    {
        Role::create(['name' => 'admin']);
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = TestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        TestUser::create(['name' => 'Charlie']);

        $alice->assignRole('admin');
        $bob->assignRole('admin');

        [$exit, $output] = $this->runCommand(['role' => 'admin']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2 user(s) with role "admin"', $output);
        $this->assertStringContainsString('Alice', $output);
        $this->assertStringContainsString('Bob', $output);
        $this->assertStringNotContainsString('Charlie', $output);
    }

    public function test_lists_users_with_permission_direct(): void
    {
        Permission::create(['name' => 'publish']);
        $alice = TestUser::create(['name' => 'Alice']);
        TestUser::create(['name' => 'Bob']);

        $alice->givePermissionTo('publish');

        [$exit, $output] = $this->runCommand(['--permission' => 'publish']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 user(s) with permission "publish"', $output);
        $this->assertStringContainsString('Alice', $output);
        $this->assertStringContainsString('direct', $output);
    }

    public function test_lists_users_with_permission_via_role(): void
    {
        Permission::create(['name' => 'publish']);
        $editor = Role::create(['name' => 'editor']);
        $editor->givePermissionTo('publish');

        $alice = TestUser::create(['name' => 'Alice']);
        $alice->assignRole('editor');

        [$exit, $output] = $this->runCommand(['--permission' => 'publish']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Alice', $output);
        $this->assertStringContainsString('via role editor', $output);
    }

    public function test_unknown_role_returns_failure(): void
    {
        [$exit, $output] = $this->runCommand(['role' => 'ghost']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Role "ghost" not found', $output);
    }

    public function test_requires_role_or_permission(): void
    {
        [$exit, $output] = $this->runCommand([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pass a role argument or', $output);
    }

    public function test_rejects_both_role_and_permission(): void
    {
        Role::create(['name' => 'admin']);
        Permission::create(['name' => 'edit']);

        [$exit, $output] = $this->runCommand(['role' => 'admin', '--permission' => 'edit']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not both', $output);
    }

    public function test_expired_role_is_excluded(): void
    {
        Role::create(['name' => 'admin']);
        $alice = TestUser::create(['name' => 'Alice']);
        $alice->assignRoleUntil('admin', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));

        [$exit, $output] = $this->runCommand(['role' => 'admin']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('0 user(s) with role "admin"', $output);
        $this->assertStringNotContainsString('Alice', $output);
    }
}
