<?php

namespace Webrek\MongoPermission\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webrek\MongoPermission\Exceptions\UnauthorizedException;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission, ?string $guard = null)
    {
        $user = $request->user($guard);
        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        $permissions = is_array($permission) ? $permission : explode('|', $permission);

        if (! method_exists($user, 'hasAnyPermission') || ! $user->hasAnyPermission(...$permissions)) {
            throw UnauthorizedException::forPermissions($permissions);
        }

        return $next($request);
    }
}
