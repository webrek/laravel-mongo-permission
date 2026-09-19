<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class DiagnosticScopeTest extends TestCase
{
    public function test_explicit_team_filter_works_even_when_application_team_scoping_is_off(): void
    {
        config(['permission.teams' => false, 'permission.strict_team_isolation' => false]);
        $p = Permission::create(['name' => 'p']);
        $u = TestUser::create(['name' => 'u', 'permission_ids' => [['permission_id' => (string) $p->id, 'team_id' => 'B']]]);
        $args = ['user_id' => (string) $u->id, 'permission' => 'p'];
        $this->assertSame(0, Artisan::call('permission:check', $args + ['--team' => 'A']));
        $this->assertStringContainsString('  NO', Artisan::output());
        $this->assertSame(0, Artisan::call('permission:check', $args));
        $output = Artisan::output();
        $this->assertStringContainsString('  YES', $output);
        $this->assertStringContainsString('User '.$u->id.' has "p" (guard web)?', $output);
    }

    public function test_role_list_skips_nonmatching_first_assignment_and_filters_local_catalogs(): void
    {
        $a = Role::create(['name' => 'same', 'team_id' => 'A']);
        $b = Role::create(['name' => 'same', 'team_id' => 'B']);
        TestUser::create(['name' => 'A', 'email' => 'a@test', 'role_ids' => [['role_id' => (string) $b->id, 'team_id' => 'A'], ['role_id' => (string) $a->id, 'team_id' => 'A']]]);
        TestUser::create(['name' => 'B', 'email' => 'b@test', 'role_ids' => [['role_id' => (string) $b->id, 'team_id' => 'A']]]);
        $this->assertSame(0, Artisan::call('permission:list-users', ['role' => 'same', '--team' => 'A']));
        $output = Artisan::output();
        $this->assertStringContainsString('<a@test>  team:A', $output);
        $this->assertStringNotContainsString('<b@test>', $output);
    }

    public function test_permission_list_scopes_local_catalogs_and_finds_direct_only_users(): void
    {
        $a = Permission::create(['name' => 'same', 'team_id' => 'A']);
        $b = Permission::create(['name' => 'same', 'team_id' => 'B']);
        TestUser::create(['name' => 'A', 'email' => 'a@test', 'permission_ids' => [['permission_id' => (string) $a->id, 'team_id' => 'A']]]);
        TestUser::create(['name' => 'B', 'email' => 'b@test', 'permission_ids' => [['permission_id' => (string) $b->id, 'team_id' => 'A']]]);
        $this->assertSame(0, Artisan::call('permission:list-users', ['--permission' => 'same', '--team' => 'A']));
        $output = Artisan::output();
        $this->assertStringContainsString('<a@test>', $output);
        $this->assertStringNotContainsString('<b@test>', $output);
    }
}
