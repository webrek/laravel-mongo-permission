<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Carbon;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class TtlGrantsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_assignRoleUntil_holds_before_expiry(): void
    {
        Role::create(['name' => 'admin']);
        $user = TestUser::create(['name' => 'V']);

        $user->assignRoleUntil('admin', now()->addHour());

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    public function test_assignRoleUntil_expires_after_ttl(): void
    {
        Role::create(['name' => 'admin']);
        $user = TestUser::create(['name' => 'V']);

        $user->assignRoleUntil('admin', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_expired_role_does_not_grant_permissions(): void
    {
        Permission::create(['name' => 'edit articles']);
        $role = Role::create(['name' => 'editor']);
        $role->givePermissionTo('edit articles');
        $user = TestUser::create(['name' => 'V']);

        $user->assignRoleUntil('editor', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));
        $this->assertFalse($user->fresh()->hasPermissionTo('edit articles'));
    }

    public function test_givePermissionToUntil_expires(): void
    {
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);

        $user->givePermissionToUntil('edit articles', now()->addHour());

        $this->assertTrue($user->fresh()->hasPermissionTo('edit articles'));

        Carbon::setTestNow(now()->addHours(2));
        $this->assertFalse($user->fresh()->hasPermissionTo('edit articles'));
    }

    public function test_hasDirectPermission_filters_expired(): void
    {
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);

        $user->givePermissionToUntil('edit articles', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));
        $this->assertFalse($user->fresh()->hasDirectPermission('edit articles'));
    }

    public function test_getRoleNames_excludes_expired(): void
    {
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'editor']);
        $user = TestUser::create(['name' => 'V']);

        $user->assignRole('admin');
        $user->assignRoleUntil('editor', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));
        $names = $user->fresh()->getRoleNames()->all();

        $this->assertContains('admin', $names);
        $this->assertNotContains('editor', $names);
    }

    public function test_permissions_accessor_excludes_expired(): void
    {
        Permission::create(['name' => 'edit articles']);
        Permission::create(['name' => 'delete articles']);
        $user = TestUser::create(['name' => 'V']);

        $user->givePermissionTo('edit articles');
        $user->givePermissionToUntil('delete articles', now()->addHour());

        Carbon::setTestNow(now()->addHours(2));
        $names = $user->fresh()->permissions()->pluck('name')->all();

        $this->assertContains('edit articles', $names);
        $this->assertNotContains('delete articles', $names);
    }

    public function test_grant_without_expiry_never_expires(): void
    {
        Role::create(['name' => 'admin']);
        $user = TestUser::create(['name' => 'V']);

        $user->assignRole('admin');

        Carbon::setTestNow(now()->addYears(5));
        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    public function test_assignRoleUntil_with_role_instance(): void
    {
        $role = Role::create(['name' => 'admin']);
        $user = TestUser::create(['name' => 'V']);

        $user->assignRoleUntil($role, now()->addHour());
        $this->assertTrue($user->fresh()->hasRole('admin'));

        Carbon::setTestNow(now()->addHours(2));
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }
}
