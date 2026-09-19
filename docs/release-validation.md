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

Added 46 behavioral tests (260 total) covering expiry boundaries, permission model identity, mixed-team grant preservation, conflicting writes, lock support, scoped context restoration, branching hierarchies, CLI diagnostics and migration edge cases. The tests reproduced and drove fixes for same-name permission identity confusion, incorrect CLI traces, and polymorphic/team/deduplication/context/force issues in Spatie imports. Numeric SQL team IDs are normalized to strings; a regression verifies team `0`, repeated imports and denial from a different team.

Local verification: 260 tests / 748 assertions pass with Redis; array runs have 257 passing tests plus 3 Redis-only skips / 733 assertions. Consumer platform: 38 tests / 99 assertions with Redis. PHPStan and Composer audit pass.

The per-mutant timeout is increased to 60 seconds: slow coverage-selected integration suites must be given enough time to finish rather than counting runner timeouts as detected mutations. MSI thresholds remain 80% / 90%, with no new source exclusions or mutator suppressions. Updated full-matrix and mutation results are recorded in PR #1 checks.

## Behavioral contract regressions — 2026-09-18

Added coverage for custom BSON IDs, textual IDs, explicit guards, scoped middleware, expired grants, CLI output and candidate filtering, import edge cases, cache recovery and lock coordination. Regressions reproduced and corrected request team context leakage, exact-role counts using the wrong guard, concurrent grants lost during pruning, SQL identifier zero being skipped, and reverse queries/cascades missing BSON references.

A deterministic paused-writer test also reproduced cache generation updates being overwritten after lease expiry. Existing counters now use the cache driver's increment operation while retaining locks for file-store serialization. Redis's atomic increment preserves both writes in this case. This does not claim failover or arbitrary lease-expiry safety for every cache driver.

Local array validation: 327 tests, 1,136 assertions, with three Redis-only skips. Consumer Laravel platform with Docker Redis: 38 tests, 99 assertions. PHPStan passes. Mutation validation and the full CI matrix are being rerun for this revision; these functional results alone do not establish completion of the release gates.

## Final validated code — 2026-09-18

[CI run 35394466483](https://github.com/webrek/laravel-mongo-permission/actions/runs/35394466483), code commit `7dc05d3`, passed all nine jobs: seven PHP/Laravel combinations (each with array, Redis and dependency auditing), PHPStan, and Infection. The package suite now contains 340 tests: Redis passes all 340 with 1,187 assertions; array passes 337 with three Redis-only skips and 1,172 assertions.

Infection generated 1,580 mutants: 1,424 killed, 145 escaped, four uncovered and seven timed out; no errors, syntax errors or ignored mutants. MSI is **90.57%**, covered MSI **90.80%**, mutation coverage **99.75%**. The existing 80% / 90% thresholds, source exclusions and mutator selection are unchanged. The seven timed-out mutations introduce non-terminating graph/retry behavior; the killed-only ratio also exceeds 90% of covered mutants. The full JSON/text reports are attached to the CI run. Earlier local mutation runs under heavy load had additional timeouts and are not used as release evidence.

The consumer Laravel platform passes 38 Redis tests / 99 assertions. Fifteen live HTTP checks passed again with actual sessions and CSRF: login/logout, administrator pages, reader access and forbidden writes. These checks do not include browser rendering. Six benchmark scenarios preserve their MongoDB query counts; the reverse-role query including string and native BSON references uses indexes and examines one matching document. No production-load guarantee is inferred from this local measurement.

Release validation gates described here pass. The PR remains a draft and no merge or release tag has been performed. Compatibility review and version selection are still required before publishing; Octane and Redis Cluster/failover remain outside this validation.
