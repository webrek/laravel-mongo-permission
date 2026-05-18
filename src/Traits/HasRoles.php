<?php

namespace Webrek\MongoPermission\Traits;

use Illuminate\Support\Collection;
use Webrek\MongoPermission\Contracts\Role as RoleContract;

trait HasRoles
{
    use HasPermissions;

    public function roles(): Collection
    {
        $roleClass = config('permission.models.role');
        $ids = collect($this->role_ids ?? [])->pluck('role_id')->all();
        return $roleClass::query()->whereIn('_id', $ids)->get();
    }

    public function assignRole(...$roles): self
    {
        $ids = $this->resolveRoleIds($this->flattenInput($roles));
        $current = collect($this->role_ids ?? [])
            ->map(fn ($e) => (string) ($e['role_id'] ?? null))
            ->all();

        $toAdd = array_diff($ids, $current);
        if (empty($toAdd)) {
            return $this;
        }

        $merged = $this->role_ids ?? [];
        foreach ($toAdd as $id) {
            $merged[] = ['role_id' => $id, 'team_id' => null];
        }
        $this->role_ids = $merged;
        $this->save();

        $roleClass = config('permission.models.role');
        foreach ($toAdd as $id) {
            $role = $roleClass::query()->where('_id', $id)->first();
            event(new \Webrek\MongoPermission\Events\RoleAttached(
                $this,
                $role,
                null,
                $this->guardName(),
            ));
        }

        return $this;
    }

    public function removeRole(...$roles): self
    {
        $ids = $this->resolveRoleIds($this->flattenInput($roles));
        $remaining = collect($this->role_ids ?? [])
            ->reject(fn ($e) => in_array((string) ($e['role_id'] ?? null), $ids, strict: true))
            ->values()
            ->all();

        $removed = array_diff(
            collect($this->role_ids ?? [])->map(fn ($e) => (string) ($e['role_id'] ?? null))->all(),
            collect($remaining)->map(fn ($e) => (string) ($e['role_id'] ?? null))->all(),
        );

        $this->role_ids = $remaining;
        $this->save();

        if (! empty($removed)) {
            $roleClass = config('permission.models.role');
            foreach ($removed as $id) {
                $role = $roleClass::query()->where('_id', $id)->first();
                if ($role) {
                    event(new \Webrek\MongoPermission\Events\RoleDetached(
                        $this,
                        $role,
                        null,
                        $this->guardName(),
                    ));
                }
            }
        }

        return $this;
    }

    public function syncRoles(...$roles): self
    {
        $targetIds = $this->resolveRoleIds($this->flattenInput($roles));
        $currentIds = collect($this->role_ids ?? [])
            ->map(fn ($e) => (string) ($e['role_id'] ?? null))
            ->all();

        $toRemove = array_diff($currentIds, $targetIds);
        $toAdd = array_diff($targetIds, $currentIds);

        if (! empty($toRemove) || ! empty($toAdd)) {
            $roleClass = config('permission.models.role');
            if (! empty($toRemove)) {
                $models = $roleClass::query()->whereIn('_id', $toRemove)->get()->all();
                if (! empty($models)) {
                    $this->removeRole($models);
                }
            }
            if (! empty($toAdd)) {
                $models = $roleClass::query()->whereIn('_id', $toAdd)->get()->all();
                if (! empty($models)) {
                    $this->assignRole($models);
                }
            }
        }
        return $this;
    }

    public function hasRole(string|array|RoleContract $role, ?string $guard = null): bool
    {
        $names = is_array($role) ? $role : [$role];
        foreach ($names as $r) {
            $id = $this->resolveRoleId($r, $guard);
            $hit = collect($this->role_ids ?? [])
                ->contains(fn ($e) => (string) ($e['role_id'] ?? null) === $id);
            if ($hit) return true;
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
            if (! $this->hasRole($r, $guard)) return false;
        }
        return true;
    }

    public function hasExactRoles(array $roles, ?string $guard = null): bool
    {
        if (count($this->role_ids ?? []) !== count($roles)) return false;
        return $this->hasAllRoles($roles, $guard);
    }

    public function getRoleNames(): Collection
    {
        return $this->roles()->pluck('name');
    }

    public function getPermissionsViaRoles(): Collection
    {
        $permClass = config('permission.models.permission');
        $permissionIds = $this->roles()->flatMap(fn ($r) => $r->permission_ids ?? [])->unique()->all();
        return $permClass::query()->whereIn('_id', $permissionIds)->get();
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
        if ($entry instanceof RoleContract) {
            return (string) $entry->getKey();
        }
        return (string) $roleClass::findByName($entry, $guard ?? $this->guardName())->getKey();
    }
}
