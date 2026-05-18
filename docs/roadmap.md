# Roadmap — webrek/laravel-mongo-permission

Drawn up 2026-05-18 after v1.0.0 shipped. Items ordered by impact vs
effort. Each section is concrete enough to start work on; the small
items list their own steps, the larger ones name the design decisions
that still need a sit-down before coding.

Versioning intent: SemVer. v1.x patches and additive features here.
Anything breaking is reserved for v2.0.

---

## Track A — Differentiators (v1.1 — v1.3)

These three are the reason a Laravel + MongoDB team would pick this
package over `spatie/laravel-permission`. Without at least one of
them, the package is "spatie-compatible on Mongo" — useful, but not
distinctive.

### A1. TTL / expiring grants (v1.1)

**Why.** MongoDB has native TTL indexes. Adding `expires_at` on a
grant subdocument and letting Mongo expire it for us is a few lines
of work and a real differentiator: spatie cannot do this without a
job scheduler. Use cases: temporary support-engineer access, trial
upgrades, scheduled access windows, "give intern editor for 30 days".

**API sketch.**
```php
$user->assignRole('admin', expiresAt: now()->addHours(2));
$user->givePermissionTo('publish posts', expiresAt: now()->addDays(7));
$user->hasRole('admin'); // false once the TTL has passed
```

**Schema changes.** Add `expires_at: ISODate | null` to the subdocs
embedded in `users.role_ids` and `users.permission_ids`:

```js
{ role_id: ObjectId, team_id: ObjectId|null, expires_at: ISODate|null }
```

**Filtering.** `HasRoles::roles()` and `HasPermissions::permissions()`
filter out subdocs where `expires_at !== null && expires_at <= now()`.
Cache slug arrays already get rebuilt on attach/detach events —
adding a per-grant filter is a one-liner inside `PermissionRegistrar`.

**Cleanup options (pick one before coding).**
1. **App-side filter only.** Expired grants stay in the document
   forever but never match. Simplest. Documents grow over time.
2. **Background prune command.** `permission:prune-expired` runs
   `updateMany` with `$pull` against `expires_at <= now()`. Manual
   cron.
3. **TTL index on a separate `grants` collection.** Move grants out
   of the user doc into a collection with `{expireAfterSeconds: 0}`
   index on `expires_at`. Mongo prunes automatically. Breaks the
   embedded model; bigger refactor.

Recommendation: **option 1 + provide option 2 as `permission:prune-expired`**.
TTL collection (option 3) is a v2 conversation — it touches the
fundamental data model.

**Events.** Reuse `RoleDetached` / `PermissionDetached` when option 2
prunes; add `?\DateTimeInterface $expiredAt` to the payload to let
audit listeners distinguish manual revocations from TTL expiry.

**Tests.** Travel time with `Carbon::setTestNow()`:
- assign → check before expiry → true
- assign → travel past expiry → check → false
- prune command → grant subdoc removed
- expired grant does not block re-assignment with new TTL

**Effort.** ~1 day. ~200 LOC + 8–10 tests.

---

### A2. Role hierarchy / inheritance (v1.2)

**Why.** The #1 open request on `spatie/laravel-permission` for years.
A real hierarchy ("admin inherits editor inherits viewer") cuts
permission catalogs by orders of magnitude in mature apps.

**API sketch.**
```php
$editor->inheritsFrom($viewer);     // editor gets viewer's perms
$admin->inheritsFrom($editor);      // and transitively viewer's
$admin->getAllPermissions();        // walks the inheritance graph
```

**Schema changes.** Add `parent_role_ids: ObjectId[]` to the `roles`
collection. Multi-parent inheritance allowed (diamond is fine, cycles
are not).

**Cycle detection.** On `inheritsFrom`, run a BFS from the new parent
walking `parent_role_ids` looking for `$this->id`. Throw
`RoleHierarchyCycle` if found. Cache the closure.

**Permission resolution.** When building the cached slug array for a
user, recursively flatten each role's permissions through its parent
chain. Memoize per-role transitive permission slug set, invalidate on
the same `PermissionAttached`/`Detached` events plus a new
`RoleParentChanged`.

**Decisions to make before coding.**
1. Single-parent vs multi-parent inheritance? Single is simpler and
   ~95% of users only need it. Recommendation: **multi-parent** with
   strict cycle detection. Costs nothing extra in Mongo terms.
2. Should removing a parent role from a user revoke transitively-
   granted permissions instantly? Yes — invalidate user cache on the
   role's parent change.
3. Should `hasDirectPermission` see transitive grants? **No.** Direct
   means "attached directly to the user", transitive permissions go
   through `hasPermissionTo` only.

**New errors.** `RoleHierarchyCycle`, `RoleHierarchyTooDeep`
(configurable max depth, default 5 to bound resolution time).

**New event.** `RoleParentChanged { Role $role, Role $parent, string
$action /* attached|detached */ }`.

**Tests.**
- Diamond inheritance resolves once (no duplicate slugs)
- Cycle throws on second inheritance call
- Max depth respected
- Cache invalidation: changing parent's perms reflects in child users

**Effort.** ~2-3 days. ~400 LOC + ~15 tests.

---

### A3. Migration command from spatie/laravel-permission (v1.3)

**Why.** Without this, no production team on spatie will move. With
it, you open the entire spatie user base as potential adopters. The
spec calls this out as a "future companion package"; the simplest
form is one Artisan command living in this repo.

**Scope.** One-way migration: SQL → Mongo. Idempotent (re-runnable).
Dry-run flag for safety.

**Command shape.**
```bash
php artisan permission:migrate-from-spatie \
    --connection=mysql \
    --user-model="App\\Models\\User" \
    --user-mongo-collection=users \
    --dry-run
```

**What it reads.**
- `roles`, `permissions`, `role_has_permissions`, `model_has_roles`,
  `model_has_permissions`. The five canonical spatie tables.
- For teams: also `team_id` from those pivot tables when
  `permission.teams = true` in the spatie config.

**What it writes.**
1. For each `permissions` row → upsert into mongo `permissions`
   collection by `(name, guard_name, team_id)`.
2. Same for `roles`, then resolve `role_has_permissions` and write
   `permission_ids` array.
3. For each user in `model_has_roles` / `model_has_permissions`:
   resolve the user by external ID (provide a mapper closure via
   command argument or a published config), push the appropriate
   subdoc into `role_ids` / `permission_ids`.

**Decisions to make.**
1. ID mapping. Spatie uses int PKs; Mongo uses ObjectId. The command
   needs a way to look up the Mongo user from the SQL user — usually
   by `email` or a `legacy_id` field. Make this a config callback.
2. Conflict handling. If a permission with the same name+guard+team
   already exists in Mongo, do we overwrite or skip? Default
   **skip**, with a `--force` flag to overwrite.
3. Guard mapping. Spatie's `guard_name` ports 1:1.

**Tests.** Spin up SQLite in addition to Mongo (already in
testbench), seed spatie tables, run the command, assert Mongo state.

**Effort.** ~3 days. ~500 LOC + integration tests.

---

## Track B — Adoption polish (v1.1, parallel)

Small, low-risk, high-visibility. Land them alongside A1.

### B1. composer.json keywords + support URLs

Pad `composer.json` with:
```json
{
  "keywords": ["laravel", "mongodb", "permissions", "roles", "rbac",
               "acl", "authorization", "multi-tenant", "wildcard"],
  "support": {
    "issues": "https://github.com/webrek/laravel-mongo-permission/issues",
    "source": "https://github.com/webrek/laravel-mongo-permission"
  }
}
```
Packagist surfaces these in search. ~10 minutes.

### B2. CHANGELOG.md (Keep-a-Changelog)

One file at repo root. Backfill v1.0.0 entry from the section list
already in the tag message. Going forward, add `## [Unreleased]`
section at the top and move entries into a version block on each
tag.

### B3. README "Why this vs spatie" section

Honest comparison, not marketing. Bullets:
- Multi-tenant `team_id` flows through every read/write natively
- Events carry `team_id` and `guard` for tenant-scoped audit
- No pivot tables — grants embedded in user doc, one read per check
- Cache key tuple `(user_id, team_id)` matches the access pattern
- MongoDB-native: required, not optional

End with "If your stack is SQL, use spatie." That builds trust.
Insert after `## Status`, before `## Caching`.

---

## Track C — Quality scaffolding (v1.2, parallel)

Visible quality signals for OSS adopters.

### C1. PHPStan level 6 in CI

Add `phpstan/phpstan: ^1.10` to require-dev, a `phpstan.neon` at
repo root targeting `src/` at level 6, and a new job in
`.github/workflows/ci.yml`:

```yaml
analyse:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v5
    - uses: shivammathur/setup-php@v2
      with: { php-version: '8.3', extensions: mongodb }
    - run: composer install --no-progress
    - run: vendor/bin/phpstan analyse --no-progress
```

Level 6 catches "no missing type hints" without the false positives
of 8/9. Adjust per finding. ~2 hours including fixes.

### C2. Test helpers trait

`src/Testing/MongoPermissionAssertions.php`:

```php
trait MongoPermissionAssertions
{
    public function assertUserHasRole($user, string $role, ?string $guard = null): void;
    public function assertUserDoesNotHaveRole($user, string $role): void;
    public function assertUserHasPermission($user, string $perm): void;
    public function assertUserHasDirectPermission($user, string $perm): void;
    public function assertRoleHasPermission($role, string $perm): void;
}
```

Consumer apps `use` the trait in their TestCase. Pure ergonomic
wrappers around the trait methods. ~1 hour.

---

## Track D — Operational commands (v1.2)

Low effort, real ops value. Land both in one commit.

### D1. permission:list-users

```bash
php artisan permission:list-users admin
php artisan permission:list-users --permission="edit articles"
php artisan permission:list-users admin --team=acme --guard=api
```

Mongo query against the user collection by `role_ids.role_id` (or
`permission_ids.permission_id`). Table output: id, name, email,
team, direct?/via-role-name.

User model resolution: take a class via config
`permission.user_model` (we should add this anyway). Falls back to
`config('auth.providers.users.model')`.

### D2. permission:check

```bash
php artisan permission:check {user_id} {permission} [--guard=]
```

Output a trace of WHY the answer is what it is:

```
User 65f... has 'edit articles'?  YES
  ✓ direct grant: id=64a..., team_id=null
  ✓ via role 'editor' (id=64b..., team_id=null)
  ✗ wildcard 'posts.*' does not imply 'edit articles'
```

Debugging gold. ~3 hours.

---

## Track E — Companion package (future, separate repo)

### E1. Filament v3/v4 adapter

`webrek/laravel-mongo-permission-filament` as a sibling repo.
Provides:
- Resource pages for Role, Permission with policies wired
- A "User permissions" relation manager for the User resource
- A "Team scope" filter on the panel
- Form fields with the wildcard warning baked in

Effort: ~1 week, mostly UI. Out of this repo's scope; track it
elsewhere.

---

## Suggested sequence

```
v1.1  →  A1 (TTL) + B1 + B2 + B3
v1.2  →  A2 (Hierarchy) + C1 + C2 + D1 + D2
v1.3  →  A3 (Spatie migration)
v1.4+ →  E1 (Filament) as separate repo
```

Each minor version stays additive — no breaking changes — so adopters
can upgrade without ceremony.

## Explicit non-goals (don't reopen)

- **Driver abstraction layer.** Married to MongoDB by design (spec §16).
- **Built-in admin UI.** Filament/Nova are the answer; adapters yes,
  UI in core no.
- **`HasTeams` trait.** The package consumes the active team, does
  not own it (spec §16).
- **Built-in soft deletes.** Trivial for consumers to extend; not
  worth the default complexity.
