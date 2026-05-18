# Changelog

All notable changes to `webrek/laravel-mongo-permission` are documented
here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.0] - 2026-05-18

### Added
- `permission:migrate-from-spatie` Artisan command. Reads the five
  canonical `spatie/laravel-permission` tables (`permissions`,
  `roles`, `role_has_permissions`, `model_has_roles`,
  `model_has_permissions`) plus a SQL users table out of a
  configurable connection (`--connection=`) and writes the
  equivalent documents into Mongo. Idempotent — a second run skips
  existing rows by `(name, guard, team)` unless `--force` is set.
  SQL users are matched to Mongo users by `--match-by=email` (or
  any other field). Supports `--dry-run`, `--skip-users`, and
  `--user-model=`. Unmatched SQL users are reported as a warning.

## [1.2.0] - 2026-05-18

### Added
- Role inheritance: `$role->inheritsFrom($parent)`,
  `$role->stopsInheritingFrom($parent)`, `$role->getAncestors()`,
  `$role->getAllPermissionIds()`. Multi-parent support with cycle
  detection (`RoleHierarchyCycle`) and depth bound
  (`RoleHierarchyTooDeep`, default `permission.role_hierarchy_max_depth = 5`).
- `RoleParentChanged` event with `action = 'attached'|'detached'`.
  The cache listener flushes the registrar on parent changes so
  every affected user picks up the change on the next read.
- `permission:list-users` Artisan command — list users with a given
  role or permission (with `--guard`, `--team`, `--user-model`).
  Permission listings include source: direct or via role name.
- `permission:check` Artisan command — explain why a user does or
  does not hold a given permission (direct, role, wildcard) with
  per-grant team and expiry annotations.
- `Webrek\MongoPermission\Testing\MongoPermissionAssertions` test
  helper trait: `assertUserHasRole`, `assertUserDoesNotHaveRole`,
  `assertUserHasAnyRole`, `assertUserHasAllRoles`,
  `assertUserHasPermission`, `assertUserDoesNotHavePermission`,
  `assertUserHasDirectPermission`, `assertRoleHasPermission`,
  `assertRoleDoesNotHavePermission`.
- PHPStan level 5 in CI (analyse job alongside the test matrix),
  with `phpstan.neon` ignoring Eloquent dynamic property access on
  Models and unused trait warnings.

### Changed
- `PermissionRegistrar` now expands a role's permission list through
  its inheritance chain when building cached entries, so transitive
  permissions resolve in `hasPermissionTo`.
- `HasRoles::getPermissionsViaRoles` walks the inheritance chain via
  `Role::getAllPermissionIds`.

### Removed
- Dead-code `is_array()` guards on string-typed middleware
  parameters (flagged by PHPStan).

## [1.1.0] - 2026-05-18

### Added
- `HasRoles::assignRoleUntil($role, $expiresAt)` — grant a role
  with an expiry timestamp.
- `HasPermissions::givePermissionToUntil($permission, $expiresAt)` —
  grant a permission with an expiry timestamp.
- `permission:prune-expired` Artisan command to garbage-collect
  expired grant subdocs from user documents, with `--dry-run` and
  `--user-model=` options.
- `Webrek\MongoPermission\Support\Expiry` helper centralizing
  expiry normalization between `DateTimeInterface`, BSON
  `UTCDateTime` and unix timestamps.
- Composer keywords (`laravel`, `mongodb`, `permissions`, `roles`,
  `rbac`, `acl`, `authorization`, `multi-tenant`, `wildcard`) and
  `support.issues` / `support.source` URLs for Packagist surfacing.
- CHANGELOG.md following Keep-a-Changelog.
- README section comparing this package with
  `spatie/laravel-permission`.
- README section "Expiring grants" documenting the new API and the
  prune command.

### Changed
- `PermissionRegistrar` slug cache now stores grant entries with
  their expiry attached and re-filters expired entries on every
  read. Slug arrays returned by `getUserPermissionSlugs` and
  `getUserRoleSlugs` are unchanged in shape.
- A role assignment's expiry propagates to every permission reached
  through that role: when the assignment expires, those permissions
  stop counting in `hasPermissionTo` and `getAllPermissions`.

## [1.0.0] - 2026-05-18

### Added
- Initial production release.
- `Role` and `Permission` Eloquent models backed by MongoDB.
- `HasRoles` and `HasPermissions` traits for user models with the
  full Spatie-style API: `assignRole`, `removeRole`, `syncRoles`,
  `givePermissionTo`, `revokePermissionTo`, `syncPermissions`,
  `hasRole`, `hasAnyRole`, `hasAllRoles`, `hasExactRoles`,
  `hasPermissionTo`, `hasDirectPermission`, `hasAnyPermission`,
  `hasAllPermissions`, `getAllPermissions`.
- Multi-guard support with `GuardDoesNotMatch` enforcement.
- Multi-tenant teams via `team_id` flowing through every read and
  write; configurable team resolver; strict isolation flag.
- Wildcard permissions with `.` separator (configurable), greedy
  trailing `*` and exact interior `*` matching.
- Eight lifecycle events: `RoleCreated`, `RoleDeleted`,
  `PermissionCreated`, `PermissionDeleted`, `RoleAttached`,
  `RoleDetached`, `PermissionAttached`, `PermissionDetached`.
- Request-scoped in-memory cache plus Laravel Cache layer keyed by
  `(user_id, team_id)` and `(guard, team_id)` catalog keys, with
  event-driven invalidation.
- Four route middlewares: `role`, `permission`, `role_or_permission`,
  `team-context`.
- Ten Blade directives: `@role`, `@elserole`, `@hasrole`,
  `@hasanyrole`, `@hasallroles`, `@unlessrole`, `@permission`,
  `@haspermission`, `@hasanypermission`, plus native `@can` via
  `Gate::before`.
- Five Artisan commands: `permission:create-indexes`,
  `permission:create-role`, `permission:create-permission`,
  `permission:show`, `permission:cache-reset`.
- Cascade deletion of `Role` and `Permission` removes the embedded
  references in user documents.
- GitHub Actions CI matrix across PHP 8.1, 8.2, 8.3 and Laravel 10,
  11, 12 against MongoDB 7, with `actions/checkout@v5`.
- 106 tests covering models, traits, events, cache, multi-guard,
  teams, strict isolation, wildcards, middlewares, Blade, Gate and
  commands.

[Unreleased]: https://github.com/webrek/laravel-mongo-permission/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/webrek/laravel-mongo-permission/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/webrek/laravel-mongo-permission/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/webrek/laravel-mongo-permission/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/webrek/laravel-mongo-permission/releases/tag/v1.0.0
