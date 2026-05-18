<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;

class CreatePermission extends Command
{
    protected $signature = 'permission:create-permission {name : The permission name} {--guard= : Guard the permission belongs to}';

    protected $description = 'Create a permission';

    public function handle(): int
    {
        $permClass = config('permission.models.permission');
        $name = $this->argument('name');
        $guard = $this->option('guard') ?: config('permission.default_guard');

        if ($name === '*' || str_ends_with($name, '.*') || str_contains($name, '*')) {
            $this->warn(sprintf(
                'Heads up: "%s" is a wildcard permission name. It will match anything that matches the pattern. Make sure that is what you want.',
                $name,
            ));
        }

        $permClass::create([
            'name' => $name,
            'guard_name' => $guard,
        ]);

        $this->info(sprintf('Permission "%s" created for guard "%s".', $name, $guard));

        return self::SUCCESS;
    }
}
