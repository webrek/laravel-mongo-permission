<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\SqlMigrationTestCase;

class MigrateFromSpatieCommandTest extends SqlMigrationTestCase
{
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
