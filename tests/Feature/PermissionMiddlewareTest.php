<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Webrek\MongoPermission\Exceptions\UnauthorizedException;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/edit', fn () => 'ok')->middleware('permission:edit articles');
    }

    public function test_authorized_user_passes(): void
    {
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);
        $user->givePermissionTo('edit articles');

        $this->actingAs($user->fresh())->get('/edit')->assertOk();
    }

    public function test_unauthorized_user_throws(): void
    {
        Permission::create(['name' => 'edit articles']);
        $user = TestUser::create(['name' => 'V']);

        $this->expectException(UnauthorizedException::class);
        $this->withoutExceptionHandling();
        $this->actingAs($user->fresh())->get('/edit');
    }
}
