<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;
use Webrek\MongoPermission\Support\Entry;
use Webrek\MongoPermission\Support\Expiry;
use Webrek\MongoPermission\WildcardPermission;

class ListUsers extends Command
{
    protected $signature = 'permission:list-users
        {role? : Role name. Lists users who have this role.}
        {--permission= : Permission name. Lists users who have this permission (direct or via roles).}
        {--guard= : Restrict to this guard. Defaults to the configured default.}
        {--team= : Restrict to this team_id. Pass "null" to scope to no team.}
        {--user-model= : Fully-qualified User model. Defaults to auth.providers.users.model.}';

    protected $description = 'List users that have a given role or permission';

    public function handle(): int
    {
        $role = $this->argument('role');
        $permission = $this->option('permission');

        if ($role === null && $permission === null) {
            $this->error('Pass a role argument or a --permission= option.');

            return self::FAILURE;
        }
        if ($role !== null && $permission !== null) {
            $this->error('Pass either a role argument or a --permission= option, not both.');

            return self::FAILURE;
        }

        $userClass = $this->option('user-model') ?: config('auth.providers.users.model');
        if (! $userClass || ! class_exists($userClass)) {
            $this->error('Could not resolve a user model. Pass --user-model= or set auth.providers.users.model.');

            return self::FAILURE;
        }

        $guard = $this->option('guard') ?: config('permission.default_guard');
        $teamOpt = $this->option('team');
        $teamFilter = $teamOpt === 'null' ? null : $teamOpt;
        $teamFilterActive = $teamOpt !== null;

        if ($role !== null) {
            return $this->listByRole($userClass, $role, $guard, $teamFilter, $teamFilterActive);
        }

        return $this->listByPermission($userClass, $permission, $guard, $teamFilter, $teamFilterActive);
    }

    protected function listByRole(string $userClass, string $roleName, string $guard, ?string $teamFilter, bool $teamFilterActive): int
    {
        $class = config('permission.models.role');
        $query = $class::query()->where('name', $roleName)->where('guard_name', $guard);
        if ($teamFilterActive) {
            $query->where(fn ($q) => $q->where('team_id', $teamFilter)->orWhereNull('team_id'));
        }
        $roles = $query->get();
        if ($roles->isEmpty()) {
            $this->error(sprintf('Role "%s" not found for guard "%s".', $roleName, $guard));

            return self::FAILURE;
        }
        $ids = $roles->map(fn ($r) => (string) $r->getKey())->all();
        $rows = [];
        foreach ($this->candidates($userClass, $ids, []) as $user) {
            foreach ($user->role_ids ?? [] as $entry) {
                $n = Entry::normalize($entry, 'role_id');
                if (! in_array($n['id'], $ids, true) || ! $this->matches($n, $teamFilter, $teamFilterActive)) {
                    continue;
                }
                $rows[(string) $user->getKey()] = sprintf('  %s  %s  <%s>  team:%s', $user->getKey(), $user->name ?? '', $user->email ?? '', $n['team_id'] ?? '(global)');
            }
        }
        $this->info(sprintf('%d user(s) with role "%s" (guard: %s).', count($rows), $roleName, $guard));
        foreach ($rows as $row) {
            $this->line($row);
        }

        return self::SUCCESS;
    }

    protected function listByPermission(string $userClass, string $permissionName, string $guard, ?string $teamFilter, bool $teamFilterActive): int
    {
        $class = config('permission.models.permission');
        $roleClass = config('permission.models.role');
        $permissions = $class::query()->where('guard_name', $guard)->get();
        $permissions = $permissions->filter(fn ($p) => $p->name === $permissionName || (config('permission.enable_wildcard_permission') && WildcardPermission::implies($p->name, $permissionName)));
        if ($teamFilterActive) {
            $permissions = $permissions->filter(fn ($p) => $p->team_id === null || $p->team_id === $teamFilter);
        }
        if ($permissions->isEmpty()) {
            $this->error(sprintf('Permission "%s" not found for guard "%s".', $permissionName, $guard));

            return self::FAILURE;
        }
        $ids = $permissions->map(fn ($p) => (string) $p->getKey())->all();
        $memo = [];
        $roles = $roleClass::query()->where('guard_name', $guard)->get();
        foreach ($roles as $role) {
            $memo[(string) $role->getKey()] = $role;
        }
        $roles = $roles->filter(function ($r) use ($ids, &$memo, $teamFilter, $teamFilterActive) {
            return (! $teamFilterActive || $r->team_id === null || $r->team_id === $teamFilter) && array_intersect($r->getAllPermissionIds($memo), $ids);
        })->keyBy(fn ($r) => (string) $r->getKey());
        $rows = [];
        foreach ($this->candidates($userClass, $roles->keys()->all(), $ids) as $user) {
            $reasons = [];
            foreach ($user->permission_ids ?? [] as $entry) {
                $n = Entry::normalize($entry, 'permission_id');
                if (in_array($n['id'], $ids, true) && $this->matches($n, $teamFilter, $teamFilterActive)) {
                    $reasons[] = 'direct';
                }
            }
            foreach ($user->role_ids ?? [] as $entry) {
                $n = Entry::normalize($entry, 'role_id');
                if (isset($roles[$n['id']]) && $this->matches($n, $teamFilter, $teamFilterActive)) {
                    $reasons[] = 'via role '.$roles[$n['id']]->name;
                }
            }
            if ($reasons) {
                $rows[] = sprintf('  %s  %s  <%s>  source: %s', $user->getKey(), $user->name ?? '', $user->email ?? '', implode(', ', array_unique($reasons)));
            }
        }
        $this->info(sprintf('%d user(s) with permission "%s" (guard: %s).', count($rows), $permissionName, $guard));
        foreach ($rows as $row) {
            $this->line($row);
        }

        return self::SUCCESS;
    }

    protected function matches(array $entry, ?string $team, bool $active): bool
    {
        return ! Expiry::isExpired($entry) && (! $active || $entry['team_id'] === $team
            || (! config('permission.strict_team_isolation') && $entry['team_id'] === null));
    }

    protected function candidates(string $userClass, array $roles, array $permissions): iterable
    {
        if (! $roles && ! $permissions) {
            return [];
        }

        return $userClass::query()->where(function ($q) use ($roles, $permissions) {
            if ($roles) {
                $q->whereIn('role_ids', $roles)->orWhereIn('role_ids.role_id', $roles);
            }
            if ($permissions) {
                $q->orWhereIn('permission_ids', $permissions)->orWhereIn('permission_ids.permission_id', $permissions);
            }
        })->cursor();
    }
}
