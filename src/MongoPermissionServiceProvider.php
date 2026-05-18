<?php

namespace Webrek\MongoPermission;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Webrek\MongoPermission\PermissionRegistrar;

class MongoPermissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/permission.php', 'permission');

        $this->app->singleton(PermissionRegistrar::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/permission.php' => config_path('permission.php'),
            ], 'permission-config');

            $this->commands([
                \Webrek\MongoPermission\Commands\CreateIndexes::class,
                \Webrek\MongoPermission\Commands\CacheReset::class,
                \Webrek\MongoPermission\Commands\CreateRole::class,
                \Webrek\MongoPermission\Commands\CreatePermission::class,
                \Webrek\MongoPermission\Commands\Show::class,
                \Webrek\MongoPermission\Commands\PruneExpired::class,
            ]);
        }

        $events = $this->app['events'];
        $events->listen(\Webrek\MongoPermission\Events\RoleAttached::class, [\Webrek\MongoPermission\Listeners\RefreshUserCacheListener::class, 'onRoleAttached']);
        $events->listen(\Webrek\MongoPermission\Events\RoleDetached::class, [\Webrek\MongoPermission\Listeners\RefreshUserCacheListener::class, 'onRoleDetached']);
        $events->listen(\Webrek\MongoPermission\Events\PermissionAttached::class, [\Webrek\MongoPermission\Listeners\RefreshUserCacheListener::class, 'onPermissionAttached']);
        $events->listen(\Webrek\MongoPermission\Events\PermissionDetached::class, [\Webrek\MongoPermission\Listeners\RefreshUserCacheListener::class, 'onPermissionDetached']);

        $router = $this->app['router'];
        $router->aliasMiddleware('role', \Webrek\MongoPermission\Middleware\RoleMiddleware::class);
        $router->aliasMiddleware('permission', \Webrek\MongoPermission\Middleware\PermissionMiddleware::class);
        $router->aliasMiddleware('role_or_permission', \Webrek\MongoPermission\Middleware\RoleOrPermissionMiddleware::class);
        $router->aliasMiddleware('team-context', \Webrek\MongoPermission\Middleware\TeamContextMiddleware::class);

        $blade = $this->app['blade.compiler'];

        $blade->directive('role', function ($expression) {
            return "<?php if (auth()->check() && method_exists(auth()->user(), 'hasRole') && auth()->user()->hasRole($expression)): ?>";
        });
        $blade->directive('elserole', function ($expression) {
            return "<?php elseif (auth()->check() && method_exists(auth()->user(), 'hasRole') && auth()->user()->hasRole($expression)): ?>";
        });
        $blade->directive('endrole', fn () => '<?php endif; ?>');

        $blade->directive('hasrole', function ($expression) {
            return "<?php if (auth()->check() && method_exists(auth()->user(), 'hasRole') && auth()->user()->hasRole($expression)): ?>";
        });
        $blade->directive('endhasrole', fn () => '<?php endif; ?>');

        $blade->directive('hasanyrole', function ($expression) {
            return "<?php if (auth()->check() && method_exists(auth()->user(), 'hasRole') && auth()->user()->hasRole(explode('|', $expression))): ?>";
        });
        $blade->directive('endhasanyrole', fn () => '<?php endif; ?>');

        $blade->directive('hasallroles', function ($expression) {
            return "<?php if (auth()->check() && method_exists(auth()->user(), 'hasAllRoles') && auth()->user()->hasAllRoles(explode('|', $expression))): ?>";
        });
        $blade->directive('endhasallroles', fn () => '<?php endif; ?>');

        $blade->directive('unlessrole', function ($expression) {
            return "<?php if (! (auth()->check() && method_exists(auth()->user(), 'hasRole') && auth()->user()->hasRole($expression))): ?>";
        });
        $blade->directive('endunlessrole', fn () => '<?php endif; ?>');

        $blade->directive('permission', function ($expression) {
            return "<?php if (auth()->check() && method_exists(auth()->user(), 'hasPermissionTo') && auth()->user()->hasPermissionTo($expression)): ?>";
        });
        $blade->directive('endpermission', fn () => '<?php endif; ?>');

        $blade->directive('haspermission', function ($expression) {
            return "<?php if (auth()->check() && method_exists(auth()->user(), 'hasPermissionTo') && auth()->user()->hasPermissionTo($expression)): ?>";
        });
        $blade->directive('endhaspermission', fn () => '<?php endif; ?>');

        $blade->directive('hasanypermission', function ($expression) {
            return "<?php if (auth()->check() && method_exists(auth()->user(), 'hasAnyPermission') && auth()->user()->hasAnyPermission(explode('|', $expression))): ?>";
        });
        $blade->directive('endhasanypermission', fn () => '<?php endif; ?>');

        Gate::before(function ($user, $ability) {
            if (! method_exists($user, 'hasPermissionTo')) {
                return null;
            }
            try {
                return $user->hasPermissionTo($ability) ?: null;
            } catch (\Webrek\MongoPermission\Exceptions\PermissionDoesNotExist) {
                return null;
            }
        });
    }
}
