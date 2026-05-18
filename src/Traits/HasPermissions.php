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

        $merged = $this->permission_ids ?? [];
        foreach ($toAdd as $id) {
            $merged[] = ['permission_id' => $id, 'team_id' => null];
        }
        $this->permission_ids = $merged;
        $this->save();
        return $this;
    }

    public function revokePermissionTo(...$permissions): self
    {
        $ids = $this->resolvePermissionIds($this->flattenInput($permissions));
        $this->permission_ids = collect($this->permission_ids ?? [])
            ->reject(fn ($e) => in_array((string) ($e['permission_id'] ?? null), $ids, strict: true))
            ->values()
            ->all();
        $this->save();
        return $this;
    }

    public function syncPermissions(...$permissions): self
    {
        $ids = $this->resolvePermissionIds($this->flattenInput($permissions));
        $this->permission_ids = array_map(
            fn (string $id) => ['permission_id' => $id, 'team_id' => null],
            array_values(array_unique($ids)),
        );
        $this->save();
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

        return collect($this->permission_ids ?? [])
            ->contains(fn ($e) => (string) ($e['permission_id'] ?? null) === $id);
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
        return property_exists($this, 'guard_name') && $this->guard_name
            ? $this->guard_name
            : config('permission.default_guard');
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
        $ids = [];
        foreach ($entries as $e) {
            if ($e instanceof PermissionContract) {
                $ids[] = (string) $e->getKey();
                continue;
            }
            $ids[] = (string) $permClass::findByName($e, $this->guardName())->getKey();
        }
        return $ids;
    }
}
