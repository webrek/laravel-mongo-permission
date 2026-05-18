<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;

class CreateRole extends Command
{
    protected $signature = 'permission:create-role {name : The role name} {--guard= : Guard the role belongs to} {permissions?* : Permission names to attach}';

    protected $description = 'Create a role and optionally attach permissions';

    public function handle(): int
    {
        $roleClass = config('permission.models.role');
        $name = $this->argument('name');
        $guard = $this->option('guard') ?: config('permission.default_guard');

        $role = $roleClass::create([
            'name' => $name,
            'guard_name' => $guard,
        ]);

        $perms = (array) $this->argument('permissions');
        if (! empty($perms)) {
            $role->givePermissionTo(...$perms);
        }

        $this->info(sprintf('Role "%s" created for guard "%s".', $name, $guard));

        return self::SUCCESS;
    }
}
