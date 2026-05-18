<?php

namespace Webrek\MongoPermission\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webrek\MongoPermission\PermissionRegistrar;

class TeamContextMiddleware
{
    public function handle(Request $request, Closure $next, string $parameter = 'team')
    {
        $teamId = $request->route($parameter)
            ?? $request->input($parameter)
            ?? $request->header('X-Team-Id');

        if ($teamId !== null) {
            app(PermissionRegistrar::class)->setTeamId((string) $teamId);
        }

        return $next($request);
    }
}
