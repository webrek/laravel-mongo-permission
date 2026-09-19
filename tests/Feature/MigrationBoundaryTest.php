<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\SqlMigrationTestCase;

class MigrationBoundaryTest extends SqlMigrationTestCase
{
    public function test_numeric_sql_team_ids_remain_usable_and_import_is_idempotent(): void
    {
        $sql = DB::connection('spatie_sql');
        foreach (['permissions', 'roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            $definition = $sql->selectOne('SELECT sql FROM sqlite_master WHERE name = ?', [$table])->sql;
            $sql->statement('DROP TABLE '.$table);
            $sql->statement(str_replace('team_id VARCHAR', 'team_id INTEGER', $definition));
        }
        $this->seedSampleData();
        foreach (['permissions', 'roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            $sql->table($table)->update(['team_id' => 0]);
        }
        config(['permission.teams' => true, 'permission.strict_team_isolation' => true]);
        setPermissionsTeamId('0');
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->runMigrate();
        $this->assertTrue($alice->fresh()->hasRole('editor'));
        $this->assertTrue($alice->fresh()->hasPermissionTo('edit articles'));
        $this->assertTrue($alice->fresh()->hasDirectPermission('delete articles'));
        $this->runMigrate();
        $this->assertSame(2, Role::count());
        $this->assertSame(3, Permission::count());
        $this->assertCount(1, $alice->fresh()->role_ids);
        $this->assertCount(1, $alice->fresh()->permission_ids);
        $inherited = Permission::findByName('edit articles');
        $direct = Permission::findByName('delete articles');
        setPermissionsTeamId('1');
        $this->assertFalse($alice->fresh()->hasRole('editor'));
        $this->assertFalse($alice->fresh()->hasPermissionTo($inherited));
        $this->assertFalse($alice->fresh()->hasDirectPermission($direct));
    }

    public function test_other_polymorphic_model_cannot_grant_access_to_same_numbered_user(): void
    {
        $this->seedSampleData();
        $sql = DB::connection('spatie_sql');
        $sql->table('model_has_roles')->where('model_id', 100)->update(['model_type' => 'App\\Models\\ServiceAccount']);
        $sql->table('model_has_permissions')->where('model_id', 100)->update(['model_type' => 'App\\Models\\ServiceAccount']);
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->runMigrate();
        $this->assertFalse($alice->fresh()->hasRole('editor'));
        $this->assertFalse($alice->fresh()->hasDirectPermission('delete articles'));
    }

    public function test_migration_preserves_same_id_grants_in_distinct_teams_and_is_idempotent(): void
    {
        $this->seedSampleData();
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->runMigrate();
        $sql = DB::connection('spatie_sql');
        $sql->table('model_has_roles')->insert(['role_id' => 10, 'model_type' => 'App\\Models\\User', 'model_id' => 100, 'team_id' => 'alpha']);
        $sql->table('model_has_permissions')->insert(['permission_id' => 2, 'model_type' => 'App\\Models\\User', 'model_id' => 100, 'team_id' => 'alpha']);
        $this->runMigrate();
        $this->assertCount(2, $alice->fresh()->role_ids);
        $this->assertCount(2, $alice->fresh()->permission_ids);
        [, $output] = $this->runMigrate();
        $this->assertCount(2, $alice->fresh()->role_ids);
        $this->assertCount(2, $alice->fresh()->permission_ids);
        $this->assertStringContainsString('Permissions: 0 created, 3 skipped', $output);
        $this->assertStringContainsString('Roles:       0 created, 2 skipped', $output);
        $this->assertStringContainsString('User role assignments:       0', $output);
        $this->assertStringContainsString('User permission assignments: 0', $output);
    }

    public function test_duplicate_sql_edges_are_deduplicated_in_a_single_run(): void
    {
        $this->seedSampleData();
        $sql = DB::connection('spatie_sql');
        $sql->table('model_has_roles')->insert(['role_id' => 10, 'model_type' => 'App\\Models\\User', 'model_id' => 100, 'team_id' => null]);
        $sql->table('model_has_permissions')->insert(['permission_id' => 2, 'model_type' => 'App\\Models\\User', 'model_id' => 100, 'team_id' => null]);
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->runMigrate();
        $this->assertCount(1, $alice->fresh()->role_ids);
        $this->assertCount(1, $alice->fresh()->permission_ids);
    }

    public function test_dry_run_preserves_existing_user_and_catalog_documents(): void
    {
        $this->seedSampleData();
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->runMigrate();
        $before = $alice->fresh()->getAttributes();
        $role = Role::findByName('editor')->getAttributes();
        $permission = Permission::findByName('edit articles')->getAttributes();
        $this->runMigrate(['--dry-run' => true, '--force' => true]);
        $this->assertEquals($before, $alice->fresh()->getAttributes());
        $this->assertEquals($role, Role::findByName('editor')->getAttributes());
        $this->assertEquals($permission, Permission::findByName('edit articles')->getAttributes());
    }

    public function test_custom_source_alias_and_matching_field_are_respected(): void
    {
        $this->seedSampleData();
        $sql = DB::connection('spatie_sql');
        $sql->table('model_has_roles')->where('model_id', 100)->update(['model_type' => 'customer']);
        $sql->table('model_has_permissions')->where('model_id', 100)->update(['model_type' => 'customer']);
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'different@example.com']);
        $bob = TestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        [$exit] = $this->runMigrate(['--source-model' => 'customer', '--match-by' => 'name']);
        $this->assertSame(0, $exit);
        $this->assertTrue($alice->fresh()->hasRole('editor'));
        $this->assertTrue($alice->fresh()->hasDirectPermission('delete articles'));
        $this->assertEmpty($bob->fresh()->role_ids ?? []);
    }

    public function test_force_replaces_role_permissions_including_an_empty_source_role(): void
    {
        $this->seedSampleData();
        $this->runMigrate(['--skip-users' => true]);
        $extra = Permission::create(['name' => 'extra']);
        Role::findByName('editor')->givePermissionTo($extra);
        DB::connection('spatie_sql')->table('role_has_permissions')->where('role_id', 11)->delete();
        [$exit, $output] = $this->runMigrate(['--skip-users' => true, '--force' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame([(string) Permission::findByName('edit articles')->id], Role::findByName('editor')->permission_ids);
        $this->assertEmpty(Role::findByName('admin')->permission_ids);
        $this->assertStringContainsString('Permissions: 0 created, 0 skipped (already present), 3 overwritten', $output);
        $this->assertStringContainsString('Roles:       0 created, 0 skipped, 2 overwritten', $output);
    }

    public function test_legacy_flat_grants_survive_repeated_import(): void
    {
        $this->seedSampleData();
        $this->runMigrate(['--skip-users' => true]);
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com',
            'role_ids' => [(string) Role::findByName('editor')->id],
            'permission_ids' => [(string) Permission::findByName('delete articles')->id]]);
        $before = $alice->fresh()->getAttributes();
        [, $output] = $this->runMigrate();
        $this->assertEquals($before, $alice->fresh()->getAttributes());
        $this->assertStringContainsString('User role assignments:       0', $output);
        $this->assertStringContainsString('User permission assignments: 0', $output);
    }

    public function test_missing_catalog_edges_are_skipped_and_dry_run_counts_only_new_grants(): void
    {
        $this->seedSampleData();
        $sql = DB::connection('spatie_sql');
        $sql->table('role_has_permissions')->insert(['role_id' => 999, 'permission_id' => 1]);
        $sql->table('role_has_permissions')->insert(['role_id' => 10, 'permission_id' => 999]);
        $sql->table('model_has_roles')->insert(['role_id' => 999, 'model_type' => 'App\\Models\\User', 'model_id' => 100, 'team_id' => null]);
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->runMigrate();
        [, $output] = $this->runMigrate(['--dry-run' => true]);
        $this->assertCount(1, $alice->fresh()->role_ids);
        $this->assertStringContainsString('Role->permission edges resolved: 3', $output);
        $this->assertStringContainsString('User role assignments:       0', $output);
        $this->assertStringContainsString('User permission assignments: 0', $output);
    }

    public function test_imported_global_catalog_is_independent_of_current_team_and_restores_context(): void
    {
        $this->seedSampleData();
        config(['permission.teams' => true]);
        setPermissionsTeamId('unrelated');
        $this->runMigrate(['--skip-users' => true]);
        $this->assertNull(Permission::query()->where('name', 'edit articles')->firstOrFail()->team_id);
        $this->assertNull(Role::query()->where('name', 'editor')->firstOrFail()->team_id);
        $this->assertSame('unrelated', app(PermissionRegistrar::class)->getTeamId());
    }
}
