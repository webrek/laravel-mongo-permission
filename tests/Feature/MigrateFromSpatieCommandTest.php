<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class MigrateFromSpatieCommandTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.spatie_sql', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieSchema();
    }

    protected function seedSpatieSchema(): void
    {
        $sql = DB::connection('spatie_sql');
        $sql->statement('CREATE TABLE permissions (id INTEGER PRIMARY KEY, name VARCHAR, guard_name VARCHAR, team_id VARCHAR)');
        $sql->statement('CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR, guard_name VARCHAR, team_id VARCHAR)');
        $sql->statement('CREATE TABLE role_has_permissions (role_id INTEGER, permission_id INTEGER)');
        $sql->statement('CREATE TABLE model_has_roles (role_id INTEGER, model_type VARCHAR, model_id INTEGER, team_id VARCHAR)');
        $sql->statement('CREATE TABLE model_has_permissions (permission_id INTEGER, model_type VARCHAR, model_id INTEGER, team_id VARCHAR)');
        $sql->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name VARCHAR, email VARCHAR)');
    }

    protected function seedSampleData(): void
    {
        $sql = DB::connection('spatie_sql');

        $sql->table('permissions')->insert([
            ['id' => 1, 'name' => 'edit articles', 'guard_name' => 'web', 'team_id' => null],
            ['id' => 2, 'name' => 'delete articles', 'guard_name' => 'web', 'team_id' => null],
            ['id' => 3, 'name' => 'admin panel', 'guard_name' => 'api', 'team_id' => null],
        ]);
        $sql->table('roles')->insert([
            ['id' => 10, 'name' => 'editor', 'guard_name' => 'web', 'team_id' => null],
            ['id' => 11, 'name' => 'admin', 'guard_name' => 'web', 'team_id' => null],
        ]);
        $sql->table('role_has_permissions')->insert([
            ['role_id' => 10, 'permission_id' => 1],
            ['role_id' => 11, 'permission_id' => 1],
            ['role_id' => 11, 'permission_id' => 2],
        ]);
        $sql->table('users')->insert([
            ['id' => 100, 'name' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 101, 'name' => 'Bob', 'email' => 'bob@example.com'],
        ]);
        $sql->table('model_has_roles')->insert([
            ['role_id' => 10, 'model_type' => 'App\\Models\\User', 'model_id' => 100, 'team_id' => null],
            ['role_id' => 11, 'model_type' => 'App\\Models\\User', 'model_id' => 101, 'team_id' => null],
        ]);
        $sql->table('model_has_permissions')->insert([
            ['permission_id' => 2, 'model_type' => 'App\\Models\\User', 'model_id' => 100, 'team_id' => null],
        ]);
    }

    protected function runMigrate(array $opts = []): array
    {
        $exit = Artisan::call('permission:migrate-from-spatie', array_merge([
            '--connection' => 'spatie_sql',
        ], $opts));
        return [$exit, Artisan::output()];
    }

    public function test_creates_permissions_and_roles_in_mongo(): void
    {
        $this->seedSampleData();
        TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        TestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        [$exit, $output] = $this->runMigrate();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('3 created', $output); // permissions
        $this->assertStringContainsString('2 created', $output); // roles

        $this->assertSame(3, Permission::query()->count());
        $this->assertSame(2, Role::query()->count());
        $this->assertNotNull(Permission::query()->where('name', 'edit articles')->first());
        $this->assertNotNull(Role::query()->where('name', 'editor')->first());
    }

    public function test_attaches_permissions_to_roles(): void
    {
        $this->seedSampleData();

        $this->runMigrate(['--skip-users' => true]);

        $admin = Role::findByName('admin');
        $this->assertCount(2, $admin->permission_ids);

        $editor = Role::findByName('editor');
        $this->assertCount(1, $editor->permission_ids);
    }

    public function test_assigns_roles_and_permissions_to_mongo_users(): void
    {
        $this->seedSampleData();
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = TestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->runMigrate();

        $alice = $alice->fresh();
        $this->assertTrue($alice->hasRole('editor'));
        $this->assertTrue($alice->hasDirectPermission('delete articles'));

        $bob = $bob->fresh();
        $this->assertTrue($bob->hasRole('admin'));
        // admin role inherits edit articles + delete articles via role
        $this->assertTrue($bob->hasPermissionTo('edit articles'));
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->seedSampleData();
        TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        [$exit, $output] = $this->runMigrate(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dry run', $output);
        $this->assertSame(0, Permission::query()->count());
        $this->assertSame(0, Role::query()->count());
    }

    public function test_idempotent_second_run_skips_existing(): void
    {
        $this->seedSampleData();
        TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        TestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->runMigrate();
        $firstCount = Permission::query()->count();

        [$exit, $output] = $this->runMigrate();

        $this->assertSame(0, $exit);
        $this->assertSame($firstCount, Permission::query()->count());
        $this->assertStringContainsString('skipped', $output);
    }

    public function test_reports_unmapped_users(): void
    {
        $this->seedSampleData();
        // Only create Alice; Bob has spatie role but no Mongo user.
        TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        [$exit, $output] = $this->runMigrate();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 SQL user(s) could not be matched', $output);
    }

    public function test_skip_users_does_not_assign(): void
    {
        $this->seedSampleData();
        $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->runMigrate(['--skip-users' => true]);

        $this->assertSame(0, count($alice->fresh()->role_ids ?? []));
        $this->assertSame(0, count($alice->fresh()->permission_ids ?? []));
    }

    public function test_unreachable_sql_connection_fails(): void
    {
        [$exit, $output] = $this->runMigrate(['--connection' => 'nonexistent-conn']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Cannot reach SQL connection', $output);
    }
}
