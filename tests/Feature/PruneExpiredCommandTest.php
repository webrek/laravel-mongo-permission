<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Carbon;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class PruneExpiredCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_removes_expired_role_subdocs(): void
    {
        Role::create(['name' => 'admin']);
        $user = TestUser::create(['name' => 'V']);
        $user->assignRoleUntil('admin', now()->addHour());

        $this->assertCount(1, $user->fresh()->role_ids ?? []);

        Carbon::setTestNow(now()->addHours(2));

        $this->artisan('permission:prune-expired')
            ->expectsOutputToContain('1 role grant')
            ->assertExitCode(0);

        $this->assertCount(0, $user->fresh()->role_ids ?? []);
    }

    public function test_removes_expired_permission_subdocs(): void
    {
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionToUntil('edit articles', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));

        $this->artisan('permission:prune-expired')
            ->expectsOutputToContain('1 permission grant')
            ->assertExitCode(0);

        $this->assertCount(0, $user->fresh()->permission_ids ?? []);
    }

    public function test_preserves_non_expired_grants(): void
    {
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'editor']);
        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('admin');
        $user->assignRoleUntil('editor', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));

        $this->artisan('permission:prune-expired')->assertExitCode(0);

        $remaining = $user->fresh()->role_ids ?? [];
        $this->assertCount(1, $remaining);
        $this->assertSame(
            (string) Role::findByName('admin')->getKey(),
            (string) $remaining[0]['role_id'],
        );
    }

    public function test_dry_run_does_not_modify_documents(): void
    {
        Role::create(['name' => 'admin']);
        $user = TestUser::create(['name' => 'V']);
        $user->assignRoleUntil('admin', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));

        $this->artisan('permission:prune-expired', ['--dry-run' => true])
            ->expectsOutputToContain('would prune')
            ->assertExitCode(0);

        $this->assertCount(1, $user->fresh()->role_ids ?? []);
    }

    public function test_reports_zero_when_nothing_expired(): void
    {
        Role::create(['name' => 'admin']);
        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('admin');

        $this->artisan('permission:prune-expired')
            ->expectsOutputToContain('Pruned 0 role grant')
            ->assertExitCode(0);

        $this->assertCount(1, $user->fresh()->role_ids ?? []);
    }
}
