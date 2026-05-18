# webrek/laravel-mongo-permission

Role and permission management for Laravel — MongoDB native.

API-compatible with `spatie/laravel-permission` for the methods most
people use day to day, but the data model, queries, and cache strategy
are designed around MongoDB.

## Requirements

| Dependency | Versions |
|---|---|
| PHP | 8.1, 8.2, 8.3 |
| Laravel | 10.x, 11.x, 12.x |
| MongoDB server | 7.x |
| `mongodb/laravel-mongodb` | `^4.0` \| `^5.0` |
| PHP `mongodb` extension | required |

CI runs every release against the full PHP × Laravel matrix above, excluding the combinations Laravel itself does not support (Laravel 11 and 12 require PHP 8.2+).

## Install

```bash
composer require webrek/laravel-mongo-permission
php artisan vendor:publish --tag=permission-config
php artisan permission:create-indexes
```

## Quick start

```php
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Traits\HasRoles;

class User extends Authenticatable {
    use HasRoles;
}

Permission::create(['name' => 'edit articles']);
$role = Role::create(['name' => 'editor']);
$role->givePermissionTo('edit articles');

$user->assignRole('editor');
$user->hasPermissionTo('edit articles'); // true
```

## Status

v1.0 — ready for production. Covers single + multi-tenant Laravel apps,
multi-guard auth, request-scoped + persistent caching, eight lifecycle
events, wildcard permissions, route middleware, Blade directives, Gate
integration, and a full set of Artisan commands.

## Caching

`hasPermissionTo` and `hasRole` consult an in-memory + Laravel Cache
layer keyed by `(user_id, team_id)`. Mutations through `assignRole`,
`removeRole`, `givePermissionTo`, `revokePermissionTo`, and
`syncRoles`/`syncPermissions` invalidate the affected keys via package
events.

Flush manually if needed:

```bash
php artisan permission:cache-reset
```

Configure the cache store and key namespace in `config/permission.php`
under the `cache` key.

**Known limitation:** changing the permission catalog of a role
(e.g. `$role->givePermissionTo(...)` / `$role->revokePermissionTo(...)`)
does not automatically invalidate the cached slug arrays of every user
holding that role — invalidation is per-user, fired by per-user attach
or detach events. Run `permission:cache-reset` after bulk role-catalog
edits, or rebuild the cache user-by-user.

## Multi-guard

Every `Role` and `Permission` is scoped by `guard_name`. The same name
can exist in multiple guards independently. The guard for an operation
resolves in this order:

1. Explicit argument: `$user->hasRole('admin', 'api')`
2. `protected string $guard_name` property on the user model
3. `auth.defaults.guard`
4. `config('permission.default_guard')`

Mismatched guards on `assignRole` / `givePermissionTo` calls with model
instances throw `GuardDoesNotMatch`.

## Multi-tenant teams

Set `permission.teams = true` (default) and either call
`setPermissionsTeamId('your-team-id')` manually or supply a closure in
`permission.team_resolver`:

```php
'team_resolver' => fn () =>
    request()->user()?->current_team_id
    ?? request()->header('X-Team-Id'),
```

Assignments made while a team is active are scoped to that team. Reads
honor the active team. Setting `permission.strict_team_isolation = true`
disables the "team_id = null is global" fallback.

## Wildcard permissions

`enable_wildcard_permission` defaults to `true`. Patterns use `.` as the
separator (configurable via `permission.wildcard_separator`). A trailing
`*` is greedy and matches all remaining segments; interior `*` matches
exactly one segment; a sole `*` matches any non-empty name.

```php
Permission::create(['name' => 'posts.*']);
$user->givePermissionTo('posts.*');
$user->hasPermissionTo('posts.edit');         // true
$user->hasPermissionTo('posts.edit.own');     // true
```

## Middleware

```php
Route::get('/admin', ...)->middleware('role:admin');
Route::get('/edit',  ...)->middleware('permission:edit articles');
Route::get('/x',     ...)->middleware('role_or_permission:admin|edit articles');
Route::get('/teams/{team}/admin', ...)
    ->middleware(['team-context:team', 'role:admin']);
```

Denied requests throw `Webrek\MongoPermission\Exceptions\UnauthorizedException`
(HTTP 403). Register a custom exception handler if you want a different
response shape.

## Blade directives

```blade
@role('admin') ... @endrole
@hasanyrole('admin|editor') ... @endhasanyrole
@hasallroles('admin|editor') ... @endhasallroles
@unlessrole('guest') ... @endunlessrole

@permission('edit articles') ... @endpermission
@haspermission('edit articles') ... @endhaspermission
@hasanypermission('edit|delete') ... @endhasanypermission

@can('edit articles') ... @endcan   {{-- native Laravel, routed via Gate::before --}}
```

## Gate integration

The package installs a `Gate::before` hook so `$user->can()`, `@can`, and
controller `authorize()` calls consult `hasPermissionTo`. Unknown
permission names return `null` from the hook so the rest of the Gate
stack (Policies, manually-defined gates) still runs.

## Events

The package dispatches eight lifecycle events. Subscribe to them for
audit logging, cache extensions, or custom side-effects.

| Event | Payload |
|---|---|
| `RoleCreated` | `Role $role` |
| `RoleDeleted` | `Role $role` |
| `PermissionCreated` | `Permission $permission` |
| `PermissionDeleted` | `Permission $permission` |
| `RoleAttached` | `mixed $user, Role $role, ?string $teamId, string $guard` |
| `RoleDetached` | `mixed $user, Role $role, ?string $teamId, string $guard` |
| `PermissionAttached` | `mixed $model, Permission $permission, ?string $teamId, string $guard` |
| `PermissionDetached` | `mixed $model, Permission $permission, ?string $teamId, string $guard` |

`PermissionAttached` / `PermissionDetached` carry the model that
received the change — a `User` instance or a `Role` instance — so
listeners can branch on the case. All `*Attached` / `*Detached`
events include the active `team_id` and `guard`, enabling per-tenant
auditing without re-querying.

All event classes live in `Webrek\MongoPermission\Events`.

## Artisan commands

```bash
php artisan permission:create-indexes
php artisan permission:create-role admin [--guard=web] [perm1 perm2 ...]
php artisan permission:create-permission "edit articles" [--guard=web]
php artisan permission:show [--guard=web] [--team=...]
php artisan permission:cache-reset
```

## Configuration

Published to `config/permission.php`:

| Key | Default | Description |
|---|---|---|
| `models.role` | `Webrek\MongoPermission\Models\Role` | Concrete Role class — swap to extend |
| `models.permission` | `Webrek\MongoPermission\Models\Permission` | Concrete Permission class — swap to extend |
| `collection_names.roles` | `'roles'` | Mongo collection for roles |
| `collection_names.permissions` | `'permissions'` | Mongo collection for permissions |
| `guard_names` | `['web', 'api']` | Guards the package will validate against |
| `default_guard` | `'web'` | Fallback when no guard can be resolved |
| `teams` | `true` | Enable multi-tenant scoping by `team_id` |
| `team_resolver` | `fn () => null` | Closure that returns the active `team_id` |
| `strict_team_isolation` | `false` | If true, `team_id = null` no longer matches every team |
| `enable_wildcard_permission` | `true` | Toggle wildcard matching in `hasPermissionTo` |
| `wildcard_separator` | `'.'` | Segment separator for wildcard patterns |
| `throw_on_missing_permission` | `true` | Throw `PermissionDoesNotExist` for unknown names instead of returning `false` |
| `handle_unauthorized` | `true` | Let middleware throw 403 `UnauthorizedException` |
| `cache.store` | `'default'` | Laravel Cache store for slug/catalog keys |
| `cache.key` | `'mongo-permission'` | Namespace prefix for all package cache keys |
| `cache.expiration_time` | `null` | `null` = forever (trust event-driven invalidation) |

## Testing locally

```bash
docker compose up -d mongo
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
```

The repository includes a `docker-compose.yml` that boots MongoDB 7
with a healthcheck so the test suite starts as soon as the database
is ready. No PHP or Mongo install on the host is required.

## License

MIT
