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

A later Redis run exposed a timing-sensitive one-second grant test; its application clock is now frozen before exercising expiry, preserving the allow-before / deny-after assertions.

Mutation testing runs separately with existing MSI 80% and covered MSI 90% thresholds. Its final result must be reviewed in the latest PR checks before treating the release checks as complete. Mutation tests use isolated per-process MongoDB databases and four workers; reports are uploaded as artifacts, including hidden files.

No Octane, Redis Cluster/failover or production-load validation is claimed. No stable version number has been selected; compatibility changes must be reviewed before tagging. Publishing the draft as a release remains a separate action.

## Follow-up regressions — 2026-09-17

Added 45 behavioral tests (259 total) covering expiry boundaries, permission model identity, mixed-team grant preservation, conflicting writes, lock support, scoped context restoration, branching hierarchies, CLI diagnostics and migration edge cases. The tests reproduced and drove fixes for same-name permission identity confusion, incorrect CLI traces, and polymorphic/team/deduplication/context/force issues in Spatie imports.

Local verification: 259 tests / 738 assertions pass with Redis; array runs have 256 passing tests plus 3 Redis-only skips / 723 assertions. Consumer platform: 38 tests / 99 assertions with Redis. PHPStan and Composer audit pass.

The per-mutant timeout is increased to 60 seconds: slow coverage-selected integration suites must be given enough time to finish rather than counting runner timeouts as detected mutations. MSI thresholds remain 80% / 90%, with no new source exclusions or mutator suppressions. Updated full-matrix and mutation results are recorded in PR #1 checks.
