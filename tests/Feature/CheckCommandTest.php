<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CheckCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function runCheck(array $args): array
    {
        $exit = Artisan::call('permission:check', $args);
        return [$exit, Artisan::output()];
    }

    public function test_direct_grant_reports_yes(): void
    {
        Permission::create(['name' => 'edit']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('edit');

        [$exit, $output] = $this->runCheck([
            'user_id' => (string) $user->getKey(),
            'permission' => 'edit',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('YES', $output);
        $this->assertStringContainsString('direct grant', $output);
    }

    public function test_role_based_grant_reports_yes_with_role_name(): void
    {
        Permission::create(['name' => 'edit']);
        $editor = Role::create(['name' => 'editor']);
        $editor->givePermissionTo('edit');

        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('editor');

        [$exit, $output] = $this->runCheck([
            'user_id' => (string) $user->getKey(),
            'permission' => 'edit',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('YES', $output);
        $this->assertStringContainsString('via role "editor"', $output);
    }

    public function test_no_grant_reports_no(): void
    {
        Permission::create(['name' => 'edit']);
        $user = TestUser::create(['name' => 'V']);

        [$exit, $output] = $this->runCheck([
            'user_id' => (string) $user->getKey(),
            'permission' => 'edit',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('NO', $output);
        $this->assertStringContainsString('no matching grants', $output);
    }

    public function test_expired_grant_is_marked_skip(): void
    {
        Permission::create(['name' => 'edit']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionToUntil('edit', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));

        [$exit, $output] = $this->runCheck([
            'user_id' => (string) $user->getKey(),
            'permission' => 'edit',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('NO', $output);
        $this->assertStringContainsString('expired', $output);
    }

    public function test_unknown_permission_falls_through_to_wildcard_search(): void
    {
        Permission::create(['name' => 'posts.*']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('posts.*');

        [$exit, $output] = $this->runCheck([
            'user_id' => (string) $user->getKey(),
            'permission' => 'posts.edit',
        ]);

        // 'posts.edit' is not in the catalog, so the command warns but still reports the wildcard.
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('does not exist', $output);
        $this->assertStringContainsString('posts.*', $output);
    }

    public function test_unknown_user_returns_failure(): void
    {
        [$exit, $output] = $this->runCheck([
            'user_id' => 'nonexistent-id',
            'permission' => 'edit',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $output);
    }

    public function test_wildcard_grant_is_reported(): void
    {
        Permission::create(['name' => 'posts.*']);
        Permission::create(['name' => 'posts.edit']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('posts.*');

        [$exit, $output] = $this->runCheck([
            'user_id' => (string) $user->getKey(),
            'permission' => 'posts.edit',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('YES', $output);
        $this->assertStringContainsString('wildcard "posts.*" implies', $output);
    }
}
