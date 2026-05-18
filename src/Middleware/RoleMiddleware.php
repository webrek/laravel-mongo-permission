<?php

namespace Webrek\MongoPermission\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webrek\MongoPermission\Exceptions\UnauthorizedException;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role, ?string $guard = null)
    {
        $user = $request->user($guard);
        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        $roles = explode('|', $role);

        if (! method_exists($user, 'hasAnyRole') || ! $user->hasAnyRole(...$roles)) {
            throw UnauthorizedException::forRoles($roles);
        }

        return $next($request);
    }
}
