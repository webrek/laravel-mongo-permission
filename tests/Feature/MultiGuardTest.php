<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class MultiGuardTest extends TestCase
{
    public function test_user_with_api_guard_property_resolves_api_role(): void
    {
        $user = new class extends TestUser {
            protected $table = 'test_users';
            protected string $guard_name = 'api';
        };
        $user->name = 'V';
        $user->save();

        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $user->assignRole('admin');

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    public function test_assign_role_uses_user_guard_for_lookup(): void
    {
        // create the same role name in two guards
        Role::create(['name' => 'shared', 'guard_name' => 'web']);
        Role::create(['name' => 'shared', 'guard_name' => 'api']);

        $apiUser = new class extends TestUser {
            protected $table = 'test_users';
            protected string $guard_name = 'api';
        };
        $apiUser->name = 'A';
        $apiUser->save();

        $apiUser->assignRole('shared');

        $assignedId = (string) Role::query()
            ->where('name', 'shared')
            ->where('guard_name', 'api')
            ->value('_id');

        $stored = collect($apiUser->fresh()->role_ids)
            ->pluck('role_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->assertContains($assignedId, $stored);
    }

    public function test_assigning_role_from_wrong_guard_throws_guard_does_not_match(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $webUser = TestUser::create(['name' => 'W']);

        $this->expectException(\Webrek\MongoPermission\Exceptions\GuardDoesNotMatch::class);

        $webUser->assignRole(\Webrek\MongoPermission\Models\Role::findByName('admin', 'api'));
    }

    public function test_giving_permission_from_wrong_guard_throws_guard_does_not_match(): void
    {
        Permission::create(['name' => 'p', 'guard_name' => 'api']);

        $webUser = TestUser::create(['name' => 'W']);

        $this->expectException(\Webrek\MongoPermission\Exceptions\GuardDoesNotMatch::class);

        $webUser->givePermissionTo(\Webrek\MongoPermission\Models\Permission::findByName('p', 'api'));
    }
}
