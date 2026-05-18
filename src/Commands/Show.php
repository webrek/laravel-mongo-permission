<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;

class Show extends Command
{
    protected $signature = 'permission:show {--guard= : Filter by guard} {--team= : Filter by team_id}';

    protected $description = 'Print a roles x permissions matrix';

    public function handle(): int
    {
        $roleClass = config('permission.models.role');
        $permClass = config('permission.models.permission');

        $guard = $this->option('guard') ?: config('permission.default_guard');
        $teamFilter = $this->option('team');

        $roles = $roleClass::query()->where('guard_name', $guard);
        $perms = $permClass::query()->where('guard_name', $guard);
        if ($teamFilter !== null) {
            $roles = $roles->where('team_id', $teamFilter);
            $perms = $perms->where('team_id', $teamFilter);
        }
        $roles = $roles->get();
        $perms = $perms->get();

        $headers = ['Role / Permission'];
        foreach ($perms as $p) {
            $headers[] = $p->name;
        }

        $rows = [];
        foreach ($roles as $role) {
            $row = [$role->name];
            $rolePerms = array_map('strval', $role->permission_ids ?? []);
            foreach ($perms as $p) {
                $row[] = in_array((string) $p->getKey(), $rolePerms, strict: true) ? 'x' : '';
            }
            $rows[] = $row;
        }

        $this->info(sprintf('Guard: %s%s', $guard, $teamFilter ? " | Team: {$teamFilter}" : ''));
        foreach ($roles as $role) {
            $this->info(sprintf('Role: %s', $role->name));
        }
        foreach ($perms as $p) {
            $this->info(sprintf('Permission: %s', $p->name));
        }
        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}
