<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Webrek\MongoPermission\Exceptions\UnauthorizedException;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class RoleOrPermissionMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/x', fn () => 'ok')->middleware('role_or_permission:admin|edit articles');
    }

    public function test_user_with_role_passes(): void
    {
        Role::create(['name' => 'admin']);
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('admin');

        $this->actingAs($user->fresh())->get('/x')->assertOk();
    }

    public function test_user_with_permission_passes(): void
    {
        Role::create(['name' => 'admin']);
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('edit articles');

        $this->actingAs($user->fresh())->get('/x')->assertOk();
    }

    public function test_user_with_neither_throws(): void
    {
        Role::create(['name' => 'admin']);
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);

        $this->expectException(UnauthorizedException::class);
        $this->withoutExceptionHandling();
        $this->actingAs($user->fresh())->get('/x');
    }
}
