<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Webrek\MongoPermission\Exceptions\UnauthorizedException;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/admin', fn () => 'ok')->middleware('role:admin');
        Route::get('/admin-or-editor', fn () => 'ok')->middleware('role:admin|editor');
    }

    public function test_authorized_user_passes(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);
        $user->assignRole('admin');

        $response = $this->actingAs($user->fresh())->get('/admin');
        $response->assertOk();
        $this->assertSame('ok', $response->getContent());
    }

    public function test_unauthorized_user_throws(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);

        $this->expectException(UnauthorizedException::class);

        $this->withoutExceptionHandling();
        $this->actingAs($user->fresh())->get('/admin');
    }

    public function test_user_with_any_of_multiple_roles_passes(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'editor']);
        $user->assignRole('editor');

        $this->actingAs($user->fresh())->get('/admin-or-editor')->assertOk();
    }
}
