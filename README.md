# webrek/laravel-mongo-permission

Role and permission management for Laravel — MongoDB native.

API-compatible with `spatie/laravel-permission` for the methods most
people use day to day, but the data model, queries, and cache strategy
are designed around MongoDB.

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

This package is under active development. The current release (Plan 1)
covers models, traits, and cascade deletion. Cache, multi-tenant teams,
multi-guard, middleware, Blade directives and wildcard permissions are
planned for follow-up releases.

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

## License

MIT
