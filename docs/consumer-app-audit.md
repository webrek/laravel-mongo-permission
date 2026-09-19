# Consumer application audit corrections

This report records the corrections verified locally on **2026-09-14** on the `fix/permission-audit` branch. The consumer application uses the package through a Composer path repository and ran these corrections. They were subsequently merged through [PR #1](https://github.com/webrek/laravel-mongo-permission/pull/1) and released in [v2.0.0](https://github.com/webrek/laravel-mongo-permission/releases/tag/v2.0.0). See [release validation](release-validation.md) for the later, expanded validation results.

## Validation after the initial corrections

- Package suite: **211 tests, 354 assertions**, all passing. Includes the original 172 tests and 39 regression, security and concurrency tests.
- Laravel application: **35 tests, 84 assertions**, all passing: 28 audit scenarios and seven functional flows.
- PHPStan level 5: **no errors**, with no new exclusions or suppressions.
- File cache: four independent processes, 40 increments each; exactly **160** increments, with no lost updates.
- `permission:create-indexes` ran in the lab. The local application was available at http://127.0.0.1:8018.

The cross-team inheritance test now verifies that the API rejects the edge with `TeamDoesNotMatch`. Another test inserts an invalid legacy edge and verifies that reads do not grant its permission either. Access must remain denied, and this is checked on both writes and reads.

## Changes

Shared team and guard scoping; lookups that prefer the team's catalog with a global fallback; synchronization that preserves other teams and guards; grant renewal; compare-and-swap for assignment arrays and role permissions; cache generations protected by shared locks; generation reads across processes; configured store and TTL support; invalidation without flushing unrelated cache; descendant depth validation and locks for hierarchy changes; commands supporting inheritance, wildcards and legacy data; reverse indexes on user assignments.

## Performance: the same operations and synthetic data

| Operation | Before | After |
|---|---:|---:|
| 100 denied permission checks | 100 find queries | 1 find query |
| 100 hasRole checks by name | 100 find queries | 2 find queries |
| Permission check with 10 roles and a shared parent | 12 find queries | 4 find queries |
| Assign 100 permissions | 200 find + 1 update | 3 find + 1 getMore + 1 update |
| Reverse user lookup by role | 1,004 documents examined | 1 document examined |

Initial reads populate the cache; subsequent checks reuse it. The first allowed permission check increases from two to three queries because the user is read again before rebuilding authorization. The 100 allowed checks with a warm cache still issue zero MongoDB queries, but the sample increases from 0.592 ms to 3.392 ms because shared generations are checked. Local timings are illustrative, not percentiles or production guarantees. See `permission-lab/docs/benchmark.json` and `benchmark-after.json` in the separate consumer application for the complete command counts.

## Compatibility and usage

- Use a shared Laravel cache store supporting atomic locks (for example, file or Redis) for multiple workers. Array cache is appropriate for isolated tests.
- The default cache lifetime is now 86400 seconds; a published configuration with a null TTL uses the same fallback. Retired generations expire through TTL; reset changes the generation without purging other modules' keys.
- Global catalog definitions remain reusable, but assignments belong to a team. Explicit operations with another team's models throw `TeamDoesNotMatch`.
- Grant mutations atomically update their assignment arrays and emit package events. They do not save other dirty model attributes; call `save()` separately for changes unrelated to permissions.
- Direct query-builder writes do not dispatch model events. Use the package APIs or explicitly invalidate caches after bulk catalog edits.
- Initial validation did not include the full matrix or Redis. Both were tested later: see [release validation](release-validation.md). Octane and Redis Cluster/failover remain outside this validation.

## Reproducing the checks

From the package directory:

```sh
MONGO_DB_HOST=127.0.0.1 MONGO_DB_PORT=27018 MONGO_DB_DATABASE=permission_baseline_test php vendor/bin/phpunit
php vendor/bin/phpstan analyse --memory-limit=1G
```

From the separate consumer application (`permission-lab`):

```sh
php artisan test
composer lab:benchmark
```

Tests use databases ending in `_test`, and the benchmark resets only `permission_benchmark_test`.

---

# Historical evidence: audit before the corrections

The following results and proposals describe baseline commit `d3cb5a4`, before the correction branch. Historical failures are retained as evidence, not as current outstanding issues. Statements about unimplemented fixes and missing validation below refer to that baseline.

## Laravel + MongoDB consumer application audit

Audit date: 2026-09-13. Reviewed code: `webrek/laravel-mongo-permission` main `d3cb5a4` (the v1.7.0 commit is `227ea3d`).

### Environment and method

The application was located at `/Users/victor/Sites/permission-lab`, with the package linked from `/Users/victor/Sites/permisos`. Laravel 12.69.2, PHP 8.3.31, mongodb extension 1.21.7, mongodb/laravel-mongodb 5.11.0, and MongoDB 7 in Docker on local port 27018. The application implemented authentication and article workflows backed by MongoDB, with isolated tests in `permission_lab_test`.

The initial local checkout (`92d5cb6`) was nine commits behind and was fast-forwarded to origin/main. Three invalidation problems involving role synchronization, revocation and deletion reproduced in that checkout, but **were already fixed in the audited baseline**. They should not be filed as new bugs. The audit verified that `src/` and `config/` had no differences between v1.7.0 and the audited main commit.

The existing package suite passed: **172 tests, 286 assertions**, using the locally available dependencies. This did not validate the full CI matrix. The platform included HTTP tests for authentication, pages, creation and assignment, 403 restrictions and the editorial workflow; see `composer lab:test` in the consumer application.

### Reproducible baseline cases

Run `composer lab:audit` from the consumer application. The methods are in `tests/Feature/PackageAuditTest.php`. Names below omit the `test_` prefix.

| Priority | Case | Observed result | Expected result / implication |
|---|---|---|---|
| High | `role_name_resolves_in_active_team` | After creating reviewer in alpha and beta, findByName in beta returns alpha's role. | Resolve within the active team; a repeated name must not select another tenant. |
| High | `same_role_can_be_granted_in_two_teams` | Granting the same global role in alpha and beta preserves only alpha's assignment. | Assignment identity must include both team and role. |
| High | `removing_in_another_team_preserves_original_grant` | removeRole in beta removes alpha's assignment even though beta did not have it. | Revocation must be limited to the active team. |
| High | `role_rejects_permission_from_other_guard` | A web role accepts a Permission instance with the api guard without an exception. | Apply GuardDoesNotMatch to Role mutations as well. |
| Medium | `cache_reset_preserves_unrelated_application_data` | permission:cache-reset deletes a key unrelated to the package. | Invalidate only the package namespace; the baseline uses Cache::flush. |
| Medium | `missing_permission_can_return_false_when_configured` | throw_on_missing_permission=false still throws PermissionDoesNotExist. | Respect the documented option and return false. |
| Medium | `expired_role_can_be_renewed` | Reassigning an expired role with a future date does not renew the grant. | Allow renewal or provide an explicit API; the baseline silently ignores it. |

Baseline result: **seven failures, three passing cases, 15 assertions**. Team cases enable `teams=true` and `strict_team_isolation=true`; the UI keeps `teams=false`. Renewal was classified as missing functionality or undocumented behavior, as well as a failed expectation.

### Causes and proposed corrections at the time

1. `Models/Role::findByName` filters by name and guard, but not team_id. Define a consistent team-resolution and global-fallback policy, including permissions and lookups by ID.
2. `Traits/HasRoles::attachRoles` deduplicates only by role_id. Use the (role_id, team_id) pair; review the same pattern for direct permissions.
3. `Traits/HasRoles::removeRole` removes by role_id without scoping. Scope remove and sync operations and emit the correct team for cache invalidation.
4. `Models/Role::resolvePermissionIds` accepts instances without validating their guard. Validate before saving any change, including syncPermissions.
5. `PermissionRegistrar::flush` calls Cache::flush. Use generation-based invalidation without touching application keys. Also respect the configured store and expiration.
6. `Traits/HasPermissions::hasPermissionTo` calls findByName without respecting throw_on_missing_permission. Make the exception conditional on the option.
7. `attachRoles` returns early when the ID already exists, even if the grant has expired. Define replacement/renewal behavior that updates expires_at and invalidates caches.

At this stage, these corrections were proposed but not implemented. The package source was kept unchanged from origin/main as a reproducible baseline.

### Coverage and useful follow-up capabilities

The initial audit was not exhaustive and did not include concurrency, load, or every guard and configuration. Using the panel suggested the following capabilities:

- Renew grants and change their expiration through an explicit API.
- Consistent assignment, synchronization and revocation within a team.
- Explain the source of an effective permission (direct, role, ancestor or wildcard) for support and access inspection.
- Persistent access-change auditing in the consumer application; package events are a foundation, not a durable history.

The browser flow and HTTP tests were documented in the application's README. No release had been published and no external issues had been filed at this stage.

### Live Safari usage

The editor signed in, created an article titled "Live test: editorial workflow" (translated title), and verified that the Publish action was absent. Direct navigation to /roles returned a 403 page. An administrator then signed in, granted articles.publish to the editor role, and saved it. In a new editor session, the permission appeared as Allowed and the article was successfully published without manually clearing the cache. Finally, the editor role was restored to read and create permissions. The published article was retained as evidence of the flow.

### Extended audit: security, consistency and performance

The second round added 18 scenarios: 16 failed and two passed. The combined result was **28 scenarios: 23 failing and five passing, 42 assertions**. Some roles and permissions shared root causes, so this did not represent 23 independent bugs. Reproduction code: `tests/Feature/ExtendedAuditTest.php` in the consumer application. Output: `docs/audit-extended.txt` and `docs/audit-all.txt` in that application.

| Area | Extended case | Confirmed observation | Priority |
|---|---|---|---|
| Authorization | `team_revocation_invalidates_cached_authorization` | In alpha, warm the cache and revoke a direct permission: hasDirectPermission returns false, but hasPermissionTo still returns true. The revocation event carries a null team and does not invalidate alpha's entry. | High |
| Inheritance | `hierarchy_cannot_grant_other_tenant_permissions` | A beta role inherits from alpha; a beta user receives alpha's tenant.secret permission even with strict_team_isolation=true. | High |
| Guards | `hierarchy_rejects_cross_guard_parent` | A web role accepts an api parent without GuardDoesNotMatch. | High |
| Writes | `two_loaded_users_do_not_lose_independent_grants` | Two instances of the same user are loaded before saving. The first grants create, the second grants publish: create is lost. This deterministically reproduces interleaved writes; it is not a parallel load test. | High |
| Cache generation | `overlapping_registrars_do_not_reuse_cache_generation` | Two registrars read the same version; both increments write the same number. The second change does not produce a distinct key. | High |
| Persistent processes | `long_lived_registrar_sees_external_role_revocation` | A warmed registrar does not observe a revocation performed by another registrar. This reproduces a long-lived instance; Octane was not installed or validated. | High when using this execution model |
| Teams | `permission_lookup_respects_active_team` | Permission's findByName selects the other team's document. | High |
| Teams | `direct_permission_can_be_granted_in_two_teams` | Deduplication by ID prevents a second grant of the same permission in another team. | High |
| Teams | `direct_permission_revocation_does_not_touch_other_team` | Revocation from beta removes alpha's grant. | High |
| Reads | `permission_listing_agrees_with_active_team` | getAllPermissions exposes an alpha permission within beta even though hasDirectPermission denies it. | Medium |
| Hierarchy | `extending_existing_ancestor_respects_total_depth` | With a maximum depth of two, create A→B→C, then C→D: a chain of three is allowed. Only ancestors of the modified node are checked. | Medium |
| Expiration | `expired_direct_permission_can_be_renewed` | Regranting with a future date preserves the expired grant. | Medium |
| Configuration | `configured_cache_store_is_used` | permission.cache.store does not route entries to the configured store. | Medium |
| Configuration | `configured_cache_ttl_is_respected` | permission.cache.expiration_time=1 leaves the entry active after advancing the clock by two seconds. | Medium |
| CLI | `cli_lists_users_with_inherited_permission` | hasPermissionTo recognizes inheritance, but permission:list-users --permission reports zero users. | Medium |
| CLI / legacy | `cli_lists_legacy_flat_role_assignments` | hasRole recognizes flat IDs, but permission:list-users omits the user. | Medium |

Positive controls in the extended audit: direct permission expiration revoked access even with a warm cache, and simple cycle detection worked. The platform's seven functional flows also passed, with 41 assertions.

#### Measurements

Run `composer lab:benchmark` from the consumer application. Instrumentation uses the driver's CommandSubscriber without recording query contents or credentials. The environment used local MongoDB 7.0.41, four functional users and 1,000 synthetic documents for explain. Timings are illustrative samples, not percentiles or production capacity estimates; command counts are the primary evidence.

| Operation | MongoDB reads | Sample time |
|---|---:|---:|
| First allowed permission check | 2 | 0.959 ms |
| 100 allowed checks, warm cache | 0 | 0.592 ms |
| 100 denied checks, warm cache | 100 | 39.927 ms |
| 100 hasRole checks by name | 100 | 41.621 ms |
| First permission check for a user with 10 roles sharing a parent | 12 | 7.222 ms |
| Assign 100 direct permissions | 200, plus 1 write | 74.697 ms |

The reverse query used by Role::users examined **1,004 documents** and returned one, even after permission:create-indexes had run. With experimental indexes on users.role_ids and users.role_ids.role_id, it examined **one document and one key**. The indexes were added only to permission_benchmark_test. This validated the optimization for the tested query and data; it did not measure its write cost.

The audit also confirmed that a previous generation's cache entry remained stored after saving a role. Because the baseline used rememberForever and ignored the configured TTL, retired generations could accumulate. No memory curve or production growth was measured.

#### Recommended implementation order at the time

1. Fix revocations, identity (team, guard and ID) and inheritance boundaries first; apply the same scope policy to mutations and reads.
2. Prevent lost updates through atomic operations or compare-and-swap. For structured grants, addToSet alone is insufficient without considering team and expiration. MongoDB documents single-document atomicity and conditional updates in [Atomicity and Transactions](https://www.mongodb.com/docs/manual/core/write-operations-atomicity/).
3. Make cache generation atomic, respect the store and TTL, preserve unrelated keys, and define a safe lifecycle for persistent registrars.
4. Reduce repeated reads: cache catalog and name resolution with team and guard context, batch the 100 permissions, and visit shared ancestors once per evaluation. Measure again after the changes.
5. Add reverse indexes using configured models and collections, and avoid scanning every user in list-users. Keep results consistent with inheritance, wildcards, TTL and legacy data.

Instrumentation references: [PHP CommandSubscriber](https://www.php.net/manual/en/class.mongodb-driver-monitoring-commandsubscriber.php) and [MongoDB Client::addSubscriber](https://www.mongodb.com/docs/php-library/v1.x/reference/method/mongodbclient-addsubscriber/).

At the baseline stage, this review provided evidence and proposals without applying package code changes. It did not cover distributed deployments, stress testing, replica-set transactions, full Laravel 13 compatibility or an exhaustive security audit. Later corrections and validation are described above and in [release validation](release-validation.md).
