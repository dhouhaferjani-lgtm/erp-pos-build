# M5 evidence — T21 counting-listener GL leg + T22 ticket

**M5 base (M4 accepted tip):** `d425434cd` — tree byte-identical to the reviewed `f56fd9e65`

**Gate authority:** `ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md` (parent orchestrator, 2026-08-19)

**Account-map authority:** `TREASURY-RULING-2026-08-19-t20-option-a.md` — Option A, `6586` / `7586`

**Ruling commit:** `cf9994b11` — Phase 3.5.1

**Adopted M4-round6 remediation (cherry-picked):** `3c2791724`, `cdbefd40f`, `75a06d987`

**Implementation commit:** `dfeb7bea8` — Phase 3.5.2

**Executor:** parent orchestrator's implementation agent. The Codex session that held this wave went
terminal at `blocked_owner` and did not produce M5.

---

## 0. Takeover and the adopted commits

M5 opened by adopting the three commits preserved on `codex/reviewer-round6-unauthorized-mutations`,
per the parent ruling. They close M4-round6's own recorded finding 1 (a pre-policy template carrying
no expense-typed `65`/`6000` hard-aborted tenant registration) and were reverted only because the
round-6 process misattributed them to its reviewer.

Cherry-pick outcome: **clean, no conflicts**. Verified byte-identical to the preserved ref:

```
git diff --stat 5cdfbc49a HEAD -- apps/   ->  (empty)
```

The only delta between `HEAD` and the preserved ref tip is documentation (`M4-evidence.md`,
`M4-round6.md`, the new ruling file, the session report).

Re-verification on PostgreSQL:

```
APP_KEY=… DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test DB_USERNAME=postgres \
DB_PASSWORD= CACHE_STORE=array ./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/CountryDefaults/ProvisioningFlagMatrixTest.php
->  OK (19 tests, 42 assertions)
```

Matches the 19 green tests recorded post-fix. Files touched by the three commits:
`InventoryVarianceAccountProvisioner.php`, `SystemAccountPurpose.php`, `ProvisioningFlagMatrixTest.php`.

---

## 1. What T21 delivers

### 1.1 The root frame — a RULING, not a choice

`ApplyStockAdjustmentsOnCountingCompleted::handle()` opened **no** transaction; the
`DB::transaction` was **per item**. "Flush after the loop" sat at depth 0, where
`flushIfOutermost()` can never post and D-10 forbids autocommit posting; flushing inside the loop
would take the company advisory before item N+1's `ProductCostLock` / `stock_levels` locks —
violating I-1. Neither was implementable.

Implemented as ruled: **one `DB::transaction` around the whole item loop, enqueue per item, flush at
that transaction's tail.**

- `ApplyStockAdjustmentsOnCountingCompleted.php:92-105` — the RULING and its recorded consequence.
- `ApplyStockAdjustmentsOnCountingCompleted.php:106` — the root frame opens.
- `ApplyStockAdjustmentsOnCountingCompleted.php:161-166` — `flushIfOutermost()` at the tail.

### 1.2 One basis, on the row, both paths

`Product::resolveMovementUnitCost()` (D-3) is resolved **once, before the movement is created**, and
written **on** it. Both counting paths were NULL-cost before this change (§0t.6), so a GL leg valued
at post time would have posted `amount = 0` — a silent zero-value shrinkage.

- `StockAdjustmentService.php:1740-1758` — `resolveRowUnitCost()`, the single definition, scoped to
  the company the locked `StockLevel` already validated. Resolver-independent (reads `cost_price` at
  the constant `COST_SCALE = 6`), so it is safe in the queued listener with no `CompanyContext`.
- `StockAdjustmentService.php:1379` — threaded in `postCountCorrection()` (the REPLAY path).
- `StockAdjustmentService.php:948` — threaded in `postAdjustmentWithinLock()`, which serves the
  LEGACY `adjust()` counting path **and** the `adjustByDelta()` document path.

The posted amount is derived by `InventoryGlPostingService::amount()` from the persisted row as
`unit_cost x |quantity_after − quantity_before|` — never from a WAC that may have moved since.

### 1.3 The GL sink is a `\Closure`, deliberately

`StockAdjustmentService` is **Domain**. Deptrac forbids `ModuleDomain -> ModuleApplication`, and
`InventoryGlPostingBuffer` / `MovementGlContext` are Application. Rather than add new violations to
an already-failing inherited ratchet, the Domain service accepts
`?\Closure(StockMovement): void $onCountCorrection` and the **Application-layer listener** owns the
context construction and the enqueue (listener-to-buffer is same-layer, zero new violations).

- `StockAdjustmentService.php:1246-1255` — the parameter contract on `applyCountResult()`.
- `StockAdjustmentService.php:1387-1393` — invoked inside the lock, inside the caller's transaction.
  Enqueue is pure (no database work until the root flush), so it cannot invert the I-1 lock order.
- `ApplyStockAdjustmentsOnCountingCompleted.php:352-390` — `enqueueCountCorrectionGl()`.

**It fires only for the `postCountCorrection()` branch.** `postCountOpening()` writes
`MovementType::Opening` / `MovementReason::OpeningBalance`, which is outside the seam (inv N-2), so
an onboarding first count still posts no shrinkage/gain leg.

The LEGACY path needs no sink — `adjust()` already returns its `StockMovement`
(`ApplyStockAdjustmentsOnCountingCompleted.php:243-256`). Both paths call the **same** enqueue
helper, which is what makes "one basis, both paths" structural rather than asserted.

### 1.4 Currency is explicit (house rules 19/20)

The listener runs queued with **no `CompanyContext`**, where a bare no-arg
`CurrencyScaleResolver::getScale()` throws. `resolveCurrencyCode()`
(`ApplyStockAdjustmentsOnCountingCompleted.php:392-401`) reads the company's ISO 4217 code **once per
job** and it is carried on `MovementGlContext::$currencyCode`, which is what
`InventoryGlPostingService` scales on. No float touches the amount: `bcmul` at `scale + 6`, rounded
once by `CurrencyScale::bcround`.

### 1.5 D-20 half-fix — costed, but still no GL leg

`adjustByDelta()` document lines now carry a non-null `unit_cost`, so a Damage/WriteOff line stops
being indistinguishable from D-b's genuinely zero-cost population. They still get **no** journal
entry, and the refusal is now **structural**: that path has no GL sink at all, so nothing it writes
can reach the buffer. D-a's and D-b's `reference_type = 'stock_adjustment'` exclusions are preserved
untouched, so the newly costed document lines do not enter the detector.

### 1.6 The dormancy gate (OQ-12/H-5)

`config/inventory.php` — `count_correction_gl_posting_enabled`, env
`INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED`, **shipped FALSE**.

With it off the listener corrects stock and threads the row cost onto the count movement, but
enqueues nothing. Nothing is lost: the ledger can be reconstructed from the movement rows once the
flag flips. Both count-correction purposes stay dormant exactly as 3C shipped them.

**This is a DEPLOY-TIME blocker, not a code blocker.** The flag must not be enabled for any tenant
before the expert-comptable ratification of the Option A presentation is recorded.

### 1.7 Ticket 2026-08-18-remove-counting-detector-exclusion-with-t21 — CLOSED

That ticket required D-e's `reference_type = inventory_counting` exclusion to be deleted in the same
commit that wires `MovementGlKind::CountCorrection`. Deleting it outright would have made every
completed count a permanent false alarm while posting is deliberately dormant, so the exclusion is
now **tied to the flag** (`CheckCogsCoverageCommand.php:247-260`): silent while dormant, reporting
the moment posting goes live. That preserves the ticket's intent exactly — D-e surfaces failed or
declined count-correction postings — without spamming the detector for a feature that is off by
design. Covered positively and negatively by
`CheckCogsCoverageCommandTest::test_de_reports_count_corrections_once_their_posting_flag_is_live`.

---

## 2. T22 — a ticket, not code

Per the plan ruling ("RE-TICKET, do not absorb") and the brief's evidence contract ("T22 is a ticket
file, not code — the deliverable is the file, citing both source files"), T22 is delivered as
`docs/superpowers/tickets/2026-08-19-counting-lane-c2-default-lot-inflation.md`, citing:

1. `StockAdjustmentService.php:905` (`postAdjustmentWithinLock`), `:921`
   (`$deltaBasedDefaultLot`), `:859` (document path passes `true`), `:729` (counting path passes
   `false`), `:886` (the docblock recording the V7 contradiction and its settlement);
2. `InventoryCountingDefaultBatchTest.php:130-131` (the shipped target-based counting invariant).

It records why G2 does not depend on the answer: T21's direction and magnitude come from
`quantity_before`/`quantity_after` on the aggregate row (D-5), which is correct under both lot
strategies. Registered against OQ-7.

---

## 3. TDD — red-first, by revert-replay

Tests were written first, then the production delta was **reverted to `d425434cd`** (three production
files restored, `config/inventory.php` removed) and the covering set re-run on PostgreSQL.

**RED (production reverted to the M5 base):**

```
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Inventory/CountCorrectionGlPostingTest.php \
  tests/Feature/Inventory/ReplayFinalizeTest.php \
  tests/Feature/Inventory/StockAdjustByDeltaTest.php \
  tests/Feature/Accounting/CheckCogsCoverageCommandTest.php
->  Tests: 65, Assertions: 155, Errors: 1, Failures: 12
```

Every new or changed test was in the red set, and nothing else was:

| red case | mechanism it covers |
|---|---|
| `CountCorrectionGlPostingTest::test_replay_shortage_debits_shrinkage_on_the_rows_own_cost` | replay path, Dr Shrinkage, exact amount |
| `…::test_replay_overage_credits_the_gain_account` | replay path, Cr Gain |
| `…::test_legacy_path_posts_on_the_same_row_cost_basis` | legacy path, one basis |
| `…::test_queue_retry_after_the_marker_posts_no_second_entry` | idempotency |
| `…::test_the_flag_is_off_by_default_and_the_dormant_listener_posts_nothing` | the OQ-12/H-5 dormancy pin |
| `…::test_an_item_that_throws_leaves_zero_movements_and_zero_entries_and_the_retry_posts_one_of_each` | the root-frame consequence |
| `ReplayFinalizeTest::test_both_counting_paths_persist_the_row_unit_cost` | cost threading, driver-agnostic |
| `StockAdjustByDeltaTest::test_the_document_lane_costs_the_row_but_still_posts_no_gl` | D-20 half-fix |
| `StockAdjustByDeltaTest::test_an_adjustment_document_line_is_costed_but_never_reaches_the_gl_buffer` | D-20 structural refusal |
| `CheckCogsCoverageCommandTest::test_de_reports_count_corrections_once_their_posting_flag_is_live` | the D-e exclusion lift |

(The one ERROR in the red run and the three `ReplayFinalizeTest` red rows beyond the new case are the
inherited PG condition recorded in §6.)

Production was then restored and the same set re-run **GREEN** (§4).

### The anti-green-by-vacuum antidotes, discharged

- **The replay case is driven through `postCountCorrection`.** The test asserts
  `movement_type = Adjustment`, `reason = CountCorrection` and
  `reference_type = inventory_counting` before asserting on the entry, so an `OpeningBalance`
  movement from `postCountOpening()` could not satisfy it
  (`CountCorrectionGlPostingTest.php:291-305`).
- **`entry.amount == row.unit_cost x abs(delta)` exactly, on BOTH paths** —
  `assertEntryAmountEqualsRowCostTimesAbsoluteDelta()`, recomputed from the persisted row rather than
  from the expected literal, and applied to the replay shortage, the replay overage and the legacy
  case. It also asserts the two lines balance.
- **The item-3 pin.** A three-item counting whose third item throws (its product soft-deleted, so
  `resolveTenantId()`'s `findOrFail` excludes it) leaves **zero** movements and **zero** entries, and
  the retry with the fault cleared produces **exactly one of each, per item**. The test asserts the
  listener's own load order first, so the pin cannot silently degrade into "item 3 ran first".

---

## 4. Verification — exact counts

### 4.1 Changed/new tests, SQLite (default `phpunit.xml`)

```
./vendor/bin/phpunit \
  tests/Feature/Inventory/ReplayFinalizeTest.php \
  tests/Feature/Inventory/StockAdjustByDeltaTest.php \
  tests/Feature/Accounting/CheckCogsCoverageCommandTest.php \
  tests/Feature/Inventory/CountCorrectionGlPostingTest.php
->  OK, Tests: 65, Assertions: 150, Skipped: 7
```

The 7 skips are `CountCorrectionGlPostingTest`, which skips **loudly** on non-PostgreSQL drivers.

### 4.2 Changed/new tests, PostgreSQL (`phpunit-pgsql.xml`)

```
APP_KEY=… DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test DB_USERNAME=postgres \
DB_PASSWORD= CACHE_STORE=array ./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Inventory/CountCorrectionGlPostingTest.php \
  tests/Feature/Inventory/StockAdjustByDeltaTest.php \
  tests/Feature/Accounting/CheckCogsCoverageCommandTest.php
->  OK (54 tests, 156 assertions)
```

`CountCorrectionGlPostingTest` alone: **OK (7 tests, 42 assertions)**.
`ProvisioningFlagMatrixTest` (adopted commits): **OK (19 tests, 42 assertions)**.

`ReplayFinalizeTest` is excluded from the PG lane — see §6.

### 4.3 Regression set — every suite directory the diff touches

| suite | driver | result |
|---|---|---|
| `tests/Feature/Inventory` | sqlite | 828 tests, 16 errors, 2 failures — **identical set at base**, §5 |
| `tests/Unit/Inventory` + `tests/Architecture` | sqlite | 188 tests, 667 assertions, 2 errors, 4 failures — **identical at base**, §5 |
| `tests/Feature/CountryDefaults` | PG | **OK (172 tests, 1142 assertions)** |
| `tests/Feature/Accounting/CheckCogsCoverageCommandTest` | PG | **OK (26 tests, 54 assertions)** |

### 4.4 Style and static analysis

```
./vendor/bin/pint --test <8 changed files>            ->  {"result":"pass"}
./vendor/bin/phpstan analyse --memory-limit=2G \
  StockAdjustmentService.php \
  ApplyStockAdjustmentsOnCountingCompleted.php \
  CheckCogsCoverageCommand.php                        ->  [OK] No errors   (level 8)
```

Pint was run against `d425434cd`'s `StockAdjustmentService.php` in isolation first
(`{"result":"pass"}`), confirming the drift Pint fixed came from this change and not from inherited
baseline drift. The post-Pint diff was reviewed line by line: only this change's lines moved.

---

## 5. Pre-existing vs introduced — the triage the parent asked for

### 5.1 The reported `tests/Feature/CountryDefaults` PG anomaly: DOES NOT REPRODUCE

The wave recorded 10 errors ("relation `super_admins` does not exist" on the central connection) on
a full-directory PG run. Measured both sides:

| tree state | command | result |
|---|---|---|
| `d425434cd` (production **and** tests reverted to base) | `phpunit -c phpunit-pgsql.xml tests/Feature/CountryDefaults` | **OK (169 tests, 1132 assertions)** |
| M5 tip after the three cherry-picks | same | **OK (172 tests, 1142 assertions)** |

**Answer: neither pre-existing-and-reproducing nor introduced — the 10 errors do not occur at all in
this worktree, in either tree state.** The delta between the two runs is exactly the +3 tests / +10
assertions the adopted commits add. The reported errors were an artifact of the Codex session's
environment (stale central-connection state), not a property of the code at `d425434cd`. No fix was
chased, per instruction.

### 5.2 The regression-suite failures are inherited, proven by identical failing sets

For both regression suites the production delta was reverted to `d425434cd` and the identical command
re-run:

```
tests/Unit/Inventory + tests/Architecture
  M5 tip : Tests: 188, Assertions: 667, Errors: 2, Failures: 4
  base   : Tests: 188, Assertions: 667, Errors: 2, Failures: 4
```

The four `tests/Feature/Inventory` classes carrying all 18 non-green results were run in isolation at
both tree states and their failing-test-name lists diffed:

```
diff suspect-BASE-names.txt suspect-HEAD-names.txt   ->  IDENTICAL
  both: Tests: 31, Assertions: 130, Errors: 16, Failures: 2
```

The inherited set: `CogsRelocationCharacterisationTest` (15), `ExitMovementPrecisionTest` (1),
`GoodsReceiptLedgerSchemaTest` (1), `PosMovementCostSnapshotTest` (1), plus the architecture
classification lists (`AuthLifecycleTest`, `ConsoleCommandTenantContextTest`,
`ControllerTenantContextTest`, `QueueJobTenantContextTest`) and `GoodsReceiptDataTest` (2). None
names a file this change touches. `ApplyStockAdjustmentsOnCountingCompleted` is a `ShouldQueue`
**listener**, not under `*/Jobs`, so it is outside `QueueJobTenantContextTest`'s scan root.

**M5 introduces zero new failures.**

---

## 6. Two conditions found and recorded, not absorbed

### 6.1 `ReplayFinalizeTest` is not PostgreSQL-runnable (pre-existing)

`ReplayFinalizeTest.php:157` builds a 21-character `counting_number` against a `varchar(20)` column.
SQLite accepts it; PostgreSQL rejects every insert with `SQLSTATE 22001`, so the counting replay
lane's primary regression file has never run on the production driver. Shortening the fixture (tried,
then reverted) exposes **three genuine PG-only divergences** — including
`test_case_b_pre_count_sale_excluded` returning `12.0000` instead of `17.0000`, i.e. the
pre-count-sale exclusion behaving differently on PostgreSQL.

Root-causing timestamp handling in the replay window is a separate lane. M5 left
`ReplayFinalizeTest.php:157` exactly as inherited, excluded the file from its PG lane, and filed
`docs/superpowers/tickets/2026-08-19-replay-finalize-test-not-pg-runnable.md` with the measured
table. M5's own addition to that file is driver-agnostic and green on SQLite; its PostgreSQL
counterpart is `CountCorrectionGlPostingTest`.

### 6.2 The `[PG]` non-transactional pattern has two ordering properties

`connectionsToTransact() = []` is required for `flushIfOutermost()` to see a real level-1 root frame.
It carries two consequences, one inherited and one this change fixed:

- **Inherited:** on the SQLite lane it tears down the shared in-memory schema for any class scheduled
  after it. Verified at this tip with an untouched file: running `InventoryGlPostingSeamTest` before
  `ReplayFinalizeTest` produces the same 11 errors. Documented on
  `CountCorrectionGlPostingTest::connectionsToTransact()`; put `[PG]` classes last in a mixed
  by-path SQLite invocation.
- **Fixed here:** because this class commits **real stock movements**, two of which deliberately have
  no journal entry (the fail-soft chart and the dormant flag), it polluted
  `accounting:check-cogs-coverage`, which scans every tenant and every active company — making
  `CheckCogsCoverageCommandTest` fail in a file that never touched it. `tearDown()` now deletes the
  class's journal lines, entries and stock movements and closes its fixture company. The existing
  `InventoryGlPostingSeamTest` does not have this problem because it writes no stock movements.

---

## 7. Deliberately NOT done

- **The V1 purpose manifest was not edited.** `ProvisioningRequiredPurposesV1.php:83` still reads
  "no CountCorrection producer until T21". V1 is a frozen, fingerprint-locked fixture whose entry
  count the parent-side merge reconciliation pinned at 43, and the reason `InventoryGainIncome` is
  SOFT is unchanged and still true: `postForCountCorrection()` fail-softs when the gain account is
  unmapped, producer or no producer. A V2 re-certification is already ticketed
  (`2026-08-19-m4-country-defaults-v2-certification-repin.md`).
- **S-16** (the per-tenant duplicate-count query) is untouched and remains a parent-side hard
  pre-promotion gate. No local probe is offered as evidence.
- **The inherited deptrac ratchet** is untouched and remains parent-owned. This change adds **zero**
  new `ModuleDomain -> ModuleApplication` edges by construction (§1.3).
- **No `.github/workflows/**` change**, per the M4 STOP-B ruling.
- **The bridge review was not run.** The parent runs M5's whole-branch gate itself.
