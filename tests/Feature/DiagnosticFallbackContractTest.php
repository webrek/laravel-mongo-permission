<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class DiagnosticFallbackContractTest extends TestCase
{
    public function test_unscoped_diagnostics_include_team_catalogs(): void
    {
        config(['permission.teams' => false]);
        $p = Permission::create(['name' => 'p', 'team_id' => 'B']);
        $r = Role::create(['name' => 'r', 'team_id' => 'B', 'permission_ids' => [$p->id]]);
        $u = TestUser::create(['name' => 'u', 'email' => 'unscoped@test', 'role_ids' => [$r->id]]);
        $this->assertSame(0, Artisan::call('permission:check', ['user_id' => $u->id, 'permission' => 'p']));
        $this->assertStringContainsString('  YES', Artisan::output());
        $this->assertSame(0, Artisan::call('permission:list-users', ['--permission' => 'p']));
        $this->assertStringContainsString('<unscoped@test>', Artisan::output());
    }

    public function test_global_catalog_role_is_available_to_current_team_but_foreign_assignments_are_not(): void
    {
        config(['permission.teams' => true, 'permission.strict_team_isolation' => false]);
        setPermissionsTeamId(null);
        $p = Permission::create(['name' => 'p']);
        $r = Role::create(['name' => 'r', 'permission_ids' => [$p->id]]);
        TestUser::create(['name' => 'yes', 'email' => 'yes@test', 'role_ids' => [['role_id' => $r->id, 'team_id' => 'A']]]);
        TestUser::create(['name' => 'no', 'email' => 'no@test', 'role_ids' => [['role_id' => $r->id, 'team_id' => 'B']]]);
        $this->assertSame(0, Artisan::call('permission:list-users', ['--permission' => 'p', '--team' => 'A']));
        $output = Artisan::output();
        $this->assertStringContainsString('<yes@test>', $output);
        $this->assertStringNotContainsString('<no@test>', $output);
    }

    public function test_missing_wildcard_flag_does_not_grant_an_existing_literal_permission(): void
    {
        $config = config('permission');
        Arr::forget($config, 'enable_wildcard_permission');
        config(['permission' => $config]);
        Permission::create(['name' => 'docs.write']);
        $wildcard = Permission::create(['name' => 'docs.*']);
        $u = TestUser::create(['name' => 'u']);
        $u->givePermissionTo($wildcard);
        $this->assertSame(0, Artisan::call('permission:check', ['user_id' => $u->id, 'permission' => 'docs.write']));
        $this->assertStringContainsString('  NO', Artisan::output());
    }
}
