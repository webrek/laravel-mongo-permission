<?php

namespace Webrek\MongoPermission\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webrek\MongoPermission\Exceptions\UnauthorizedException;

class RoleOrPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $rolesOrPermissions, ?string $guard = null)
    {
        $user = $request->user($guard);
        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        $values = is_array($rolesOrPermissions) ? $rolesOrPermissions : explode('|', $rolesOrPermissions);

        if (method_exists($user, 'hasAnyRole')) {
            try {
                if ($user->hasAnyRole(...$values)) {
                    return $next($request);
                }
            } catch (\Webrek\MongoPermission\Exceptions\RoleDoesNotExist) {
                // Unknown role names treated as "user does not have it"
            }
        }

        if (method_exists($user, 'hasAnyPermission')) {
            try {
                if ($user->hasAnyPermission(...$values)) {
                    return $next($request);
                }
            } catch (\Webrek\MongoPermission\Exceptions\PermissionDoesNotExist) {
                // Unknown names treated as "user does not have it"
            }
        }

        throw UnauthorizedException::forRolesOrPermissions($values);
    }
}
