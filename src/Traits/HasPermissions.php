<?php

namespace Webrek\MongoPermission\Traits;

use Webrek\MongoPermission\Contracts\Permission as PermissionContract;

trait HasPermissions
{
    public function permissions()
    {
        $permClass = config('permission.models.permission');
        $ids = collect($this->permission_ids ?? [])->pluck('permission_id')->all();
        return $permClass::query()->whereIn('_id', $ids)->get();
    }

    public function givePermissionTo(...$permissions): self
    {
        $ids = $this->resolvePermissionIds($this->flattenInput($permissions));
        $current = collect($this->permission_ids ?? [])
            ->map(fn ($e) => (string) ($e['permission_id'] ?? null))
            ->all();

        $toAdd = array_diff($ids, $current);
        if (empty($toAdd)) {
            return $this;
        }

        $activeTeam = $this->activeTeamId();
        $merged = $this->permission_ids ?? [];
        foreach ($toAdd as $id) {
            $merged[] = ['permission_id' => $id, 'team_id' => $activeTeam];
        }
        $this->permission_ids = $merged;
        $this->save();

        $permClass = config('permission.models.permission');
        foreach ($toAdd as $id) {
            $perm = $permClass::query()->where('_id', $id)->first();
            event(new \Webrek\MongoPermission\Events\PermissionAttached(
                $this,
                $perm,
                $activeTeam,
                $this->guardName(),
            ));
        }

        return $this;
    }

    public function revokePermissionTo(...$permissions): self
    {
        $ids = $this->resolvePermissionIds($this->flattenInput($permissions));
        $remaining = collect($this->permission_ids ?? [])
            ->reject(fn ($e) => in_array((string) ($e['permission_id'] ?? null), $ids, strict: true))
            ->values()
            ->all();

        $removed = array_diff(
            collect($this->permission_ids ?? [])->map(fn ($e) => (string) ($e['permission_id'] ?? null))->all(),
            collect($remaining)->map(fn ($e) => (string) ($e['permission_id'] ?? null))->all(),
        );

        $this->permission_ids = $remaining;
        $this->save();

        if (! empty($removed)) {
            $permClass = config('permission.models.permission');
            foreach ($removed as $id) {
                $perm = $permClass::query()->where('_id', $id)->first();
                if ($perm) {
                    event(new \Webrek\MongoPermission\Events\PermissionDetached(
                        $this,
                        $perm,
                        null,
                        $this->guardName(),
                    ));
                }
            }
        }

        return $this;
    }

    public function syncPermissions(...$permissions): self
    {
        $targetIds = $this->resolvePermissionIds($this->flattenInput($permissions));
        $currentIds = collect($this->permission_ids ?? [])
            ->map(fn ($e) => (string) ($e['permission_id'] ?? null))
            ->all();

        $toRemove = array_diff($currentIds, $targetIds);
        $toAdd = array_diff($targetIds, $currentIds);

        if (! empty($toRemove) || ! empty($toAdd)) {
            $permClass = config('permission.models.permission');
            if (! empty($toRemove)) {
                $models = $permClass::query()->whereIn('_id', $toRemove)->get()->all();
                if (! empty($models)) {
                    $this->revokePermissionTo($models);
                }
            }
            if (! empty($toAdd)) {
                $models = $permClass::query()->whereIn('_id', $toAdd)->get()->all();
                if (! empty($models)) {
                    $this->givePermissionTo($models);
                }
            }
        }
        return $this;
    }

    public function hasPermissionTo(string|PermissionContract $permission): bool
    {
        if ($this->hasDirectPermission($permission)) {
            return true;
        }

        // Role-derived permissions are added in Task 9 once HasRoles is in.
        if (method_exists($this, 'getPermissionsViaRoles')) {
            $name = is_string($permission)
                ? $permission
                : $permission->getName();
            return $this->getPermissionsViaRoles()->contains(fn ($p) => $p->getName() === $name);
        }

        if (is_string($permission)) {
            // ensure the permission exists; throws otherwise
            config('permission.models.permission')::findByName($permission, $this->guardName());
        }

        return false;
    }

    public function hasDirectPermission(string|PermissionContract $permission): bool
    {
        $id = is_string($permission)
            ? (string) config('permission.models.permission')::findByName($permission, $this->guardName())->getKey()
            : (string) $permission->getKey();

        $activeTeam = $this->activeTeamId();
        $strict = (bool) config('permission.strict_team_isolation', false);

        return collect($this->permission_ids ?? [])->contains(function ($e) use ($id, $activeTeam, $strict) {
            if ((string) ($e['permission_id'] ?? null) !== $id) {
                return false;
            }
            $entryTeam = $e['team_id'] ?? null;
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
                if ($this->hasPermissionTo($perm)) return true;
            } catch (\Webrek\MongoPermission\Exceptions\PermissionDoesNotExist) {
                continue;
            }
        }
        return false;
    }

    public function hasAllPermissions(...$permissions): bool
    {
        foreach ($this->flattenInput($permissions) as $perm) {
            try {
                if (! $this->hasPermissionTo($perm)) return false;
            } catch (\Webrek\MongoPermission\Exceptions\PermissionDoesNotExist) {
                return false;
            }
        }
        return true;
    }

    public function getPermissionNames(): \Illuminate\Support\Collection
    {
        return $this->permissions()->pluck('name');
    }

    public function getAllPermissions(): \Illuminate\Support\Collection
    {
        $direct = $this->permissions();
        if (method_exists($this, 'getPermissionsViaRoles')) {
            $direct = $direct->concat($this->getPermissionsViaRoles())->unique('_id');
        }
        return $direct->values();
    }

    protected function guardName(): string
    {
        return \Webrek\MongoPermission\Guard::resolveForModel($this);
    }

    protected function activeTeamId(): ?string
    {
        if (! config('permission.teams', false)) {
            return null;
        }
        return app(\Webrek\MongoPermission\PermissionRegistrar::class)->getTeamId();
    }

    protected function flattenInput(array $items): array
    {
        $flat = [];
        foreach ($items as $i) {
            if (is_array($i)) $flat = array_merge($flat, $i);
            else $flat[] = $i;
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
                    throw \Webrek\MongoPermission\Exceptions\GuardDoesNotMatch::create($actualGuard, $expectedGuard);
                }
                $ids[] = (string) $e->getKey();
                continue;
            }
            $ids[] = (string) $permClass::findByName($e, $expectedGuard)->getKey();
        }
        return $ids;
    }
}
