<?php

namespace Webrek\MongoPermission\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class SqlMigrationTestCase extends TestCase
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
}
