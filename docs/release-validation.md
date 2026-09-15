# Release validation — 2026-09-14

Changes are available in [draft PR #1](https://github.com/webrek/laravel-mongo-permission/pull/1); no merge or release tag has been performed.

## PHP / Laravel / cache matrix

The [GitHub run for code commit 7c55132](https://github.com/webrek/laravel-mongo-permission/actions/runs/34928069884) passed all seven matrix jobs:

| PHP | Laravel | Array suite | Redis suite | Composer audit |
| --- | --- | --- | --- | --- |
| 8.2 | 12 | Pass | Pass | Pass |
| 8.3 | 12 | Pass | Pass | Pass |
| 8.3 | 13 | Pass | Pass | Pass |
| 8.4 | 12 | Pass | Pass | Pass |
| 8.4 | 13 | Pass | Pass | Pass |
| 8.5 | 12 | Pass | Pass | Pass |
| 8.5 | 13 | Pass | Pass | Pass |

Array runs execute 211 tests and skip the three Redis-only cases. Redis runs execute 214 tests with 369 assertions. Some tests explicitly choose another store to verify store selection or file locks. PHPStan also passed in GitHub and locally.

The initial local installed dependency set contained advisories. Resolving updated dependencies within composer.json constraints produced a clean Composer audit; no advisory suppressions were added. Each CI matrix entry independently resolves and audits its own dependency set. This validates current resolved versions, not every older version permitted by broad dependency ranges.

## Redis and upgrade checks

Redis tests exercise role and direct grant/revoke propagation from separate PHP processes while retaining the reader's registrar and user object. Four independent workers preserve exactly 160 cache generation increments. TTL tests wait for Redis's actual clock.

A synthetic upgrade from actual v1.7.0 documents passed six checks: role permission, expiring direct grant, flat legacy role assignment, and warm-cache revocation of all three. See [upgrade notes](upgrading-from-1.7.md).

## Remaining release gates

Mutation testing runs separately with existing MSI 80% and covered MSI 90% thresholds. Its final result must be reviewed in the linked run before treating the release checks as complete. Tests run serially because their MongoDB setup drops shared collections; reports are uploaded as artifacts, including hidden files.

No Octane, Redis Cluster/failover or production-load validation is claimed. No stable version number has been selected; compatibility changes must be reviewed before tagging. Publishing the draft as a release remains a separate action.
