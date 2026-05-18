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

## License

MIT
