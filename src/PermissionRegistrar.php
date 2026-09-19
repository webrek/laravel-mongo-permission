<?php

namespace Webrek\MongoPermission;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Webrek\MongoPermission\Support\Entry;
use Webrek\MongoPermission\Support\Expiry;
use Webrek\MongoPermission\Support\TeamScope;

class PermissionRegistrar
{
    protected ?string $teamId = null;

    protected bool $teamIdExplicitlySet = false;

    /** @var array<string, array<int, array{name: string, expires_at: int|null}>> */
    protected array $memo = [];

    public function setTeamId(?string $teamId): self
    {
        $this->teamId = $teamId;
        $this->teamIdExplicitlySet = true;

        return $this;
    }

    /** Run work in a team context without changing the caller's resolver state. */
    public function withTeamId(?string $teamId, callable $callback): mixed
    {
        $previous = $this->teamId;
        $wasExplicit = $this->teamIdExplicitlySet;
        $this->setTeamId($teamId);
        try {
            return $callback();
        } finally {
            $this->teamId = $previous;
            $this->teamIdExplicitlySet = $wasExplicit;
        }
    }

    public function getTeamId(): ?string
    {
        if ($this->teamIdExplicitlySet) {
            return $this->teamId;
        }

        $resolver = config('permission.team_resolver');
        if (is_callable($resolver)) {
            $resolved = $resolver();

            return $resolved === null ? null : (string) $resolved;
        }

        return null;
    }

    public function forgetTeamId(): self
    {
        $this->teamId = null;
        $this->teamIdExplicitlySet = false;

        return $this;
    }

    public function getUserPermissionSlugs(object $user): array
    {
        return $this->namesFromEntries($this->entriesFor($user, 'permissions'));
    }

    public function getUserPermissionIds(object $user): array
    {
        return collect($this->entriesFor($user, 'permissions'))
            ->filter(fn ($entry) => $entry['expires_at'] === null || $entry['expires_at'] > Carbon::now()->getTimestamp())
            ->pluck('id')->unique()->values()->all();
    }

    public function getUserRoleSlugs(object $user, ?string $guard = null): array
    {
        return $this->namesFromEntries($this->entriesFor($user, 'roles', $guard));
    }

    public function getUserRoleIds(object $user, ?string $guard = null): array
    {
        return collect($this->entriesFor($user, 'roles', $guard))
            ->filter(fn ($entry) => $entry['expires_at'] === null || $entry['expires_at'] > Carbon::now()->getTimestamp())
            ->pluck('id')->all();
    }

    public function store(): Repository
    {
        $store = config('permission.cache.store', 'default');

        return Cache::store($store === 'default' ? null : $store);
    }

    public function withLock(string $name, callable $callback): mixed
    {
        $cache = $this->store();
        if (! $cache instanceof \Illuminate\Cache\Repository || ! $cache->getStore() instanceof LockProvider) {
            throw new \LogicException('Permission mutations require a cache store that supports atomic locks.');
        }

        return $cache->getStore()->lock($this->versionKey().'.lock.'.hash('sha256', $name), 30)->block(10, $callback);
    }

    protected function generation(string $key, bool $increment = false): int
    {
        $cache = $this->store();
        $value = $cache->get($key);
        if (! $increment && $value !== null) {
            return (int) $value;
        }
        if (! $cache instanceof \Illuminate\Cache\Repository) {
            throw new \LogicException('Permission cache requires a Laravel cache repository.');
        }
        $driver = $cache->getStore();
        if (! $driver instanceof LockProvider) {
            throw new \LogicException('Permission cache requires a store that supports atomic locks.');
        }

        // FileStore::increment is read/modify/write, so keep writer locks.
        // Use the store's increment for existing counters: Redis can preserve
        // concurrent increments even if a paused writer outlives its lock lease.
        return $driver->lock($key.'.lock', 10)->block(5, function () use ($cache, $key, $increment) {
            $current = $cache->get($key);
            if ($current !== null) {
                return $increment ? (int) $cache->increment($key) : (int) $current;
            }
            $next = (int) (microtime(true) * 1000000);
            if ($increment) {
                $next++;
            }
            $cache->forever($key, $next);

            return $next;
        });
    }

    public function forgetUserCache(string $userId, ?string $teamId): void
    {
        // A global grant affects every team. Version the user, not only one tuple.
        $this->generation($this->versionKey().'.user.'.$userId, true);
        $this->memo = [];
    }

    public function flush(): void
    {
        foreach (array_keys($this->memo) as $key) {
            $this->store()->forget($key);
        }
        $this->bumpCacheVersion();
    }

    public function cacheVersion(): int
    {
        return $this->generation($this->versionKey());
    }

    public function bumpCacheVersion(): void
    {
        $this->generation($this->versionKey(), true);
        $this->memo = [];
    }

    protected function versionKey(): string
    {
        return config('permission.cache.key', 'mongo-permission').'.version';
    }

    protected function remember(string $key, callable $load): mixed
    {
        // Bound retired generations even when no explicit TTL was supplied.
        $ttl = config('permission.cache.expiration_time') ?? 86400;
        $value = $this->store()->remember($key, $ttl, $load);
        if (count($this->memo) >= 1024) {
            $this->memo = [];
        }
        $this->memo[$key] = [];

        return $value;
    }

    public function catalog(string $class, string $guard): Collection
    {
        $team = TeamScope::active();
        $key = $this->versionKey().'.'.$this->cacheVersion().'.catalog.'.hash('sha256', serialize([$class, $guard, $team, config('permission.teams')]));

        return $this->remember($key, function () use ($class, $guard, $team) {
            $query = $class::query()->where('guard_name', $guard);
            if (config('permission.teams', false)) {
                $query->where(function ($q) use ($team) {
                    $q->where('team_id', $team)->orWhereNull('team_id');
                });
            }

            return $query->get()->sortBy(fn ($m) => $m->team_id === $team ? 0 : 1)->values();
        });
    }

    protected function entriesFor(object $user, string $kind, ?string $guard = null): array
    {
        $guard ??= Guard::resolveForModel($user);
        $key = $this->cacheKey((string) $user->getKey(), $this->getTeamId(), $kind, $guard);

        return $this->remember($key, function () use ($user, $kind, $guard) {
            $fresh = $user->fresh() ?? $user;

            return $kind === 'permissions' ? $this->loadPermissionEntries($fresh) : $this->loadRoleEntries($fresh, $guard);
        });
    }

    /**
     * Distill the cached entries into a flat list of currently-active slugs,
     * filtering out anything whose expires_at has passed.
     *
     * @param  array<int, array{name: string, expires_at: int|null}>  $entries
     * @return array<int, string>
     */
    protected function namesFromEntries(array $entries): array
    {
        $now = Carbon::now()->getTimestamp();
        $active = [];
        foreach ($entries as $entry) {
            $exp = $entry['expires_at'] ?? null;
            if ($exp !== null && $exp <= $now) {
                continue;
            }
            $active[$entry['name']] = true;
        }

        return array_keys($active);
    }

    /**
     * @return array<int, array{name: string, expires_at: int|null}>
     */
    protected function loadPermissionEntries(object $user): array
    {
        $permClass = config('permission.models.permission');
        $roleClass = config('permission.models.role');

        // Direct permission grants on the user.
        $directGrants = [];
        foreach ($user->permission_ids ?? [] as $e) {
            $n = Entry::normalize($e, 'permission_id');
            if (! $this->teamMatches($n['team_id'])) {
                continue;
            }
            $directGrants[] = [
                'permission_id' => (string) ($n['id'] ?? ''),
                'expires_at' => $this->expiryTimestamp($n),
            ];
        }

        // Role assignments — each carries its own expiry which propagates to the
        // permissions reached through that role.
        $roleAssignments = [];
        foreach ($user->role_ids ?? [] as $e) {
            $n = Entry::normalize($e, 'role_id');
            if (! $this->teamMatches($n['team_id'])) {
                continue;
            }
            $roleAssignments[] = [
                'role_id' => (string) ($n['id'] ?? ''),
                'expires_at' => $this->expiryTimestamp($n),
            ];
        }

        $roleIds = array_values(array_unique(array_column($roleAssignments, 'role_id')));
        $rolesById = [];
        if (! empty($roleIds)) {
            $rolesById = $roleClass::query()
                ->whereIn('_id', $roleIds)
                ->where('guard_name', Guard::resolveForModel($user))
                ->get()->filter(fn ($m) => TeamScope::catalog($m, $this->getTeamId()))
                ->keyBy(fn ($r) => (string) $r->getKey());
        }

        $grants = $directGrants;
        $ancestorMemo = [];
        foreach ($roleAssignments as $assignment) {
            $role = $rolesById[$assignment['role_id']] ?? null;
            if (! $role) {
                continue;
            }
            // permission ids walked through the inheritance chain of the role
            $allIds = method_exists($role, 'getAllPermissionIds')
                ? $role->getAllPermissionIds($ancestorMemo)
                : array_map('strval', $role->permission_ids ?? []);
            foreach ($allIds as $pid) {
                $grants[] = [
                    'permission_id' => (string) $pid,
                    'expires_at' => $assignment['expires_at'],
                ];
            }
        }

        if (empty($grants)) {
            return [];
        }

        $permIds = array_values(array_unique(array_column($grants, 'permission_id')));
        $permsById = $permClass::query()
            ->whereIn('_id', $permIds)
            ->where('guard_name', Guard::resolveForModel($user))
            ->get()->filter(fn ($m) => TeamScope::catalog($m, $this->getTeamId()))
            ->keyBy(fn ($p) => (string) $p->getKey());

        $entries = [];
        foreach ($grants as $grant) {
            $perm = $permsById[$grant['permission_id']] ?? null;
            if (! $perm) {
                continue;
            }
            $entries[] = [
                'id' => (string) $perm->getKey(),
                'name' => $perm->name,
                'expires_at' => $grant['expires_at'],
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, array{name: string, expires_at: int|null}>
     */
    protected function loadRoleEntries(object $user, ?string $guard = null): array
    {
        $roleClass = config('permission.models.role');

        $assignments = [];
        foreach ($user->role_ids ?? [] as $e) {
            $n = Entry::normalize($e, 'role_id');
            if (! $this->teamMatches($n['team_id'])) {
                continue;
            }
            $assignments[] = [
                'role_id' => (string) ($n['id'] ?? ''),
                'expires_at' => $this->expiryTimestamp($n),
            ];
        }

        if (empty($assignments)) {
            return [];
        }

        $roleIds = array_values(array_unique(array_column($assignments, 'role_id')));
        $rolesById = $roleClass::query()
            ->whereIn('_id', $roleIds)
            ->where('guard_name', $guard ?? Guard::resolveForModel($user))
            ->get()->filter(fn ($m) => TeamScope::catalog($m, $this->getTeamId()))
            ->keyBy(fn ($r) => (string) $r->getKey());

        $entries = [];
        foreach ($assignments as $assignment) {
            $role = $rolesById[$assignment['role_id']] ?? null;
            if (! $role) {
                continue;
            }
            $entries[] = [
                'id' => (string) $role->getKey(),
                'name' => $role->name,
                'expires_at' => $assignment['expires_at'],
            ];
        }

        return $entries;
    }

    /**
     * Pull the expiry off a grant subdoc and normalize it to a unix timestamp,
     * or null when the grant has no expiry.
     */
    protected function expiryTimestamp(array $entry): ?int
    {
        $dt = Expiry::toDateTime($entry['expires_at'] ?? null);

        return $dt?->getTimestamp();
    }

    protected function teamMatches(?string $entryTeam): bool
    {
        if (! config('permission.teams', false)) {
            return true;
        }
        $active = $this->getTeamId();
        $strict = (bool) config('permission.strict_team_isolation', false);
        if ($strict) {
            return $entryTeam === $active;
        }

        return $entryTeam === $active || $entryTeam === null;
    }

    public function cacheKey(string $userId, ?string $teamId, string $kind, ?string $guard = null): string
    {
        $ns = config('permission.cache.key', 'mongo-permission');
        $guard ??= config('auth.defaults.guard', config('permission.default_guard'));
        $scope = hash('sha256', serialize([$teamId, $guard, (bool) config('permission.teams'), (bool) config('permission.strict_team_isolation')]));
        $revision = $this->generation($this->versionKey().'.user.'.$userId);

        // Format 2 includes permission identity; do not reuse older name-only entries.
        return "{$ns}.format2.v{$this->cacheVersion()}.user.{$userId}.r{$revision}.scope.{$scope}.{$kind}";
    }
}
