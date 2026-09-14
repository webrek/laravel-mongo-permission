<?php

namespace Webrek\MongoPermission\Models;

use Illuminate\Support\Collection;
use MongoDB\Laravel\Eloquent\Model;
use Webrek\MongoPermission\Contracts\Permission as PermissionContract;
use Webrek\MongoPermission\Contracts\Role as RoleContract;
use Webrek\MongoPermission\Events\PermissionAttached;
use Webrek\MongoPermission\Events\PermissionDetached;
use Webrek\MongoPermission\Events\RoleCreated;
use Webrek\MongoPermission\Events\RoleDeleted;
use Webrek\MongoPermission\Events\RoleParentChanged;
use Webrek\MongoPermission\Exceptions\RoleAlreadyExists;
use Webrek\MongoPermission\Exceptions\RoleDoesNotExist;
use Webrek\MongoPermission\Exceptions\RoleHierarchyCycle;
use Webrek\MongoPermission\Exceptions\RoleHierarchyTooDeep;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Support\AtomicArray;
use Webrek\MongoPermission\Support\TeamScope;

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

            if (! array_key_exists('team_id', $role->getAttributes()) || $role->team_id === null) {
                if (config('permission.teams', false)) {
                    $role->team_id = app(PermissionRegistrar::class)->getTeamId();
                }
            }

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
            event(new RoleCreated($role));
        });

        static::saved(function (): void {
            app(PermissionRegistrar::class)->bumpCacheVersion();
        });

        static::deleted(function (self $role): void {
            $id = (string) $role->getKey();
            $userClass = config('auth.providers.users.model');
            if ($userClass) {
                $userInstance = new $userClass;
                $collection = $userInstance->getConnection()
                    ->getMongoDB()
                    ->selectCollection($userInstance->getTable());
                // Remove both the structured form ({role_id: id}) and the legacy
                // flat form (the bare id string) so old data is cleaned up too.
                $collection->updateMany([], ['$pull' => ['role_ids' => ['role_id' => $id]]]);
                $collection->updateMany([], ['$pull' => ['role_ids' => $id]]);
            }

            app(PermissionRegistrar::class)->bumpCacheVersion();
            event(new RoleDeleted($role));
        });
    }

    public static function findByName(string $name, ?string $guardName = null): self
    {
        $guard = $guardName ?? config('permission.default_guard');

        $role = app(PermissionRegistrar::class)->catalog(static::class, $guard)->firstWhere('name', $name);

        if ($role === null) {
            throw RoleDoesNotExist::named($name, $guard);
        }

        return clone $role;
    }

    public static function findById(string $id, ?string $guardName = null): self
    {
        $guard = $guardName ?? config('permission.default_guard');

        $role = app(PermissionRegistrar::class)->catalog(static::class, $guard)->first(fn ($m) => (string) $m->getKey() === $id);

        if ($role === null) {
            throw RoleDoesNotExist::withId($id, $guard);
        }

        return clone $role;
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

    /**
     * Usuarios que tienen este rol. Soporta role_ids plano (["id"]) y
     * estructurado ([{role_id: "id"}]) para compatibilidad con datos legacy.
     */
    public function users(): Collection
    {
        $userClass = config('auth.providers.users.model');
        if (! $userClass) {
            return collect();
        }
        $id = (string) $this->getKey();

        return $userClass::query()
            ->where(function ($q) use ($id): void {
                $q->where('role_ids', $id)
                    ->orWhere('role_ids.role_id', $id);
            })
            ->get();
    }

    public function givePermissionTo(...$permissions): self
    {
        return $this->changePermissions($permissions, 'add');
    }

    public function revokePermissionTo(...$permissions): self
    {
        return $this->changePermissions($permissions, 'remove');
    }

    public function syncPermissions(...$permissions): self
    {
        return $this->changePermissions($permissions, 'sync');
    }

    protected function changePermissions(array $permissions, string $operation): self
    {
        $ids = $this->resolvePermissionIds($this->flatten($permissions));
        [$before,$after] = AtomicArray::mutate($this, 'permission_ids', function ($current) use ($ids, $operation) {
            return match ($operation) {
                'add' => array_values(array_unique(array_merge($current, $ids))),
                'remove' => array_values(array_diff($current, $ids)),
                default => $ids,
            };
        });
        if ($before == $after) {
            return $this;
        }
        app(PermissionRegistrar::class)->bumpCacheVersion();
        $class = config('permission.models.permission');
        $changed = $class::query()->whereIn('_id', array_merge(array_diff($before, $after), array_diff($after, $before)))->get();
        foreach ($changed as $permission) {
            $event = in_array((string) $permission->getKey(), $after, true)
                ? PermissionAttached::class : PermissionDetached::class;
            event(new $event($this, $permission, $this->team_id, $this->guard_name));
        }

        return $this;
    }

    public function hasPermissionTo(string|PermissionContract $permission): bool
    {
        $id = is_string($permission)
            ? (string) config('permission.models.permission')::findByName($permission, $this->guard_name)->getKey()
            : (string) $permission->getKey();

        return in_array($id, array_map('strval', $this->permission_ids ?? []), strict: true);
    }

    public function inheritsFrom(RoleContract $parent): self
    {
        return app(PermissionRegistrar::class)->withLock('hierarchy:'.static::class.':'.$this->guard_name,
            fn () => $this->attachParent($parent));
    }

    protected function attachParent(RoleContract $parent): self
    {
        TeamScope::validate($parent, $this->guard_name, $this->team_id);
        $selfId = (string) $this->getKey();
        $parentId = (string) $parent->getKey();
        // Evaluate the proposed graph, including descendants whose depth grows.
        $graph = static::query()->where('guard_name', $this->guard_name)->get()->keyBy(fn ($r) => (string) $r->getKey());
        $edges = [];
        foreach ($graph as $id => $role) {
            $edges[$id] = array_map('strval', $role->parent_role_ids ?? []);
        }
        $edges[$selfId] = array_values(array_unique(array_merge($edges[$selfId] ?? [], [$parentId])));
        $affected = [$selfId => true];
        do {
            $changed = false;
            foreach ($edges as $id => $parents) {
                if (! isset($affected[$id]) && array_intersect($parents, array_keys($affected))) {
                    $affected[$id] = true;
                    $changed = true;
                }
            }
        } while ($changed);
        $depths = [];
        $visiting = [];
        $walk = function (string $id) use (&$walk, &$visiting, &$depths, $edges, $parent): int {
            if (isset($visiting[$id])) {
                throw RoleHierarchyCycle::detected($this->name, $parent->getName());
            }
            if (isset($depths[$id])) {
                return $depths[$id];
            }
            $visiting[$id] = true;
            $depth = 0;
            foreach ($edges[$id] ?? [] as $pid) {
                $depth = max($depth, 1 + $walk($pid));
            }
            unset($visiting[$id]);

            return $depths[$id] = $depth;
        };
        $maxDepth = (int) config('permission.role_hierarchy_max_depth', 5);
        foreach (array_keys($affected) as $id) {
            if ($walk((string) $id) > $maxDepth) {
                throw RoleHierarchyTooDeep::exceeded($this->name, $maxDepth);
            }
        }

        $current = array_map('strval', $this->fresh()->parent_role_ids ?? []);
        $parentId = (string) $parent->getKey();
        if (in_array($parentId, $current, strict: true)) {
            return $this;
        }
        $current[] = $parentId;
        $this->parent_role_ids = $current;
        $this->save();

        event(new RoleParentChanged($this, $parent, 'attached'));

        return $this;
    }

    public function stopsInheritingFrom(RoleContract $parent): self
    {
        return app(PermissionRegistrar::class)->withLock('hierarchy:'.static::class.':'.$this->guard_name,
            fn () => $this->detachParent($parent));
    }

    protected function detachParent(RoleContract $parent): self
    {
        $current = array_map('strval', $this->fresh()->parent_role_ids ?? []);
        $parentId = (string) $parent->getKey();
        if (! in_array($parentId, $current, strict: true)) {
            return $this;
        }
        $this->parent_role_ids = array_values(array_diff($current, [$parentId]));
        $this->save();

        event(new RoleParentChanged($this, $parent, 'detached'));

        return $this;
    }

    public function getAncestors(array &$memo = []): Collection
    {
        $maxDepth = (int) config('permission.role_hierarchy_max_depth', 5);
        $visited = [(string) $this->getKey() => true];
        $stack = array_map('strval', $this->parent_role_ids ?? []);
        $ancestors = [];
        $depth = 0;
        while ($stack && $depth < $maxDepth) {
            $missing = array_values(array_filter($stack, fn ($id) => ! array_key_exists($id, $memo)));
            if ($missing) {
                foreach ($missing as $id) {
                    $memo[$id] = null;
                }
                foreach ($this->newQuery()->whereIn('_id', $missing)->getModels() as $role) {
                    $memo[(string) $role->getKey()] = $role;
                }
            }
            $next = [];
            foreach ($stack as $id) {
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $role = $memo[$id] ?? null;
                if (! $role || $role->guard_name !== $this->guard_name || ! TeamScope::catalog($role, $this->team_id)) {
                    continue;
                }
                $ancestors[] = $role;
                foreach ($role->parent_role_ids ?? [] as $pid) {
                    $next[] = (string) $pid;
                }
            }
            $stack = array_values(array_unique($next));
            $depth++;
        }

        return collect($ancestors);
    }

    /** @return array<int, string> */
    public function getAllPermissionIds(array &$memo = []): array
    {
        $ids = array_map('strval', $this->permission_ids ?? []);
        foreach ($this->getAncestors($memo) as $ancestor) {
            $ids = array_merge($ids, array_map('strval', $ancestor->permission_ids ?? []));
        }

        return array_values(array_unique($ids));
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
            $permission = $entry instanceof PermissionContract ? $entry : $permClass::findByName($entry, $this->guard_name);
            TeamScope::validate($permission, $this->guard_name, $this->team_id);
            $ids[] = (string) $permission->getKey();
        }

        return array_values(array_unique($ids));
    }
}
