<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CommandBoundaryTest extends TestCase
{
    public function test_indexes_cover_custom_catalogs_and_both_user_assignment_formats(): void
    {
        config(['permission.collection_names.roles' => 'custom_roles', 'permission.collection_names.permissions' => 'custom_permissions']);
        $this->assertSame(0, Artisan::call('permission:create-indexes'));
        $this->assertStringContainsString('Indexes created.', Artisan::output());
        $db = app('db')->connection('mongodb')->getMongoDB();
        foreach (['custom_roles', 'custom_permissions'] as $name) {
            $indexes = collect(iterator_to_array($db->selectCollection($name)->listIndexes()))->keyBy(fn ($i) => $i->getName());
            $this->assertTrue($indexes['uniq_name_guard_team']->isUnique());
            $this->assertSame(['name' => 1, 'guard_name' => 1, 'team_id' => 1], (array) $indexes['uniq_name_guard_team']->getKey());
        }
        $indexes = collect(iterator_to_array($db->selectCollection((new TestUser)->getTable())->listIndexes()))->keyBy(fn ($i) => $i->getName());
        foreach (['role_ids', 'role_ids.role_id', 'permission_ids', 'permission_ids.permission_id'] as $field) {
            $this->assertSame([$field => 1], (array) $indexes['idx_'.str_replace('.', '_', $field)]->getKey());
        }
        $this->assertSame(0, Artisan::call('permission:create-indexes'));
    }

    public function test_catalog_commands_honor_explicit_guard_and_do_not_warn_for_literal_permissions(): void
    {
        $this->assertSame(0, Artisan::call('permission:create-permission', ['name' => 'reports.read', '--guard' => 'api']));
        $this->assertStringContainsString('Permission "reports.read" created for guard "api".', Artisan::output());
        $this->assertStringNotContainsString('Heads up', Artisan::output());
        $this->assertSame(0, Artisan::call('permission:create-role', ['name' => 'reporter', '--guard' => 'api', 'permissions' => ['reports.read']]));
        $this->assertStringContainsString('Role "reporter" created for guard "api".', Artisan::output());
        $role = Role::findByName('reporter', 'api');
        $this->assertSame([(string) Permission::findByName('reports.read', 'api')->id], $role->permission_ids);
        foreach (['*', 'posts.*', 'a.*.b'] as $name) {
            $this->assertSame(0, Artisan::call('permission:create-permission', ['name' => $name]));
            $this->assertStringContainsString('Heads up', Artisan::output());
        }
    }

    public function test_invalid_models_fail_cleanly_for_diagnostic_and_maintenance_commands(): void
    {
        foreach ([null, 'MissingUserClass'] as $model) {
            config(['auth.providers.users.model' => $model]);
            foreach (['permission:check' => ['user_id' => 'missing', 'permission' => 'read'], 'permission:list-users' => ['role' => 'reader'], 'permission:prune-expired' => []] as $command => $args) {
                $this->assertSame(1, Artisan::call($command, $args));
                $this->assertStringContainsString('Could not resolve a user model', Artisan::output());
            }
        }
    }

    public function test_list_users_filters_teams_guards_expiry_and_duplicate_sources(): void
    {
        $this->freezeTime();
        $p = Permission::create(['name' => 'read']);
        $role = Role::create(['name' => 'reader']);
        $role->givePermissionTo($p);
        $api = Role::create(['name' => 'reader', 'guard_name' => 'api']);
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        setPermissionsTeamId('alpha');
        $a = TestUser::create(['name' => 'Alpha', 'email' => 'alpha@test']);
        $a->assignRole($role)->givePermissionTo($p);
        setPermissionsTeamId('beta');
        $b = TestUser::create(['name' => 'Beta', 'email' => 'beta@test']);
        $b->assignRole($role)->givePermissionTo($p);
        $expired = TestUser::create(['name' => 'Expired', 'email' => 'expired@test']);
        $expired->assignRoleUntil($role, now()->subSecond());
        foreach ([['role' => 'reader'], ['--permission' => 'read']] as $args) {
            $this->assertSame(0, Artisan::call('permission:list-users', $args + ['--team' => 'alpha']));
            $out = Artisan::output();
            $this->assertStringContainsString('1 user(s)', $out);
            $this->assertStringContainsString('alpha@test', $out);
            $this->assertStringNotContainsString('beta@test', $out);
            $this->assertStringNotContainsString('expired@test', $out);
        }
        $this->assertSame(0, Artisan::call('permission:list-users', ['role' => 'reader', '--guard' => 'api']));
        $this->assertStringContainsString('0 user(s)', Artisan::output());
        foreach ([[], ['role' => 'reader', '--permission' => 'read'], ['role' => 'missing'], ['--permission' => 'missing']] as $args) {
            $this->assertSame(1, Artisan::call('permission:list-users', $args));
        }
    }

    public function test_pruning_counts_each_kind_and_preserves_other_grants_in_dry_run_and_real_run(): void
    {
        $this->freezeTime();
        $p = Permission::create(['name' => 'read']);
        $q = Permission::create(['name' => 'write']);
        $role = Role::create(['name' => 'reader']);
        $user = TestUser::create(['name' => 'Reader']);
        $user->assignRoleUntil($role, now()->subSecond());
        $user->givePermissionToUntil($p, now()->subSecond());
        $user->givePermissionTo($q);
        $untouched = TestUser::create(['name' => 'Untouched']);
        $untouched->assignRole($role);
        $before = $user->fresh()->getAttributes();
        $this->assertSame(0, Artisan::call('permission:prune-expired', ['--dry-run' => true]));
        $this->assertStringContainsString('Dry run: would prune 1 role grant(s) and 1 permission grant(s) across 1 user(s).', Artisan::output());
        $this->assertEquals($before, $user->fresh()->getAttributes());
        $this->assertSame(0, Artisan::call('permission:prune-expired'));
        $this->assertStringContainsString('Pruned 1 role grant(s) and 1 permission grant(s) across 1 user(s).', Artisan::output());
        $this->assertEmpty($user->fresh()->role_ids);
        $this->assertSame(['write'], $user->fresh()->getPermissionNames()->all());
        $this->assertTrue($untouched->fresh()->hasRole($role));
        $this->assertSame(0, Artisan::call('permission:prune-expired'));
        $this->assertStringContainsString('across 0 user(s)', Artisan::output());
    }
}
