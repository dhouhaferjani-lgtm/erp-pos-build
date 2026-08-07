# RepositoryMovementController allocation aggregate throws LogicException under the sqlite test driver

**Status:** OPEN — 11th live red on origin/dev, surfaced 2026-08-07/08 by the dev-red fixture-repair lane's sweep (correct STOP: product defect, not a fixture problem). Gate-drafted ticket text (treasury gate, `2026-08-08-devred-treasury-gate.md`).

`app/Modules/Treasury/Presentation/Controllers/RepositoryMovementController.php:102-105` requires the `withSum('statementAllocations as allocated_amount', 'matched_amount')` aggregate (declared at `:65`) to be a PHP `string`, throwing `\LogicException('Repository movement allocation aggregate must be a decimal string.')` otherwise. PDO_SQLite returns `SUM()` over a `decimal` (NUMERIC-affinity) column as int/float, so `tests/Feature/Treasury/RepositoryMovementsEndpointTest.php:351` (`test_search_returns_allocation_capacity_for_manual_matching`) fails on `origin/dev` today — independent of the W-5b balance-guard reds.

Introduced by `2afd37ebef` (2026-07-20, "fix(treasury): close phase 5 exit review findings") — pre-existing; NOT caused by the fixture-repair lane.

**First action: confirm the pgsql behaviour on a live PG instance.** If PDO_PGSQL returns `numeric` as a string, the production path is unaffected and this is test-env-only; if not, the manual bank-statement matching endpoint 500s in production.

**Fix direction:** normalise the aggregate at the boundary — an `AsDecimal`/string cast on `RepositoryMovement::$allocated_amount`, or `is_numeric()` + `(string)` in place of the `is_string()` requirement — rather than weakening the numeric-string contract. Rule 19 forbids letting a float reach the `bcadd`/`bcsub` at `:107`/`:115`.

**Adjacent dev reds recorded by the same sweep (different classes, handed off):**
- `tests/Feature/Fiscal/TreasuryDepositBridgeTest` 5E/4F — 3-vs-4 constructor drift since `ae58e1b11` (2026-07-11); already ticketed by R2-K-prev (`2026-08-07-treasury-deposit-bridge-test-argcount-broken.md`).
- `tests/Feature/Fiscal/ChokepointCompletenessTest::test_every_finalize_callsite_is_reconciled_with_receiver_type` — manifest-drift architecture test, needs its owner.
