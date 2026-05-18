<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class TeamContextMiddlewareTest extends TestCase
{
    public function test_team_context_sets_active_team_before_role_check(): void
    {
        Route::get('/teams/{team}/admin', fn () => 'ok')
            ->middleware(['team-context:team', 'role:admin']);

        config()->set('permission.teams', true);

        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);

        // Assign role only in team 'A'
        app(PermissionRegistrar::class)->setTeamId('A');
        $user->assignRole('admin');
        app(PermissionRegistrar::class)->forgetTeamId();

        // Hitting /teams/A/admin should succeed
        $this->actingAs($user->fresh())->get('/teams/A/admin')->assertOk();
    }

    public function test_request_to_wrong_team_fails(): void
    {
        Route::get('/teams/{team}/admin', fn () => 'ok')
            ->middleware(['team-context:team', 'role:admin']);

        config()->set('permission.teams', true);

        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);

        app(PermissionRegistrar::class)->setTeamId('A');
        $user->assignRole('admin');
        app(PermissionRegistrar::class)->forgetTeamId();

        $this->expectException(\Webrek\MongoPermission\Exceptions\UnauthorizedException::class);
        $this->withoutExceptionHandling();
        $this->actingAs($user->fresh())->get('/teams/B/admin');
    }
}
