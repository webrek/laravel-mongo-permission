<?php

namespace Webrek\MongoPermission;

use Illuminate\Support\Facades\Cache;

class PermissionRegistrar
{
    protected ?string $teamId = null;
    protected bool $teamIdExplicitlySet = false;

    /** @var array<string, array<int, string>> in-memory cache for the current request */
    protected array $memo = [];

    public function setTeamId(?string $teamId): self
    {
        $this->teamId = $teamId;
        $this->teamIdExplicitlySet = true;
        return $this;
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
        return $this->slugsFor($user, 'permissions');
    }

    public function getUserRoleSlugs(object $user): array
    {
        return $this->slugsFor($user, 'roles');
    }

    public function forgetUserCache(string $userId, ?string $teamId): void
    {
        foreach (['permissions', 'roles'] as $kind) {
            $key = $this->cacheKey($userId, $teamId, $kind);
            Cache::forget($key);
            unset($this->memo[$key]);
        }
    }

    public function flush(): void
    {
        Cache::flush();
        $this->memo = [];
    }

    protected function slugsFor(object $user, string $kind): array
    {
        $key = $this->cacheKey((string) $user->getKey(), $this->getTeamId(), $kind);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $slugs = Cache::rememberForever($key, function () use ($user, $kind): array {
            return $kind === 'permissions'
                ? $this->loadPermissionSlugs($user)
                : $this->loadRoleSlugs($user);
        });

        return $this->memo[$key] = $slugs;
    }

    protected function loadPermissionSlugs(object $user): array
    {
        $permClass = config('permission.models.permission');
        $roleClass = config('permission.models.role');

        $directIds = collect($user->permission_ids ?? [])
            ->filter(fn ($e) => $this->teamMatches($e['team_id'] ?? null))
            ->pluck('permission_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $roleIds = collect($user->role_ids ?? [])
            ->filter(fn ($e) => $this->teamMatches($e['team_id'] ?? null))
            ->pluck('role_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $rolePermIds = [];
        if (! empty($roleIds)) {
            $rolePermIds = $roleClass::query()
                ->whereIn('_id', $roleIds)
                ->get()
                ->flatMap(fn ($r) => $r->permission_ids ?? [])
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->all();
        }

        $allIds = array_values(array_unique(array_merge($directIds, $rolePermIds)));
        if (empty($allIds)) {
            return [];
        }

        return $permClass::query()
            ->whereIn('_id', $allIds)
            ->get()
            ->pluck('name')
            ->all();
    }

    protected function loadRoleSlugs(object $user): array
    {
        $roleClass = config('permission.models.role');

        $ids = collect($user->role_ids ?? [])
            ->filter(fn ($e) => $this->teamMatches($e['team_id'] ?? null))
            ->pluck('role_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (empty($ids)) {
            return [];
        }

        return $roleClass::query()
            ->whereIn('_id', $ids)
            ->get()
            ->pluck('name')
            ->all();
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

    protected function cacheKey(string $userId, ?string $teamId, string $kind): string
    {
        $ns = config('permission.cache.key', 'mongo-permission');
        $team = $teamId ?? 'null';
        return "{$ns}.user.{$userId}.team.{$team}.{$kind}";
    }
}
