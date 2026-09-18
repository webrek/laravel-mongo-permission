# Upgrading from v1.7.0

These notes apply to the corrections on `fix/permission-audit`; no release tag has been assigned yet.

## Before deploying

- Configure a shared cache store that implements atomic locks. Redis is suitable across hosts; file cache is shared only when workers access the same filesystem. Use the same permission key namespace and store on all workers.
- Review calls that pass role or permission models from another team or guard. They now reject invalid assignments explicitly, including `TeamDoesNotMatch`, instead of silently using another scope.
- Review calls that modify user attributes before assigning permissions. Assignment methods update only the grant arrays; explicitly save unrelated dirty attributes.
- Review tenant hierarchy data for invalid cross-team or cross-guard edges. Invalid legacy edges no longer grant access. Verify expected access in each team after upgrading.
- Cache entries now default to 86400 seconds, including a previously published null TTL. Grant expiration remains independent of cache expiration.
- `team-context` now restores the caller's context after the downstream request finishes or throws. Code that needs a team outside the request pipeline should set its own explicit context.
- `permission:prune-expired` updates assignment arrays atomically and preserves concurrent additions. It no longer saves an entire user document or emits the user's Eloquent save events; use the command result for cleanup reporting.
- Reverse user queries and deletion cleanup accept both string and native BSON ObjectId references. Custom text identifiers continue to work.

## Deployment sequence

1. Back up MongoDB and rehearse against a copy of application data.
2. Install the release in staging, update dependencies, and run the application's authorization tests.
3. Rebuild application configuration caches if used. Create the package indexes with `php artisan permission:create-indexes` after reviewing indexes on the application's user collection.
4. Replace old application workers together and restart persistent workers. Avoid mixing old and new implementations during permission mutations: the old code does not participate in the new concurrency protection.
5. Run `php artisan permission:cache-reset` from the updated application and verify grant/revoke flows. This command invalidates package permissions without flushing unrelated application cache.

Direct query-builder writes bypass model events. Prefer package mutation APIs; explicitly invalidate affected permission caches after any bulk data edits.

## Verification performed

Synthetic documents were written by the actual v1.7.0 code into a separate MongoDB database, then loaded by this branch with Redis. Six checks passed: existing role permission, expiring direct permission, legacy flat role assignment, and subsequent warm-cache revocations for all three cases. This is a focused compatibility check, not validation of every customer's existing data.

Redis standalone subprocess behavior is tested. Octane, Redis Cluster/failover, and a production load test are outside this validation.
