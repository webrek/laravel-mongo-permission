<?php

namespace Webrek\MongoPermission;

use Illuminate\Support\ServiceProvider;

class MongoPermissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/permission.php', 'permission');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/permission.php' => config_path('permission.php'),
            ], 'permission-config');
        }
    }
}
