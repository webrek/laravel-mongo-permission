<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class DiagnosticContractTest extends TestCase
{
    private function check(TestUser $u, string $permission, array $options = []): string
    {
        $this->assertSame(0, Artisan::call('permission:check', array_merge(['user_id' => (string) $u->id, 'permission' => $permission], $options)));

        return Artisan::output();
    }

    public function test_check_does_not_confuse_unrelated_role_permissions_and_reports_later_direct_grants(): void
    {
        $a = Permission::create(['name' => 'a']);
        $b = Permission::create(['name' => 'b']);
        $r = Role::create(['name' => 'r']);
        $r->givePermissionTo($a);
        $u = TestUser::create(['name' => 'u']);
        $u->assignRole($r);
        $this->assertStringContainsString("  NO\n  no matching grants found", $this->check($u, 'b'));
        $u->givePermissionTo($a, $b);
        $this->assertStringContainsString("  YES\n  [ok] direct grant in team [global]", $this->check($u, 'b'));
    }

    public function test_check_explains_team_mismatch_and_exact_expiry(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01'));
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        $p = Permission::create(['name' => 'read']);
        $u = TestUser::create(['name' => 'u', 'permission_ids' => [
            ['permission_id' => (string) $p->id, 'team_id' => 'B'],
            ['permission_id' => (string) $p->id, 'team_id' => 'A', 'expires_at' => now()],
        ]]);
        $output = $this->check($u, 'read', ['--team' => 'A']);
        $this->assertStringContainsString('  NO', $output);
        $this->assertStringContainsString('team [B] does not match requested team [A]', $output);
        $this->assertStringContainsString('expired', $output);
    }

    public function test_check_inherited_wildcards_skip_invalid_earlier_grants_and_show_multiple_sources(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01'));
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true, 'permission.enable_wildcard_permission' => true]);
        $one = Permission::create(['name' => 'posts.*']);
        $two = Permission::create(['name' => '*.edit']);
        $other = Permission::create(['name' => 'users.*']);
        $parent = Role::create(['name' => 'parent']);
        $parent->givePermissionTo($one, $two);
        $child = Role::create(['name' => 'child']);
        $child->inheritsFrom($parent);
        $u = TestUser::create(['name' => 'u', 'permission_ids' => [
            ['permission_id' => (string) $other->id, 'team_id' => 'B'],
            ['permission_id' => (string) $other->id, 'team_id' => 'A', 'expires_at' => now()],
            ['permission_id' => (string) $other->id, 'team_id' => 'A'],
        ], 'role_ids' => [
            ['role_id' => (string) $child->id, 'team_id' => 'B'],
            ['role_id' => (string) $child->id, 'team_id' => 'A', 'expires_at' => now()],
            ['role_id' => (string) $child->id, 'team_id' => 'A'],
        ]]);
        $output = $this->check($u, 'posts.edit', ['--team' => 'A']);
        $this->assertStringContainsString('Checking wildcards owned by user...', $output);
        $this->assertStringContainsString('[ok] wildcard "posts.*"', $output);
        $this->assertStringContainsString('[ok] wildcard "*.edit"', $output);
        $this->assertStringNotContainsString('no wildcard grant implies', $output);
        $miss = $this->check($u, 'payments.delete', ['--team' => 'A']);
        $this->assertStringContainsString('no wildcard grant implies this name', $miss);
    }

    public function test_list_users_filters_catalogs_and_grants_independently_and_deduplicates_sources(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => false, 'permission.enable_wildcard_permission' => true]);
        $p = Permission::create(['name' => 'docs.*']);
        $unrelated = Permission::create(['name' => 'other']);
        $global = Role::create(['name' => 'global']);
        $global->givePermissionTo($p);
        $other = Role::create(['name' => 'unrelated']);
        $other->givePermissionTo($unrelated);
        $foreign = Role::create(['name' => 'foreign', 'team_id' => 'B', 'permission_ids' => [(string) $p->id]]);
        $local = Role::create(['name' => 'local', 'team_id' => 'A', 'permission_ids' => [(string) $p->id]]);
        $yes = TestUser::create(['name' => 'Included', 'email' => 'yes@test', 'role_ids' => [(string) $other->id, (string) $global->id, ['role_id' => (string) $local->id, 'team_id' => 'A']], 'permission_ids' => [(string) $p->id, (string) $p->id]]);
        TestUser::create(['name' => 'Unrelated', 'email' => 'no@test', 'role_ids' => [(string) $other->id]]);
        TestUser::create(['name' => 'Foreign', 'email' => 'foreign@test', 'role_ids' => [['role_id' => (string) $foreign->id, 'team_id' => 'A']]]);
        $this->assertSame(0, Artisan::call('permission:list-users', ['--permission' => 'docs.edit', '--team' => 'A']));
        $output = Artisan::output();
        $this->assertStringContainsString('<yes@test>', $output);
        $this->assertStringNotContainsString('<no@test>', $output);
        $this->assertStringNotContainsString('<foreign@test>', $output);
        $this->assertSame(1, substr_count($output, 'direct'));
        config(['permission.enable_wildcard_permission' => false]);
        $this->assertSame(1, Artisan::call('permission:list-users', ['--permission' => 'docs.edit', '--team' => 'A']));
        $this->assertStringContainsString('Permission "docs.edit" not found for guard "web".', Artisan::output());
        $this->assertSame(0, Artisan::call('permission:list-users', ['--permission' => 'docs.*', '--team' => 'A']));
        $this->assertStringContainsString('<yes@test>', Artisan::output());
    }

    public function test_show_matrix_contains_correct_cells_and_respects_guard_and_team_filters(): void
    {
        config(['permission.teams' => true]);
        setPermissionsTeamId('A');
        $a = Permission::create(['name' => 'allowed']);
        $b = Permission::create(['name' => 'denied']);
        $r = Role::create(['name' => 'reader']);
        $r->givePermissionTo($a);
        Role::create(['name' => 'foreign', 'team_id' => 'B']);
        Permission::create(['name' => 'api', 'guard_name' => 'api']);
        $this->assertSame(0, Artisan::call('permission:show', ['--team' => 'A']));
        $out = Artisan::output();
        $this->assertStringContainsString('Guard: web | Team: A', $out);
        $this->assertStringContainsString('Role: reader', $out);
        $this->assertStringContainsString('Permission: allowed', $out);
        $this->assertMatchesRegularExpression('/\| Role \/ Permission\s*\| allowed\s*\| denied\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\| reader\s*\| x\s*\|\s*\|/', $out);
        $this->assertStringNotContainsString('foreign', $out);
        $this->assertStringNotContainsString('api', $out);
        $this->assertSame(0, Artisan::call('permission:show'));
        $this->assertStringContainsString('Role: foreign', Artisan::output());
        $this->assertSame(0, Artisan::call('permission:cache-reset'));
        $this->assertStringContainsString('Permission cache flushed.', Artisan::output());
    }
}
