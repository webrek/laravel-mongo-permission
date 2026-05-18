<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;
use Webrek\MongoPermission\Support\Expiry;

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
        $roleClass = config('permission.models.role');
        $role = $roleClass::query()
            ->where('name', $roleName)
            ->where('guard_name', $guard)
            ->first();

        if ($role === null) {
            $this->error(sprintf('Role "%s" not found for guard "%s".', $roleName, $guard));
            return self::FAILURE;
        }

        $roleId = (string) $role->getKey();
        $rows = [];

        foreach ($userClass::query()->cursor() as $user) {
            foreach ($user->role_ids ?? [] as $entry) {
                $entry = (array) $entry;
                if ((string) ($entry['role_id'] ?? '') !== $roleId) {
                    continue;
                }
                if (Expiry::isExpired($entry)) {
                    continue;
                }
                if ($teamFilterActive && ($entry['team_id'] ?? null) !== $teamFilter) {
                    continue;
                }
                $rows[] = [
                    (string) $user->getKey(),
                    $user->name ?? '',
                    $user->email ?? '',
                    $entry['team_id'] ?? '(global)',
                ];
            }
        }

        $this->info(sprintf('%d user(s) with role "%s" (guard: %s).', count($rows), $roleName, $guard));
        foreach ($rows as $row) {
            $this->line(sprintf('  %s  %s  <%s>  team:%s', $row[0], $row[1], $row[2], $row[3]));
        }
        return self::SUCCESS;
    }

    protected function listByPermission(string $userClass, string $permissionName, string $guard, ?string $teamFilter, bool $teamFilterActive): int
    {
        $permClass = config('permission.models.permission');
        $roleClass = config('permission.models.role');

        $perm = $permClass::query()
            ->where('name', $permissionName)
            ->where('guard_name', $guard)
            ->first();

        if ($perm === null) {
            $this->error(sprintf('Permission "%s" not found for guard "%s".', $permissionName, $guard));
            return self::FAILURE;
        }

        $permId = (string) $perm->getKey();

        // Find roles that carry this permission.
        $rolesWithPerm = $roleClass::query()
            ->where('permission_ids', $permId)
            ->where('guard_name', $guard)
            ->get();
        $roleNamesById = $rolesWithPerm->keyBy(fn ($r) => (string) $r->getKey())->map(fn ($r) => $r->name)->all();

        $rows = [];

        foreach ($userClass::query()->cursor() as $user) {
            $reasons = [];

            // Direct grant.
            foreach ($user->permission_ids ?? [] as $entry) {
                $entry = (array) $entry;
                if ((string) ($entry['permission_id'] ?? '') !== $permId) {
                    continue;
                }
                if (Expiry::isExpired($entry)) {
                    continue;
                }
                if ($teamFilterActive && ($entry['team_id'] ?? null) !== $teamFilter) {
                    continue;
                }
                $reasons[] = 'direct';
            }

            // Grant via role.
            foreach ($user->role_ids ?? [] as $entry) {
                $entry = (array) $entry;
                $rid = (string) ($entry['role_id'] ?? '');
                if (! isset($roleNamesById[$rid])) {
                    continue;
                }
                if (Expiry::isExpired($entry)) {
                    continue;
                }
                if ($teamFilterActive && ($entry['team_id'] ?? null) !== $teamFilter) {
                    continue;
                }
                $reasons[] = 'via role ' . $roleNamesById[$rid];
            }

            if (empty($reasons)) {
                continue;
            }

            $rows[] = [
                (string) $user->getKey(),
                $user->name ?? '',
                $user->email ?? '',
                implode(', ', $reasons),
            ];
        }

        $this->info(sprintf('%d user(s) with permission "%s" (guard: %s).', count($rows), $permissionName, $guard));
        foreach ($rows as $row) {
            $this->line(sprintf('  %s  %s  <%s>  source: %s', $row[0], $row[1], $row[2], $row[3]));
        }
        return self::SUCCESS;
    }
}
