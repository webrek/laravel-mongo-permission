<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CacheRecoveryContractTest extends TestCase
{
    public function test_recreating_evicted_generation_metadata_does_not_reuse_retired_permissions(): void
    {
        $p = Permission::create(['name' => 'p']);
        $u = TestUser::create(['name' => 'u']);
        $u->givePermissionTo($p);
        $reader = new PermissionRegistrar;
        $this->assertSame(['p'], $reader->getUserPermissionSlugs($u));
        TestUser::whereKey($u->id)->update(['permission_ids' => []]);
        // Simulate partial cache eviction: authorization entries remain but their generation metadata is lost.
        Cache::forget(config('permission.cache.key').'.version');
        $this->assertSame([], $reader->getUserPermissionSlugs($u));
    }

    public function test_duplicate_global_and_team_assignments_keep_later_roles_and_exact_role_semantics(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => false]);
        $p = Permission::create(['name' => 'p']);
        $q = Permission::create(['name' => 'q']);
        $r = Role::create(['name' => 'r']);
        $r->givePermissionTo($p);
        $s = Role::create(['name' => 's']);
        $s->givePermissionTo($q);
        $u = TestUser::create(['name' => 'duplicates', 'role_ids' => [(string) $r->id, ['role_id' => (string) $r->id, 'team_id' => 'A'], (string) $s->id], 'permission_ids' => [(string) $p->id, (string) $p->id, (string) $q->id]]);
        setPermissionsTeamId('A');
        $this->assertTrue($u->hasExactRoles([$r, $s]));
        $this->assertTrue($u->hasPermissionTo($q));
        $this->assertTrue($u->hasRole($s));
        $this->assertEqualsCanonicalizing([(string) $p->id, (string) $q->id], app(PermissionRegistrar::class)->getUserPermissionIds($u));
    }
}
