<?php

namespace Webrek\MongoPermission;

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
            ]);
        }

        $events = $this->app['events'];
        $events->listen(\Webrek\MongoPermission\Events\RoleAttached::class, [\Webrek\MongoPermission\Listeners\RefreshUserCacheListener::class, 'onRoleAttached']);
        $events->listen(\Webrek\MongoPermission\Events\RoleDetached::class, [\Webrek\MongoPermission\Listeners\RefreshUserCacheListener::class, 'onRoleDetached']);
        $events->listen(\Webrek\MongoPermission\Events\PermissionAttached::class, [\Webrek\MongoPermission\Listeners\RefreshUserCacheListener::class, 'onPermissionAttached']);
        $events->listen(\Webrek\MongoPermission\Events\PermissionDetached::class, [\Webrek\MongoPermission\Listeners\RefreshUserCacheListener::class, 'onPermissionDetached']);
    }
}
