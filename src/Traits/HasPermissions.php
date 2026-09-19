<?php

namespace Webrek\MongoPermission\Traits;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Webrek\MongoPermission\Contracts\Permission as PermissionContract;
use Webrek\MongoPermission\Exceptions\GuardDoesNotMatch;
use Webrek\MongoPermission\Exceptions\PermissionDoesNotExist;
use Webrek\MongoPermission\Guard;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Support\Entry;
use Webrek\MongoPermission\Support\Expiry;
use Webrek\MongoPermission\Support\TeamScope;
use Webrek\MongoPermission\WildcardPermission;

trait HasPermissions
{
    use MutatesGrants;

    public function permissions()
    {
        $permClass = config('permission.models.permission');
        $ids = collect($this->permission_ids ?? [])
            ->map(fn ($e) => Entry::normalize($e, 'permission_id'))
            ->filter(fn ($n) => $n['id'] !== null && Expiry::notExpired($n) && TeamScope::grant($n['team_id']))
            ->pluck('id')
            ->all();

        return $permClass::query()->whereIn('_id', $ids)->where('guard_name', $this->guardName())->get()->filter(fn ($m) => TeamScope::catalog($m, $this->activeTeamId()))->values();
    }

    public function givePermissionTo(...$permissions): self
    {
        return $this->attachPermissions($this->flattenInput($permissions), null);
    }

    public function givePermissionToUntil(string|PermissionContract $permission, DateTimeInterface $expiresAt): self
    {
        return $this->attachPermissions([$permission], $expiresAt);
    }

    protected function attachPermissions(array $entries, ?DateTimeInterface $expiresAt): self
    {
        return $this->mutateGrants('permission', $this->resolveGrantModels($entries, 'permission'), 'add', $expiresAt);
    }

    public function revokePermissionTo(...$entries): self
    {
        return $this->mutateGrants('permission', $this->resolveGrantModels($this->flattenInput($entries), 'permission'), 'remove');
    }

    public function syncPermissions(...$entries): self
    {
        return $this->mutateGrants('permission', $this->resolveGrantModels($this->flattenInput($entries), 'permission'), 'sync');
    }

    public function hasPermissionTo(string|PermissionContract $permission): bool
    {
        if (! is_string($permission)) {
            if ($permission->getGuardName() !== $this->guardName() || ! TeamScope::catalog($permission, $this->activeTeamId())) {
                return false;
            }
        }
        $name = is_string($permission) ? $permission : $permission->getName();

        $registrar = app(PermissionRegistrar::class);
        $slugs = $registrar->getUserPermissionSlugs($this);
        $matches = is_string($permission)
            ? in_array($name, $slugs, strict: true)
            : in_array((string) $permission->getKey(), $registrar->getUserPermissionIds($this), strict: true);

        if ($matches) {
            return true;
        }

        if (config('permission.enable_wildcard_permission', false)) {
            foreach ($slugs as $owned) {
                if ((is_string($permission) || $owned !== $name) && WildcardPermission::implies($owned, $name)) {
                    return true;
                }
            }
        }

        if (is_string($permission) && config('permission.throw_on_missing_permission', true)) {
            config('permission.models.permission')::findByName($permission, $this->guardName());
        }

        return false;
    }

    public function hasDirectPermission(string|PermissionContract $permission): bool
    {
        try {
            $model = is_string($permission) ? config('permission.models.permission')::findByName($permission, $this->guardName()) : $permission;
        } catch (PermissionDoesNotExist) {
            return false;
        }
        if ($model->getGuardName() !== $this->guardName() || ! TeamScope::catalog($model, $this->activeTeamId())) {
            return false;
        }
        $id = (string) $model->getKey();

        $activeTeam = $this->activeTeamId();
        $strict = (bool) config('permission.strict_team_isolation', false);

        return collect($this->permission_ids ?? [])->contains(function ($e) use ($id, $activeTeam, $strict) {
            $n = Entry::normalize($e, 'permission_id');
            if ($n['id'] !== $id) {
                return false;
            }
            if (Expiry::isExpired($n)) {
                return false;
            }
            $entryTeam = $n['team_id'];
            if (! config('permission.teams', false)) {
                return true;
            }
            if ($strict) {
                return $entryTeam === $activeTeam;
            }

            return $entryTeam === $activeTeam || $entryTeam === null;
        });
    }

    public function hasAnyPermission(...$permissions): bool
    {
        foreach ($this->flattenInput($permissions) as $perm) {
            try {
                if ($this->hasPermissionTo($perm)) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                continue;
            }
        }

        return false;
    }

    public function hasAllPermissions(...$permissions): bool
    {
        foreach ($this->flattenInput($permissions) as $perm) {
            try {
                if (! $this->hasPermissionTo($perm)) {
                    return false;
                }
            } catch (PermissionDoesNotExist) {
                return false;
            }
        }

        return true;
    }

    public function getPermissionNames(): Collection
    {
        return $this->permissions()->pluck('name');
    }

    public function getAllPermissions(): Collection
    {
        $direct = $this->permissions();
        if (method_exists($this, 'getPermissionsViaRoles')) {
            $direct = $direct->concat($this->getPermissionsViaRoles())->unique('_id');
        }

        return $direct->values();
    }

    protected function guardName(): string
    {
        return Guard::resolveForModel($this);
    }

    protected function activeTeamId(): ?string
    {
        if (! config('permission.teams', false)) {
            return null;
        }

        return app(PermissionRegistrar::class)->getTeamId();
    }

    protected function flattenInput(array $items): array
    {
        $flat = [];
        foreach ($items as $i) {
            if (is_array($i)) {
                $flat = array_merge($flat, $i);
            } else {
                $flat[] = $i;
            }
        }

        return $flat;
    }

    protected function resolvePermissionIds(array $entries): array
    {
        $permClass = config('permission.models.permission');
        $expectedGuard = $this->guardName();
        $ids = [];
        foreach ($entries as $e) {
            if ($e instanceof PermissionContract) {
                $actualGuard = $e->getGuardName();
                if ($actualGuard !== $expectedGuard) {
                    throw GuardDoesNotMatch::create($actualGuard, $expectedGuard);
                }
                $ids[] = (string) $e->getKey();

                continue;
            }
            $ids[] = (string) $permClass::findByName($e, $expectedGuard)->getKey();
        }

        return $ids;
    }
}
