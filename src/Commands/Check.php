<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Support\Entry;
use Webrek\MongoPermission\Support\Expiry;
use Webrek\MongoPermission\WildcardPermission;

class Check extends Command
{
    protected $signature = 'permission:check
        {user_id : The user document _id}
        {permission : The permission name to evaluate}
        {--guard= : Restrict guard. Defaults to permission.default_guard.}
        {--team= : team_id context. Pass "null" or omit to use global / current.}
        {--user-model= : Fully-qualified User model. Defaults to auth.providers.users.model.}';

    protected $description = 'Trace why a user does or does not have a given permission';

    public function handle(): int
    {
        $userId = $this->argument('user_id');
        $permName = $this->argument('permission');

        $userClass = $this->option('user-model') ?: config('auth.providers.users.model');
        if (! $userClass || ! class_exists($userClass)) {
            $this->error('Could not resolve a user model. Pass --user-model= or set auth.providers.users.model.');

            return self::FAILURE;
        }

        $guard = $this->option('guard') ?: config('permission.default_guard');
        $teamOpt = $this->option('team');
        $teamFilter = $teamOpt === 'null' ? null : ($teamOpt ?? app(PermissionRegistrar::class)->getTeamId());
        $teamFilterActive = $teamOpt !== null || (bool) config('permission.teams');

        $user = $userClass::query()->where('_id', $userId)->first();
        if (! $user) {
            $this->error(sprintf('User %s not found.', $userId));

            return self::FAILURE;
        }

        $permClass = config('permission.models.permission');
        $perms = $permClass::query()
            ->where('name', $permName)
            ->where('guard_name', $guard)
            ->get()->filter(fn ($p) => $this->catalogMatches($p, $teamFilter, $teamFilterActive));

        if ($perms->isEmpty()) {
            $this->warn(sprintf('Permission "%s" does not exist for guard "%s".', $permName, $guard));
            $this->line('Checking wildcards owned by user...');

            return $this->reportWildcardOnly($user, $permName, $guard, $teamFilter, $teamFilterActive);
        }

        $permIds = $perms->map(fn ($p) => (string) $p->getKey())->all();

        $this->info(sprintf('User %s has "%s" (guard %s)?', $userId, $permName, $guard));
        $reasons = [];

        // Direct grants matching the exact permission.
        foreach ($user->permission_ids ?? [] as $entry) {
            $entry = Entry::normalize($entry, 'permission_id');
            if (! in_array($entry['id'], $permIds, true)) {
                continue;
            }
            $reason = $this->describeGrant('direct grant', $entry, $teamFilter, $teamFilterActive);
            $reasons[] = $reason;
        }

        // Role-based grants matching the permission.
        $roleClass = config('permission.models.role');
        $rolesWithPerm = $roleClass::query()
            ->where('guard_name', $guard)
            ->get()->filter(fn ($r) => $this->catalogMatches($r, $teamFilter, $teamFilterActive) && array_intersect($r->getAllPermissionIds(), $permIds))
            ->keyBy(fn ($r) => (string) $r->getKey());

        foreach ($user->role_ids ?? [] as $entry) {
            $entry = Entry::normalize($entry, 'role_id');
            $rid = $entry['id'];
            $role = $rolesWithPerm->get($rid);
            if (! $role) {
                continue;
            }
            $reason = $this->describeGrant(sprintf('via role "%s"', $role->name), $entry, $teamFilter, $teamFilterActive);
            $reasons[] = $reason;
        }

        // Wildcard grants.
        if (config('permission.enable_wildcard_permission', false)) {
            $wildcards = $this->ownedWildcards($user, $guard, $teamFilter, $teamFilterActive);
            foreach ($wildcards as $w) {
                if (WildcardPermission::implies($w['name'], $permName)) {
                    $reasons[] = sprintf('[ok] wildcard "%s" implies "%s" (%s)', $w['name'], $permName, $w['source']);
                } else {
                    $reasons[] = sprintf('[skip] wildcard "%s" does not imply "%s"', $w['name'], $permName);
                }
            }
        }

        $hasIt = false;
        foreach ($reasons as $r) {
            if (str_starts_with($r, '[ok]')) {
                $hasIt = true;
                break;
            }
        }

        $this->line('  '.($hasIt ? 'YES' : 'NO'));
        foreach ($reasons as $r) {
            $this->line('  '.$r);
        }
        if (empty($reasons)) {
            $this->line('  no matching grants found');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{name: string, source: string, expires_at: int|null}>
     */
    protected function ownedWildcards(object $user, string $guard, ?string $teamFilter, bool $teamFilterActive): array
    {
        $permClass = config('permission.models.permission');
        $roleClass = config('permission.models.role');

        $directIds = [];
        $directMeta = [];
        foreach ($user->permission_ids ?? [] as $e) {
            $e = Entry::normalize($e, 'permission_id');
            if (! $this->grantMatches($e['team_id'], $teamFilter, $teamFilterActive)) {
                continue;
            }
            if (Expiry::isExpired($e)) {
                continue;
            }
            $pid = (string) $e['id'];
            $directIds[] = $pid;
            $directMeta[$pid] = $e;
        }

        $roleIds = [];
        foreach ($user->role_ids ?? [] as $e) {
            $e = Entry::normalize($e, 'role_id');
            if (! $this->grantMatches($e['team_id'], $teamFilter, $teamFilterActive)) {
                continue;
            }
            if (Expiry::isExpired($e)) {
                continue;
            }
            $rid = (string) $e['id'];
            $roleIds[] = $rid;
        }

        $allPermIds = $directIds;
        $rolesById = [];
        if (! empty($roleIds)) {
            $rolesById = $roleClass::query()
                ->whereIn('_id', $roleIds)
                ->where('guard_name', $guard)
                ->get()->filter(fn ($r) => $this->catalogMatches($r, $teamFilter, $teamFilterActive))
                ->keyBy(fn ($r) => (string) $r->getKey());
            foreach ($rolesById as $rid => $role) {
                foreach ($role->getAllPermissionIds() as $pid) {
                    $allPermIds[] = (string) $pid;
                }
            }
        }
        $allPermIds = array_values(array_unique($allPermIds));

        if (empty($allPermIds)) {
            return [];
        }

        $perms = $permClass::query()
            ->whereIn('_id', $allPermIds)
            ->where('guard_name', $guard)
            ->get()->filter(fn ($p) => $this->catalogMatches($p, $teamFilter, $teamFilterActive));

        $out = [];
        foreach ($perms as $p) {
            if (! str_contains($p->name, '*')) {
                continue;
            }
            $pid = (string) $p->getKey();
            // Find which source (direct vs role) carries this permission.
            if (isset($directMeta[$pid])) {
                $out[] = ['name' => $p->name, 'source' => 'direct', 'expires_at' => null];

                continue;
            }
            foreach ($rolesById as $rid => $role) {
                if (in_array($pid, $role->getAllPermissionIds(), strict: true)) {
                    $out[] = ['name' => $p->name, 'source' => "via role \"{$role->name}\"", 'expires_at' => null];
                    break;
                }
            }
        }

        return $out;
    }

    protected function reportWildcardOnly(object $user, string $permName, string $guard, ?string $teamFilter, bool $teamFilterActive): int
    {
        if (! config('permission.enable_wildcard_permission', false)) {
            $this->line('  Wildcard matching is disabled.');

            return self::SUCCESS;
        }

        $wildcards = $this->ownedWildcards($user, $guard, $teamFilter, $teamFilterActive);
        $hit = false;
        foreach ($wildcards as $w) {
            if (WildcardPermission::implies($w['name'], $permName)) {
                $this->line(sprintf('  [ok] wildcard "%s" implies "%s" (%s)', $w['name'], $permName, $w['source']));
                $hit = true;
            }
        }
        if (! $hit) {
            $this->line('  no wildcard grant implies this name');
        }

        return self::SUCCESS;
    }

    protected function describeGrant(string $kind, array $entry, ?string $teamFilter, bool $teamFilterActive): string
    {
        $team = $entry['team_id'] ?? null;
        $teamLabel = $team ?? 'global';

        if (! $this->grantMatches($team, $teamFilter, $teamFilterActive)) {
            return sprintf('[skip] %s in team [%s] does not match requested team [%s]', $kind, $teamLabel, $teamFilter ?? 'global');
        }

        $exp = Expiry::toDateTime($entry['expires_at'] ?? null);
        if ($exp !== null && $exp <= now()) {
            return sprintf('[skip] %s expired at %s', $kind, $exp->format('Y-m-d H:i:s'));
        }
        if ($exp !== null) {
            return sprintf('[ok] %s in team [%s], expires %s', $kind, $teamLabel, $exp->format('Y-m-d H:i:s'));
        }

        return sprintf('[ok] %s in team [%s]', $kind, $teamLabel);
    }

    protected function catalogMatches(object $model, ?string $team, bool $scoped): bool
    {
        return ! $scoped || $model->team_id === null || $model->team_id === $team;
    }

    protected function grantMatches(?string $owner, ?string $team, bool $scoped): bool
    {
        return ! $scoped || $owner === $team || (! config('permission.strict_team_isolation') && $owner === null);
    }
}
