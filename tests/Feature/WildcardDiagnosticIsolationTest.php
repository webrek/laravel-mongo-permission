<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class WildcardDiagnosticIsolationTest extends TestCase
{
    public function test_wildcard_diagnostics_reject_expired_and_foreign_assignments_independently(): void
    {
        $this->freezeTime();
        config(['permission.teams' => true, 'permission.strict_team_isolation' => false, 'permission.enable_wildcard_permission' => true]);
        $p = Permission::create(['name' => 'docs.*']);
        $r = Role::create(['name' => 'reader']);
        $r->givePermissionTo($p);
        foreach (['permission' => $p, 'role' => $r] as $kind => $model) {
            foreach ([['team_id' => 'B'], ['team_id' => 'A', 'expires_at' => now()], ['team_id' => 'A']] as $metadata) {
                $u = TestUser::create(['name' => 'u', $kind.'_ids' => [array_merge([$kind.'_id' => (string) $model->id], $metadata)]]);
                $this->assertSame(0, Artisan::call('permission:check', ['user_id' => (string) $u->id, 'permission' => 'docs.edit', '--team' => 'A']));
                $out = Artisan::output();
                $allowed = $metadata === ['team_id' => 'A'];
                $this->assertSame($allowed, str_contains($out, '[ok] wildcard'));
                $this->assertSame(! $allowed, str_contains($out, 'no wildcard grant implies'));
            }
        }
    }

    public function test_skipped_direct_grants_and_literal_catalog_entries_do_not_hide_later_wildcards(): void
    {
        $this->freezeTime();
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true, 'permission.enable_wildcard_permission' => true]);
        $literal = Permission::create(['name' => 'literal']);
        $bad = Permission::create(['name' => 'foreign.*']);
        $p = Permission::create(['name' => 'docs.*']);
        $u = TestUser::create(['name' => 'u', 'permission_ids' => [
            ['permission_id' => (string) $bad->id, 'team_id' => 'B'],
            ['permission_id' => (string) $bad->id, 'team_id' => 'A', 'expires_at' => now()],
            ['permission_id' => (string) $literal->id, 'team_id' => 'A'],
            ['permission_id' => (string) $p->id, 'team_id' => 'A'],
        ]]);
        $this->assertSame(0, Artisan::call('permission:check', ['user_id' => (string) $u->id, 'permission' => 'docs.edit', '--team' => 'A']));
        $this->assertStringContainsString('[ok] wildcard "docs.*"', Artisan::output());
    }

    public function test_direct_and_inherited_wildcards_are_each_reported_once(): void
    {
        config(['permission.enable_wildcard_permission' => true]);
        $direct = Permission::create(['name' => 'docs.*']);
        $inherited = Permission::create(['name' => '*.edit']);
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b']);
        $a->givePermissionTo($direct, $inherited);
        $b->givePermissionTo($inherited);
        $u = TestUser::create(['name' => 'u']);
        $u->givePermissionTo($direct)->assignRole($a, $b);
        $this->assertSame(0, Artisan::call('permission:check', ['user_id' => (string) $u->id, 'permission' => 'docs.edit']));
        $out = Artisan::output();
        $this->assertSame(1, substr_count($out, '[ok] wildcard "docs.*"'));
        $this->assertSame(1, substr_count($out, '[ok] wildcard "*.edit"'));
    }

    public function test_unrelated_first_role_does_not_hide_a_later_matching_role(): void
    {
        $p = Permission::create(['name' => 'p']);
        $r = Role::create(['name' => 'r']);
        $s = Role::create(['name' => 's']);
        $s->givePermissionTo($p);
        $u = TestUser::create(['name' => 'u']);
        $u->assignRole($r, $s);
        $this->assertSame(0, Artisan::call('permission:check', ['user_id' => (string) $u->id, 'permission' => 'p']));
        $this->assertStringContainsString('[ok] via role "s"', Artisan::output());
        $this->assertFalse($u->hasAllPermissions('p', 'does-not-exist'));
    }
}
