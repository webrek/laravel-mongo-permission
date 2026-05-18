<?php

namespace Webrek\MongoPermission\Models;

use Illuminate\Support\Collection;
use MongoDB\Laravel\Eloquent\Model;
use Webrek\MongoPermission\Contracts\Permission as PermissionContract;
use Webrek\MongoPermission\Contracts\Role as RoleContract;
use Webrek\MongoPermission\Exceptions\RoleAlreadyExists;
use Webrek\MongoPermission\Exceptions\RoleDoesNotExist;

class Role extends Model implements RoleContract
{
    protected $connection = 'mongodb';
    protected $guarded = [];

    public function getTable(): string
    {
        return config('permission.collection_names.roles', 'roles');
    }

    protected static function booted(): void
    {
        static::creating(function (self $role): void {
            $role->guard_name = $role->guard_name ?? config('permission.default_guard');
            $role->permission_ids = $role->permission_ids ?? [];

            $existing = static::query()
                ->where('name', $role->name)
                ->where('guard_name', $role->guard_name)
                ->where('team_id', $role->team_id)
                ->exists();

            if ($existing) {
                throw RoleAlreadyExists::create($role->name, $role->guard_name);
            }
        });

        static::created(function (self $role): void {
            event(new \Webrek\MongoPermission\Events\RoleCreated($role));
        });

        static::deleted(function (self $role): void {
            $id = (string) $role->getKey();
            $userClass = config('auth.providers.users.model');
            if ($userClass) {
                $userInstance = new $userClass;
                $userInstance->getConnection()
                    ->getMongoDB()
                    ->selectCollection($userInstance->getTable())
                    ->updateMany([], ['$pull' => ['role_ids' => ['role_id' => $id]]]);
            }

            event(new \Webrek\MongoPermission\Events\RoleDeleted($role));
        });
    }

    public static function findByName(string $name, ?string $guardName = null): self
    {
        $guard = $guardName ?? config('permission.default_guard');

        $role = static::query()
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->first();

        if ($role === null) {
            throw RoleDoesNotExist::named($name, $guard);
        }

        return $role;
    }

    public static function findById(string $id, ?string $guardName = null): self
    {
        $guard = $guardName ?? config('permission.default_guard');

        $role = static::query()
            ->where('_id', $id)
            ->where('guard_name', $guard)
            ->first();

        if ($role === null) {
            throw RoleDoesNotExist::withId($id, $guard);
        }

        return $role;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getGuardName(): string
    {
        return $this->guard_name;
    }

    public function permissions(): Collection
    {
        $permClass = config('permission.models.permission');
        return $permClass::query()->whereIn('_id', $this->permission_ids ?? [])->get();
    }

    public function givePermissionTo(...$permissions): self
    {
        $ids = $this->resolvePermissionIds($this->flatten($permissions));
        $current = $this->permission_ids ?? [];
        $toAdd = array_diff($ids, $current);

        if (empty($toAdd)) {
            return $this;
        }

        $this->permission_ids = array_values(array_unique(array_merge($current, $toAdd)));
        $this->save();

        $permClass = config('permission.models.permission');
        foreach ($toAdd as $id) {
            $perm = $permClass::query()->where('_id', $id)->first();
            event(new \Webrek\MongoPermission\Events\PermissionAttached(
                $this,
                $perm,
                $this->team_id,
                $this->guard_name,
            ));
        }

        return $this;
    }

    public function revokePermissionTo(...$permissions): self
    {
        $ids = $this->resolvePermissionIds($this->flatten($permissions));
        $current = $this->permission_ids ?? [];
        $remaining = array_values(array_diff($current, $ids));
        $removed = array_diff($current, $remaining);

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
                        $this->team_id,
                        $this->guard_name,
                    ));
                }
            }
        }

        return $this;
    }

    public function syncPermissions(...$permissions): self
    {
        $ids = $this->resolvePermissionIds($this->flatten($permissions));
        $this->permission_ids = array_values(array_unique($ids));
        $this->save();
        return $this;
    }

    public function hasPermissionTo(string|PermissionContract $permission): bool
    {
        $id = is_string($permission)
            ? (string) config('permission.models.permission')::findByName($permission, $this->guard_name)->getKey()
            : (string) $permission->getKey();

        return in_array($id, array_map('strval', $this->permission_ids ?? []), strict: true);
    }

    protected function flatten(array $items): array
    {
        $flat = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $flat = array_merge($flat, $item);
            } else {
                $flat[] = $item;
            }
        }
        return $flat;
    }

    protected function resolvePermissionIds(array $names): array
    {
        $permClass = config('permission.models.permission');
        $ids = [];
        foreach ($names as $entry) {
            if ($entry instanceof PermissionContract) {
                $ids[] = (string) $entry->getKey();
                continue;
            }
            $ids[] = (string) $permClass::findByName($entry, $this->guard_name)->getKey();
        }
        return $ids;
    }
}
