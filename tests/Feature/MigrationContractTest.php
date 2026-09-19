<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\SqlMigrationTestCase;

class MigrationContractTest extends SqlMigrationTestCase
{
    public function test_dry_run_reports_complete_counts_without_catalog_or_cache_changes(): void
    {
        $this->seedSampleData();
        TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        TestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $r = app(PermissionRegistrar::class);
        $version = $r->cacheVersion();
        [$exit,$out] = $this->runMigrate(['--dry-run' => true]);
        $this->assertSame(0, $exit);
        foreach (['Reading spatie tables from connection "spatie_sql" (dry run).', 'Migration summary', 'Permissions: 3 created', 'Roles:       2 created', 'Role->permission edges resolved: 3', 'User role assignments:       2', 'User permission assignments: 1', 'Dry run — no documents were written.'] as $line) {
            $this->assertStringContainsString($line, $out);
        }
        $this->assertSame(0, Role::count());
        $this->assertSame(0, Permission::count());
        $this->assertSame($version, $r->cacheVersion());
        $this->assertStringNotContainsString('could not be matched', $out);
    }

    public function test_import_counts_multiple_users_and_invalid_earlier_rows_do_not_abort_valid_rows(): void
    {
        $this->seedSampleData();
        $sql = DB::connection('spatie_sql');
        $sql->table('role_has_permissions')->delete();
        $sql->table('role_has_permissions')->insert([['role_id' => 999, 'permission_id' => 1], ['role_id' => 10, 'permission_id' => 1]]);
        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $table => $key) {
            $sql->table($table)->delete();
            $id = $key === 'role_id' ? 10 : 2;
            $sql->table($table)->insert([
                [$key => $id, 'model_type' => 'other', 'model_id' => 100, 'team_id' => null],
                [$key => 999, 'model_type' => 'App\\Models\\User', 'model_id' => 100, 'team_id' => null],
                [$key => $id, 'model_type' => 'App\\Models\\User', 'model_id' => 100, 'team_id' => null],
                [$key => $id, 'model_type' => 'App\\Models\\User', 'model_id' => 101, 'team_id' => null],
            ]);
        }
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = TestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        [$exit,$out] = $this->runMigrate();
        $this->assertSame(0, $exit);
        foreach ([$alice, $bob] as $u) {
            $this->assertTrue($u->hasRole('editor'));
            $this->assertTrue($u->hasPermissionTo('edit articles'));
            $this->assertTrue($u->fresh()->hasDirectPermission('delete articles'));
        }
        foreach (['Permissions: 3 created', 'Roles:       2 created', 'User role assignments:       2', 'User permission assignments: 2'] as $line) {
            $this->assertStringContainsString($line, $out);
        }
        $this->assertStringNotContainsString('(dry run)', $out);
    }

    public function test_new_user_assignments_invalidate_warm_cache_but_dry_runs_and_repeats_do_not(): void
    {
        $this->seedSampleData();
        $this->runMigrate(['--skip-users' => true]);
        $u = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $r = new PermissionRegistrar;
        $this->assertSame([], $r->getUserPermissionSlugs($u));
        $key = $r->cacheKey((string) $u->id, null, 'permissions');
        $this->runMigrate(['--dry-run' => true]);
        $this->assertSame($key, $r->cacheKey((string) $u->id, null, 'permissions'));
        $this->runMigrate();
        $this->assertNotSame($key, $r->cacheKey((string) $u->id, null, 'permissions'));
        $this->assertEqualsCanonicalizing(['edit articles', 'delete articles'], $r->getUserPermissionSlugs($u));
        $key = $r->cacheKey((string) $u->id, null, 'permissions');
        $this->runMigrate();
        $this->assertSame($key, $r->cacheKey((string) $u->id, null, 'permissions'));
    }

    public function test_role_edge_import_merges_deduplicates_and_invalidates_existing_reader(): void
    {
        $this->seedSampleData();
        $this->runMigrate(['--skip-users' => true]);
        $role = Role::findByName('editor');
        $extra = Permission::create(['name' => 'extra']);
        $role->givePermissionTo($extra);
        $u = TestUser::create(['name' => 'u']);
        $u->assignRole($role);
        $reader = new PermissionRegistrar;
        $this->assertEqualsCanonicalizing(['edit articles', 'extra'], $reader->getUserPermissionSlugs($u));
        $sql = DB::connection('spatie_sql');
        $sql->table('role_has_permissions')->insert(['role_id' => 10, 'permission_id' => 2]);
        $this->runMigrate(['--skip-users' => true]);
        $this->assertEqualsCanonicalizing(['edit articles', 'extra', 'delete articles'], $reader->getUserPermissionSlugs($u));
        $this->assertCount(3, $role->fresh()->permission_ids);
        $this->runMigrate(['--skip-users' => true]);
        $this->assertCount(3, $role->fresh()->permission_ids);
    }

    public function test_guard_specific_catalogs_are_preserved_across_repeated_imports(): void
    {
        $this->seedSampleData();
        $sql = DB::connection('spatie_sql');
        $sql->table('roles')->insert(['id' => 12, 'name' => 'editor', 'guard_name' => 'api', 'team_id' => null]);
        $sql->table('role_has_permissions')->insert(['role_id' => 12, 'permission_id' => 3]);
        $this->runMigrate(['--skip-users' => true]);
        $this->runMigrate(['--skip-users' => true]);
        $this->assertSame(3, Role::count());
        $this->assertSame([(string) Permission::findByName('admin panel', 'api')->id], Role::findByName('editor', 'api')->permission_ids);
        $this->assertSame([(string) Permission::findByName('edit articles', 'web')->id], Role::findByName('editor', 'web')->permission_ids);
    }

    public function test_invalid_connection_and_user_model_have_actionable_diagnostics(): void
    {
        [$exit,$out] = $this->runMigrate(['--connection' => 'not-configured']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Cannot reach SQL connection "not-configured"', $out);
        $this->seedSampleData();
        foreach ([null, 'NonexistentUserModel'] as $model) {
            config(['auth.providers.users.model' => $model]);
            [$exit,$out] = $this->runMigrate();
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('No Mongo user model configured. Skipping user assignments.', $out);
        }
    }
}
