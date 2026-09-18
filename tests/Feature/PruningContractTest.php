<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class PruningContractTest extends TestCase
{
    public function test_pruning_preserves_a_concurrent_grant_added_after_cursor_read(): void
    {
        $this->freezeTime();
        $expired = Permission::create(['name' => 'expired']);
        $new = Permission::create(['name' => 'concurrent']);
        $u = TestUser::create(['name' => 'race']);
        $u->givePermissionToUntil($expired, now()->subMinute());
        $injected = false;
        TestUser::retrieved(function ($model) use (&$injected, $new) {
            if (! $injected) {
                $injected = true;
                $entries = $model->permission_ids;
                $entries[] = ['permission_id' => (string) $new->id, 'team_id' => null, 'expires_at' => null];
                TestUser::whereKey($model->id)->update(['permission_ids' => $entries]);
            }
        });
        $this->assertSame(0, Artisan::call('permission:prune-expired'));
        $this->assertTrue($injected);
        $this->assertSame([['permission_id' => (string) $new->id, 'team_id' => null, 'expires_at' => null]], $u->fresh()->permission_ids);
    }

    public function test_pruning_skips_initial_unchanged_users_counts_all_users_and_preserves_flat_grants(): void
    {
        $this->freezeTime();
        TestUser::create(['name' => 'untouched']);
        $p = Permission::create(['name' => 'p']);
        $r = Role::create(['name' => 'r']);
        $users = [];
        foreach (['first', 'second'] as $name) {
            $users[] = TestUser::create(['name' => $name, 'role_ids' => [['role_id' => 'expired', 'expires_at' => now()], (string) $r->id], 'permission_ids' => [['permission_id' => 'expired', 'expires_at' => now()], (string) $p->id]]);
        }
        $this->assertSame(0, Artisan::call('permission:prune-expired', ['--dry-run' => true]));
        $this->assertStringContainsString('Dry run: would prune 2 role grant(s) and 2 permission grant(s) across 2 user(s).', Artisan::output());
        $this->assertCount(2, $users[0]->fresh()->role_ids);
        $this->assertSame(0, Artisan::call('permission:prune-expired'));
        $this->assertStringContainsString('Pruned 2 role grant(s) and 2 permission grant(s) across 2 user(s).', Artisan::output());
        foreach ($users as $u) {
            $this->assertSame([(string) $r->id], $u->fresh()->role_ids);
            $this->assertSame([(string) $p->id], $u->fresh()->permission_ids);
        }
    }
}
