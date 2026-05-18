<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class GateIntegrationTest extends TestCase
{
    public function test_user_can_returns_true_for_held_permission(): void
    {
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('edit articles');

        $this->assertTrue($user->fresh()->can('edit articles'));
    }

    public function test_gate_allows_returns_true_for_held_permission(): void
    {
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('edit articles');

        $this->assertTrue(Gate::forUser($user->fresh())->allows('edit articles'));
    }

    public function test_gate_returns_false_when_permission_lacking(): void
    {
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);

        $this->assertFalse(Gate::forUser($user->fresh())->allows('edit articles'));
    }

    public function test_unknown_permission_falls_through_to_policy(): void
    {
        // No Permission row for 'something-policy-handles'; Gate should fall through.
        Gate::define('something-policy-handles', fn ($user) => true);

        $user = TestUser::create(['name' => 'V']);

        $this->assertTrue(Gate::forUser($user->fresh())->allows('something-policy-handles'));
    }
}
