<?php

namespace Webrek\MongoPermission;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Webrek\MongoPermission\Support\Expiry;

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

    public function getUserRoleSlugs(object $user): array
    {
        return $this->namesFromEntries($this->entriesFor($user, 'roles'));
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

    /**
     * @return array<int, array{name: string, expires_at: int|null}>
     */
    protected function entriesFor(object $user, string $kind): array
    {
        $key = $this->cacheKey((string) $user->getKey(), $this->getTeamId(), $kind);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $entries = Cache::rememberForever($key, function () use ($user, $kind): array {
            return $kind === 'permissions'
                ? $this->loadPermissionEntries($user)
                : $this->loadRoleEntries($user);
        });

        return $this->memo[$key] = $entries;
    }

    /**
     * Distill the cached entries into a flat list of currently-active slugs,
     * filtering out anything whose expires_at has passed.
     *
     * @param array<int, array{name: string, expires_at: int|null}> $entries
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
            $e = (array) $e;
            if (! $this->teamMatches($e['team_id'] ?? null)) {
                continue;
            }
            $directGrants[] = [
                'permission_id' => (string) ($e['permission_id'] ?? ''),
                'expires_at' => $this->expiryTimestamp($e),
            ];
        }

        // Role assignments — each carries its own expiry which propagates to the
        // permissions reached through that role.
        $roleAssignments = [];
        foreach ($user->role_ids ?? [] as $e) {
            $e = (array) $e;
            if (! $this->teamMatches($e['team_id'] ?? null)) {
                continue;
            }
            $roleAssignments[] = [
                'role_id' => (string) ($e['role_id'] ?? ''),
                'expires_at' => $this->expiryTimestamp($e),
            ];
        }

        $roleIds = array_values(array_unique(array_column($roleAssignments, 'role_id')));
        $rolesById = [];
        if (! empty($roleIds)) {
            $rolesById = $roleClass::query()
                ->whereIn('_id', $roleIds)
                ->get()
                ->keyBy(fn ($r) => (string) $r->getKey());
        }

        $grants = $directGrants;
        foreach ($roleAssignments as $assignment) {
            $role = $rolesById[$assignment['role_id']] ?? null;
            if (! $role) {
                continue;
            }
            // permission ids walked through the inheritance chain of the role
            $allIds = method_exists($role, 'getAllPermissionIds')
                ? $role->getAllPermissionIds()
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
            ->get()
            ->keyBy(fn ($p) => (string) $p->getKey());

        $entries = [];
        foreach ($grants as $grant) {
            $perm = $permsById[$grant['permission_id']] ?? null;
            if (! $perm) {
                continue;
            }
            $entries[] = [
                'name' => $perm->name,
                'expires_at' => $grant['expires_at'],
            ];
        }
        return $entries;
    }

    /**
     * @return array<int, array{name: string, expires_at: int|null}>
     */
    protected function loadRoleEntries(object $user): array
    {
        $roleClass = config('permission.models.role');

        $assignments = [];
        foreach ($user->role_ids ?? [] as $e) {
            $e = (array) $e;
            if (! $this->teamMatches($e['team_id'] ?? null)) {
                continue;
            }
            $assignments[] = [
                'role_id' => (string) ($e['role_id'] ?? ''),
                'expires_at' => $this->expiryTimestamp($e),
            ];
        }

        if (empty($assignments)) {
            return [];
        }

        $roleIds = array_values(array_unique(array_column($assignments, 'role_id')));
        $rolesById = $roleClass::query()
            ->whereIn('_id', $roleIds)
            ->get()
            ->keyBy(fn ($r) => (string) $r->getKey());

        $entries = [];
        foreach ($assignments as $assignment) {
            $role = $rolesById[$assignment['role_id']] ?? null;
            if (! $role) {
                continue;
            }
            $entries[] = [
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

    protected function cacheKey(string $userId, ?string $teamId, string $kind): string
    {
        $ns = config('permission.cache.key', 'mongo-permission');
        $team = $teamId ?? 'null';
        return "{$ns}.user.{$userId}.team.{$team}.{$kind}";
    }
}
