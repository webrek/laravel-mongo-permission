<?php

namespace Webrek\MongoPermission\Models;

use Illuminate\Support\Collection;
use MongoDB\Laravel\Eloquent\Model;
use Webrek\MongoPermission\Contracts\Permission as PermissionContract;
use Webrek\MongoPermission\Events\PermissionCreated;
use Webrek\MongoPermission\Events\PermissionDeleted;
use Webrek\MongoPermission\Exceptions\PermissionAlreadyExists;
use Webrek\MongoPermission\Exceptions\PermissionDoesNotExist;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Support\AtomicArray;

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

            if (! array_key_exists('team_id', $perm->getAttributes()) || $perm->team_id === null) {
                if (config('permission.teams', false)) {
                    $perm->team_id = app(PermissionRegistrar::class)->getTeamId();
                }
            }

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
            event(new PermissionCreated($perm));
        });

        static::saved(function (): void {
            app(PermissionRegistrar::class)->bumpCacheVersion();
        });

        static::deleted(function (self $perm): void {
            $id = (string) $perm->getKey();

            // Resolve user model and its collection from Auth config (fallback 'users')
            $userClass = config('auth.providers.users.model');
            if ($userClass) {
                $userInstance = new $userClass;
                $collection = $userInstance->getConnection()
                    ->getMongoDB()
                    ->selectCollection($userInstance->getTable());
                // Remove both the structured form ({permission_id: id}) and the
                // legacy flat form (the bare id string).
                $collection->updateMany([], ['$pull' => ['permission_ids' => ['permission_id' => $id]]]);
                $collection->updateMany([], ['$pull' => ['permission_ids' => $id]]);
            }

            // Pull from roles.permission_ids
            $roleClass = config('permission.models.role');
            $roleClass::query()->where('permission_ids', $id)->each(function ($role) use ($id): void {
                AtomicArray::mutate($role, 'permission_ids', fn ($ids) => array_values(array_diff($ids, [$id])));
            });

            app(PermissionRegistrar::class)->bumpCacheVersion();
            event(new PermissionDeleted($perm));
        });
    }

    public static function findByName(string $name, ?string $guardName = null): self
    {
        $guard = $guardName ?? config('permission.default_guard');

        $perm = app(PermissionRegistrar::class)->catalog(static::class, $guard)->firstWhere('name', $name);

        if ($perm === null) {
            throw PermissionDoesNotExist::named($name, $guard);
        }

        return clone $perm;
    }

    public static function findById(string $id, ?string $guardName = null): self
    {
        $guard = $guardName ?? config('permission.default_guard');

        $perm = app(PermissionRegistrar::class)->catalog(static::class, $guard)->first(fn ($m) => (string) $m->getKey() === $id);

        if ($perm === null) {
            throw PermissionDoesNotExist::withId($id, $guard);
        }

        return clone $perm;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getGuardName(): string
    {
        return $this->guard_name;
    }

    /**
     * Roles que tienen este permiso (roles.permission_ids es un array plano de ids).
     */
    public function roles(): Collection
    {
        $roleClass = config('permission.models.role');

        return $roleClass::query()->where('permission_ids', (string) $this->getKey())->get();
    }
}
