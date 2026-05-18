<?php

namespace Webrek\MongoPermission\Models;

use MongoDB\Laravel\Eloquent\Model;
use Webrek\MongoPermission\Contracts\Permission as PermissionContract;
use Webrek\MongoPermission\Exceptions\PermissionAlreadyExists;
use Webrek\MongoPermission\Exceptions\PermissionDoesNotExist;

class Permission extends Model implements PermissionContract
{
    protected $connection = 'mongodb';
    protected $guarded = [];

    public function getTable(): string
    {
        return config('permission.collection_names.permissions', 'permissions');
    }

    protected static function booted(): void
    {
        static::creating(function (self $perm): void {
            $perm->guard_name = $perm->guard_name ?? config('permission.default_guard');

            $existing = static::query()
                ->where('name', $perm->name)
                ->where('guard_name', $perm->guard_name)
                ->where('team_id', $perm->team_id)
                ->exists();

            if ($existing) {
                throw PermissionAlreadyExists::create($perm->name, $perm->guard_name);
            }
        });

        static::created(function (self $perm): void {
            event(new \Webrek\MongoPermission\Events\PermissionCreated($perm));
        });

        static::deleted(function (self $perm): void {
            $id = (string) $perm->getKey();

            // Resolve user model and its collection from Auth config (fallback 'users')
            $userClass = config('auth.providers.users.model');
            if ($userClass) {
                $userInstance = new $userClass;
                $userInstance->getConnection()
                    ->getMongoDB()
                    ->selectCollection($userInstance->getTable())
                    ->updateMany([], ['$pull' => ['permission_ids' => ['permission_id' => $id]]]);
            }

            // Pull from roles.permission_ids
            $roleClass = config('permission.models.role');
            $roleClass::query()->where('permission_ids', $id)->each(function ($role) use ($id): void {
                $role->permission_ids = array_values(array_diff($role->permission_ids ?? [], [$id]));
                $role->saveQuietly();
            });

            event(new \Webrek\MongoPermission\Events\PermissionDeleted($perm));
        });
    }

    public static function findByName(string $name, ?string $guardName = null): self
    {
        $guard = $guardName ?? config('permission.default_guard');

        $perm = static::query()
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->first();

        if ($perm === null) {
            throw PermissionDoesNotExist::named($name, $guard);
        }

        return $perm;
    }

    public static function findById(string $id, ?string $guardName = null): self
    {
        $guard = $guardName ?? config('permission.default_guard');

        $perm = static::query()
            ->where('_id', $id)
            ->where('guard_name', $guard)
            ->first();

        if ($perm === null) {
            throw PermissionDoesNotExist::withId($id, $guard);
        }

        return $perm;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getGuardName(): string
    {
        return $this->guard_name;
    }
}
