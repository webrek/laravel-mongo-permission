<?php

namespace Webrek\MongoPermission\Traits;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Webrek\MongoPermission\Contracts\Role as RoleContract;
use Webrek\MongoPermission\Exceptions\GuardDoesNotMatch;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Support\Entry;
use Webrek\MongoPermission\Support\Expiry;
use Webrek\MongoPermission\Support\TeamScope;

trait HasRoles
{
    use HasPermissions;

    public function roles(): Collection
    {
        $roleClass = config('permission.models.role');
        $ids = collect($this->role_ids ?? [])
            ->map(fn ($e) => Entry::normalize($e, 'role_id'))
            ->filter(fn ($n) => $n['id'] !== null && Expiry::notExpired($n) && TeamScope::grant($n['team_id']))
            ->pluck('id')
            ->all();

        return $roleClass::query()->whereIn('_id', $ids)->where('guard_name', $this->guardName())->get()->filter(fn ($m) => TeamScope::catalog($m, $this->activeTeamId()))->values();
    }

    public function assignRole(...$roles): self
    {
        return $this->attachRoles($this->flattenInput($roles), null);
    }

    public function assignRoleUntil(string|RoleContract $role, DateTimeInterface $expiresAt): self
    {
        return $this->attachRoles([$role], $expiresAt);
    }

    protected function attachRoles(array $entries, ?DateTimeInterface $expiresAt): self
    {
        return $this->mutateGrants('role', $this->resolveGrantModels($entries, 'role'), 'add', $expiresAt);
    }

    public function removeRole(...$entries): self
    {
        return $this->mutateGrants('role', $this->resolveGrantModels($this->flattenInput($entries), 'role'), 'remove');
    }

    public function syncRoles(...$entries): self
    {
        return $this->mutateGrants('role', $this->resolveGrantModels($this->flattenInput($entries), 'role'), 'sync');
    }

    public function hasRole(string|array|RoleContract $role, ?string $guard = null): bool
    {
        $guard ??= $this->guardName();
        $slugs = app(PermissionRegistrar::class)->getUserRoleSlugs($this, $guard);
        foreach (is_array($role) ? $role : [$role] as $entry) {
            if ($entry instanceof RoleContract) {
                if ($entry->getGuardName() !== $guard || ! TeamScope::catalog($entry, $this->activeTeamId())) {
                    continue;
                }
                if (in_array((string) $entry->getKey(), app(PermissionRegistrar::class)->getUserRoleIds($this, $guard), true)) {
                    return true;
                }

                continue;
            } else {
                $name = $entry;
            }
            if (in_array($name, $slugs, true)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyRole(...$roles): bool
    {
        return $this->hasRole($this->flattenInput($roles));
    }

    public function hasAllRoles($roles, ?string $guard = null): bool
    {
        $names = is_array($roles) ? $roles : [$roles];
        foreach ($names as $r) {
            if (! $this->hasRole($r, $guard)) {
                return false;
            }
        }

        return true;
    }

    public function hasExactRoles(array $roles, ?string $guard = null): bool
    {
        if ($this->roles()->count() !== count($roles)) {
            return false;
        }

        return $this->hasAllRoles($roles, $guard);
    }

    public function getRoleNames(): Collection
    {
        return $this->roles()->pluck('name');
    }

    public function getPermissionsViaRoles(): Collection
    {
        $permClass = config('permission.models.permission');
        $permissionIds = $this->roles()->flatMap(function ($r) {
            return method_exists($r, 'getAllPermissionIds')
                ? $r->getAllPermissionIds()
                : ($r->permission_ids ?? []);
        })->unique()->all();

        return $permClass::query()->whereIn('_id', $permissionIds)->where('guard_name', $this->guardName())->get()->filter(fn ($m) => TeamScope::catalog($m, $this->activeTeamId()))->values();
    }

    protected function resolveRoleIds(array $entries): array
    {
        $ids = [];
        foreach ($entries as $e) {
            $ids[] = $this->resolveRoleId($e);
        }

        return $ids;
    }

    protected function resolveRoleId($entry, ?string $guard = null): string
    {
        $roleClass = config('permission.models.role');
        $expectedGuard = $guard ?? $this->guardName();
        if ($entry instanceof RoleContract) {
            $actualGuard = $entry->getGuardName();
            if ($actualGuard !== $expectedGuard) {
                throw GuardDoesNotMatch::create($actualGuard, $expectedGuard);
            }

            return (string) $entry->getKey();
        }

        return (string) $roleClass::findByName($entry, $expectedGuard)->getKey();
    }
}
