<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Webrek\MongoPermission\Exceptions\UnauthorizedException;
use Webrek\MongoPermission\Middleware\PermissionMiddleware;
use Webrek\MongoPermission\Middleware\RoleMiddleware;
use Webrek\MongoPermission\Middleware\RoleOrPermissionMiddleware;
use Webrek\MongoPermission\Middleware\TeamContextMiddleware;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class MiddlewareContractTest extends TestCase
{
    public function test_guests_are_denied_before_downstream_execution(): void
    {
        foreach ([new RoleMiddleware, new PermissionMiddleware, new RoleOrPermissionMiddleware] as $middleware) {
            $request = Request::create('/');
            $request->setUserResolver(fn () => null);
            try {
                $middleware->handle($request, fn () => $this->fail('Guest reached controller'), 'read');
                $this->fail('Guest allowed');
            } catch (UnauthorizedException $e) {
                $this->assertSame(403, $e->getStatusCode());
                $this->assertSame('User is not logged in.', $e->getMessage());
            }
        }
    }

    public function test_denials_expose_http_status_required_rights_and_actionable_messages(): void
    {
        $cases = [
            [UnauthorizedException::forRoles(['editor', 'admin']), 'User does not have the right roles. Required: editor, admin', ['editor', 'admin'], []],
            [UnauthorizedException::forPermissions(['read', 'write']), 'User does not have the right permissions. Required: read, write', [], ['read', 'write']],
            [UnauthorizedException::forRolesOrPermissions(['editor', 'read']), 'User does not have any of the necessary access rights: editor, read', [], []],
            [UnauthorizedException::notLoggedIn(), 'User is not logged in.', [], []],
        ];
        foreach ($cases as [$e,$message,$roles,$permissions]) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($roles, $e->requiredRoles);
            $this->assertSame($permissions, $e->requiredPermissions);
        }
    }

    public function test_authorized_middleware_returns_the_exact_downstream_response(): void
    {
        $u = TestUser::create(['name' => 'middleware']);
        $p = Permission::create(['name' => 'read']);
        $r = Role::create(['name' => 'editor']);
        $u->givePermissionTo($p);
        $u->assignRole($r);
        $request = Request::create('/');
        $request->setUserResolver(function ($guard) use ($u) {
            $this->assertSame('custom', $guard);

            return $u;
        });
        foreach ([[new PermissionMiddleware, 'missing|read'], [new RoleMiddleware, 'missing|editor'], [new RoleOrPermissionMiddleware, 'editor'], [new RoleOrPermissionMiddleware, 'read']] as [$middleware,$value]) {
            $response = new Response('body', 202, ['X-Test' => 'preserved']);
            $calls = 0;
            $actual = $middleware->handle($request, function ($received) use ($request, $response, &$calls) {
                $this->assertSame($request, $received);
                $calls++;

                return $response;
            }, $value, 'custom');
            $this->assertSame($response, $actual);
            $this->assertSame(1, $calls);
        }
    }

    public function test_team_context_precedence_and_restoration_after_success_and_failure(): void
    {
        $registrar = app(PermissionRegistrar::class);
        config(['permission.team_resolver' => fn () => 'resolved']);
        foreach ([['route', 'input', 'header', 'route'], [null, 'input', 'header', 'input'], [null, null, 'header', 'header'], [null, null, null, 'resolved'], [null, 0, 'header', '0']] as [$routeTeam,$input,$header,$expected]) {
            $request = Request::create('/', 'GET', $input === null ? [] : ['tenant' => $input]);
            $route = new Route('GET', '/', fn () => null);
            $route->bind($request);
            if ($routeTeam !== null) {
                $route->setParameter('tenant', $routeTeam);
            }
            $request->setRouteResolver(fn () => $route);
            if ($header !== null) {
                $request->headers->set('X-Team-Id', $header);
            }
            $response = new Response('team');
            $this->assertSame($response, (new TeamContextMiddleware)->handle($request, function () use ($registrar, $expected, $response) {
                $this->assertSame($expected, $registrar->getTeamId());

                return $response;
            }, 'tenant'));
            $this->assertSame('resolved', $registrar->getTeamId());
        }
        $request = Request::create('/', 'GET', ['tenant' => 'temporary']);
        try {
            (new TeamContextMiddleware)->handle($request, fn () => throw new \RuntimeException('downstream'), 'tenant');
        } catch (\RuntimeException $e) {
            $this->assertSame('downstream', $e->getMessage());
        }
        $this->assertSame('resolved', $registrar->getTeamId());
    }
}
