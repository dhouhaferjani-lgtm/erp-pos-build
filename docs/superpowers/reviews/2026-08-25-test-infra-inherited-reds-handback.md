# test-infra inherited-reds micro-lane — handback

- **Branch:** `chore/test-infra-inherited-pg-reds`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/test-infra-inherited-reds`
- **Base:** `eaf80a112` (local `dev` at lane start); merged local `dev` `04ddf0266` mid-lane (`e80d843a1`)
- **Tip:** `c988481c3`
- **Date:** 2026-08-25
- **Scope:** TEST FILES / FIXTURES / CI FILTER ONLY. `git diff dev...HEAD -- apps/api/app` is **empty** — zero production changes.
- **Evidence harness:** throwaway PostgreSQL `autoerp_test_ti` on `127.0.0.1:5433` (`autoerp`/`autoerp_secret`), dropped and recreated before every multi-class run, dropped at the end. SQLite leg = default `phpunit.xml` (`:memory:`). Tests run BY PATH only, one PHPUnit process at a time. Full suite never run.

---

## 0. Scoreboard

| # | Target | BEFORE | AFTER (PG) | AFTER (sqlite) |
|---|---|---|---|---|
| 1 | `Import/ResultWorkbookTest` (C-10) | 1F/1P on PG (22P02 uuid) | **2 passed** | **2 passed** |
| 2 | `Import/PartiesImportBalancesTest` (C-10) | 1F/3P on PG (jsonb key order) | **4 passed** | **4 passed** |
| 3 | `Treasury/AdvanceReversalGlShapeTest` | 11F/1P on **sqlite**, 12/12 PG | **12 passed** | **12 passed** |
| 4 | `POS/ReceiptReturnRefactorTest` | 3F/16P on PG | **19 passed** | **19 passed** |
| 5a | `Treasury/RepositoryMovementsEndpointTest` | 1F/9P on **sqlite** | **10 passed** | 9 passed / **1 documented PG-only skip** |
| 5b | `Fiscal/TreasuryDepositBridgeTest` | 9F/0P (ctor arity 3 vs 4) | **9 passed** | **9 passed** |
| 6 | C-7 `PosCoreReceiptProjection*` 14-file cluster | 86 tests / **12 failures** / 335 assertions on PG | **86 passed / 365 assertions** | see §6 |
| 7 | `Inventory/LiveCountingSchemaTest` | 2F/8P on PG (never ran there before) | **10 passed** | **10 passed** |
| 8a | `Inventory/StockReservationDefaultBatchTest` | 1F/0P on PG (22P02 uuid) | **1 passed** | **1 passed** |
| 8b | `BatchExpiry` 3-file cluster (the "8 errors") | 8F/2S/4P on **sqlite** | **14 passed** | **4 passed / 10 documented PG-only skips** |
| + | W2-7 provenance classes (see §9) | 8F in a multi-class PG run | **68 passed** | green |

**Combined final sweep (20 classes, one process each):**
- PostgreSQL: `Tests: 195 passed (930 assertions)`
- SQLite: `Tests: 43 skipped, 152 passed (730 assertions)`

Gates: **Pint pass**, **PHPStan zero new findings** (49 vs dev's 50 over the touched files — the lane *removes* one; note `phpstan.neon` `paths: [app/]`, so none of these files is analysed by the CI job at all), **`feature-lane-manifest-check.php` EXIT=0** (1438 Feature classes / 74 groups, no new classes), **`deptrac-ratchet.php` RESULT: PASS**, **actionlint 0 issues on `ci.yml`**.

---

## 1. `ResultWorkbookTest` — C-10 uuid fixture

**Root cause.** `import_rows.imported_entity_id` is a `uuid` column. The fixture bound the literal `'product-1'`; SQLite's loose type affinity accepts it, PostgreSQL raises `SQLSTATE[22P02] invalid input syntax for type uuid`.

**Fix.** `apps/api/tests/Feature/Import/ResultWorkbookTest.php` — new `IMPORTED_PRODUCT_ID` class constant holding a real UUID; the fixture binds that.

BEFORE (PG): `FAILED … 22P02 … "product-1"` at `ResultWorkbookTest.php:84`. AFTER: 2 passed on both drivers.

## 2. `PartiesImportBalancesTest` — C-10 jsonb key order

**Root cause.** `opening_balance_batches.import_file_reference` is `jsonb`. PostgreSQL normalises object key order on storage (shortest-first, then bytewise); SQLite preserves insertion order. `assertSame` on an associative array is order-sensitive.

**Fix.** `apps/api/tests/Feature/Import/PartiesImportBalancesTest.php` — new `assertSameJsonObject()` helper that `ksort()`s both sides before a **strict** comparison (values and value types still compared strictly; only ORDER is relaxed).

BEFORE (PG): `Failed asserting that two arrays are identical` at `:130`. AFTER: 4 passed on both drivers.

## 3. `AdvanceReversalGlShapeTest` — driver decimal-format artifact (fixed, not skipped)

**Root cause.** Three helpers read money aggregates with `CAST(SUM(...) AS TEXT)`. PostgreSQL keeps the `numeric(N,3)` scale (`'90.000'`); SQLite has no DECIMAL type, stores these columns with NUMERIC affinity and returns `'90'`. Every assertion in the class was a string-equality on a **driver-formatted** decimal, so the class was 11F/1P on SQLite and 12/12 on PG with identical production behaviour.

**Fix (preferred route taken — no skip).** `apps/api/tests/Feature/Treasury/AdvanceReversalGlShapeTest.php`:
- new `normaliseDecimal(string $value, int $scale = 3): string` — bcmath half-up rounding at `$scale`, then canonical fixed-scale emission. No floats anywhere; a value that is not a plain decimal is surfaced verbatim so the failure message shows the real driver output instead of a silent `'0.000'`.
- applied inside `postedSidesByPurpose()`, `creditByPurpose()`, `postedDebitsByPurposeForSource()` so all 20+ call sites keep their literals unchanged.
- three expectations of the driver-shaped empty aggregate `'0'` became the canonical `'0.000'`.

AFTER: 12 passed on **both** drivers.

## 4. `ReceiptReturnRefactorTest` — two real fixture defects, both masked by SQLite

**Root cause A — `pos_receipts_totals` CHECK.** The PG constraint is `total = subtotal + tax_amount - discount_amount` (`database/migrations/tenant/2026_03_09_200000_add_return_fields_to_pos_receipts.php:42`). `createSaleReceipt()` hardcoded `tax_amount = '19.000'` regardless of the subtotal/total the caller asked for, so the three call sites that pass `total === $subtotal` (`3/3`, `100/100`, `200/200`) produced arithmetically impossible headers. The migration is pgsql-guarded, so SQLite never creates the CHECK and swallowed them.

**Root cause B — sealed-receipt immutability trigger.** `test_out_of_window_voucher_only_policy_sets_out_of_window_flag` back-dated a **fiscalised** receipt with `$saleReceipt->posted_at = …; save();`. `prevent_receipt_modification()` freezes `posted_at` on PG ("Immutable fields: … timestamp …"); SQLite has no such trigger.

**Fix.** `apps/api/tests/Feature/POS/ReceiptReturnRefactorTest.php` — `createSaleReceipt()` derives `tax_amount` as `bcsub($total, $subtotal, 3)` (the default `100.000/119.000` still yields the same `19.000` the fixture always intended) and gained a `?CarbonInterface $postedAt` parameter applied **at INSERT**. No test asserts a `createSaleReceipt`-generated `tax_amount`, so nothing lost strength.

**This is a fixture defect, not a production defect.** Both PG objects behaved exactly as designed; the test was writing rows production could never write.

AFTER: 19 passed on both drivers — which unblocks the four D-1 post-remise refund pins (see §9).

## 5. Constructor arity / driver aggregate type

**`TreasuryDepositBridgeTest` (9 errors).** `TreasuryDepositBridge::__construct()` grew a 4th dependency (`HandlesMaturityTenderLeg`); the `bridge()` helper still passed 3, so every test died with `ArgumentCountError` and proved nothing. Fixed exactly as `TreasuryAccountPaymentBridgeTest::bridge()` was in the G-3 wave: `maturityLegHandler: $this->app->make(HandlesMaturityTenderLeg::class)`. **No behaviour change, no production arity drift** — the container wiring was already correct; only the hand-built test double was stale.

**`RepositoryMovementsEndpointTest` (1 red) — NOT a ctor-arity issue.** The single failure is `test_search_returns_allocation_capacity_for_manual_matching`, the only case that populates the `withSum('statementAllocations as allocated_amount', …)` aggregate. On PostgreSQL the numeric aggregate comes back as a decimal **string**, which is what `RepositoryMovementController::index()`'s fail-loud money guard requires:

```
apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryMovementController.php:102-105
  if (! is_string($rawAllocatedAmount) || ! is_numeric($rawAllocatedAmount)) {
      throw new \LogicException('Repository movement allocation aggregate must be a decimal string.');
  }
```

SQLite hands the same aggregate back as a float, so the guard (correctly) refuses and the endpoint 500s **under SQLite only**. No fixture can change a driver's aggregate type, so the case is pinned to PostgreSQL with a documented skip and the class is added to the PG allowlist. **Not reported as a production defect:** production is PostgreSQL-only and the guard is doing precisely its job there.

## 6. C-7 — `PosCoreReceiptProjection*` committed-state bleed

**Status of the recorded fix.** The C-7 lane (`0ed7c37fc`, `72543c169`, gate r1 ACCEPT `docs/superpowers/reviews/2026-08-23-c7-testinfra-gate-r1.md`) is **fully merged into `dev`** and the target class `PosCoreReceiptProjectionTest` is green. What remained OPEN is the gate's residual **R-1/R-2**: the *sibling* classes were never scoped, and reproduce the identical 12F/74P signature.

**Reproduced BEFORE (PG, this lane):** `PosCoreReceiptProjectionRefundNoDecrementTest` ×2, `…RefundStockTest` ×5, `…VariantStockTest` ×5 = **12 failures**, e.g. `Failed asserting that 29 is identical to 1` on `DB::table('stock_movements')->count()`. All three are **green standalone** — proving the bleed, not a logic defect.

**Root cause (verified in code, not assumed).** `PosCoreReceiptProjectionRefundDispositionStockTest` overrides `connectionsToTransact(): []` — it has to, because it asserts real transaction-rollback semantics that a wrapping RefreshDatabase transaction would mask. Its rows are therefore COMMITTED and survive for the rest of the PHP process. Any sibling using an unscoped `DB::table(...)->count()/first()` reads them.

**Fix — the recorded fix shape, applied to the siblings:**
- `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundNoDecrementTest.php`, `…RefundStockTest.php`, `…VariantStockTest.php` — `myStockMovements()` / `myReceipts()` / `myReceiptChildren()` helpers scoping on the per-test `tenant_id` (the `receipt_id` FK walk for the child tables, which carry no tenant column), replacing every unscoped read. **All 25 replaced sites are reads**; no write was touched, no assertion weakened.
- `apps/api/database/factories/TenantFactory.php` — **gate residual R-3, global fix.** `Str::slug($this->faker->unique()->company())` de-duplicates the company NAME but `Str::slug()` then collapses distinct names onto one slug, tripping `tenants_slug_unique` nondeterministically. Now `Str::slug($this->faker->company()).'-'.Str::lower(Str::random(8))`. Verified no test asserts a factory-generated tenant slug (all slug assertions are on explicitly-set values).
- `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundDispositionStockTest.php` — **PG-only skip**, see below.

**AFTER (PG, full 14-file cluster):** `Tests: 86 passed (365 assertions)` — from 86/12F/335.

**The committing class on SQLite.** With `connectionsToTransact(): []` on the `:memory:` connection, the database is torn down between tests and every case after the first died with `no such table: tenants` — **15 failures / 1 pass, byte-identical with `dev`'s `TenantFactory` checked back out**, i.e. genuinely inherited and unrelated to the R-3 change. It is already in the `backend-test-pgsql` filter, so it now carries a documented PG-only skip (16 skips) instead of 15 permanently-waived reds.

## 7. `LiveCountingSchemaTest` — never ran on PG

**Root cause.** Two cases: `assertSame` on the `replay_audit` **jsonb** object (key order, same as §2), and a literal `PRAGMA table_info('inventory_counting_items')` — SQLite-only syntax that raises `SQLSTATE[42601]` on PostgreSQL, so the precision contract it exists to pin was never checked against production's driver.

**Fix.** `apps/api/tests/Feature/Inventory/LiveCountingSchemaTest.php` — order-insensitive jsonb comparison, and `sqlite_column_types_match_the_precision_contract` renamed to `column_types_match_the_precision_contract` and made driver-aware: `information_schema.columns` on PG, `PRAGMA` on SQLite, **asserting the same contract on both** (nullable, numeric-typed).

BEFORE (PG): 2F/8P. AFTER: 10 passed on both drivers.

## 8. Inventory / BatchExpiry fixtures

**`StockReservationDefaultBatchTest`.** `stock_reservations.source_id` is a `uuid` column (`2025_12_24_133728_create_stock_reservations_table.php:23`); the fixture passed `sourceId: 'manual-default-batch'`. Now `Str::uuid()->toString()`. 1F → 1 passed on both drivers.

**The 8 `BatchExpiry` "unique-constraint errors" — actually two distinct SQLite-only causes.** All three files are 14/14 green on PostgreSQL; the errors exist only on the SQLite leg:

- `AtomicFEFOConsumptionTest` (6): not a unique-constraint issue at all. Every case calls `FEFOInventoryService::consumeBatchesAtomically()`, whose candidate SELECT is hand-written PostgreSQL (`FOR UPDATE OF ibs SKIP LOCKED`). SQLite has no row-lock syntax and dies with `near "FOR": syntax error`. → class-level documented PG-only skip in `setUp()`.
- `BatchStockServiceVariantTest` ×1 and `BatchesVariantUniqueTest` ×1: `UNIQUE constraint failed: product_batches.company_id, product_batches.product_id, product_batches.batch_number`. `database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:20-21` **returns early on any non-pgsql driver** (it uses `CREATE UNIQUE INDEX CONCURRENTLY` + partial indexes), so under SQLite the pre-variant `unique_batch_per_product` constraint survives and rejects exactly the coexistence these two cases assert. The two sibling cases in `BatchesVariantUniqueTest` already carried this guard; the fix adds it to the remaining two. → documented PG-only skips, matching the existing idiom in the same file.

BEFORE (sqlite): `Tests: 14, Errors: 8, Skipped: 2, Passed: 4`. AFTER: 14 passed on PG / 4 passed + 10 documented skips on SQLite.

## 9. `backend-test-pgsql` allowlist — 16 classes ADDED

`.github/workflows/ci.yml` (`backend-test-pgsql` job, `--filter` alternation + a comment block explaining each group). The eight `feature-lane-*` jobs are **parked** behind `vars.SELF_HOSTED_RUNNER_READY`, so this filter is the only live PostgreSQL gate.

**(a) Would otherwise run NOWHERE** — these classes now carry documented PG-only skips:
`AtomicFEFOConsumptionTest`, `BatchesVariantUniqueTest`, `BatchStockServiceVariantTest`, `RepositoryMovementsEndpointTest`.

**(b) Were PG-red, now PG-green — the gate keeps them so (C-10 + §7 + §8a):**
`ResultWorkbookTest`, `PartiesImportBalancesTest`, `StockReservationDefaultBatchTest`, `LiveCountingSchemaTest`.

**(c) LEDGER C-7 — the bleed fix is only load-bearing on PG in a multi-class run:**
`PosCoreReceiptProjectionRefundNoDecrementTest`, `PosCoreReceiptProjectionRefundStockTest`, `PosCoreReceiptProjectionVariantStockTest`.

**(d) The 4 D-1 post-remise refund pins, blocked on §4:**
`ReceiptReturnRefactorTest` (`test_a_partial_return_of_a_post_remise_original_reverses_the_sealed_share`, `…a_full_return…`, `…a_return_of_a_pre_remise_original…`, `…a_return_of_a_mixed_era_original_is_refused`).

**(e) The W2-7 lot-provenance pins:**
`ReceiptStockPolicyTest`, `BatchTrackedSalesOrderConfirmFefoTest`, `ImplicitReservationFefoLotTest`, `RepairPhantomDefaultBatchesCommandTest`.

> **⚠ Group (e) required extra work and is worth the reviewer's attention.** Standalone all four are green, but in a multi-class PG run **8 more failures** surfaced from the same C-7 committed-state bleed — `RepairPhantomDefaultBatchesCommandTest` ×7 (`MultipleRecordsFoundException` from `sole()`, `Failed asserting that 28 is identical to 0`) and `ReceiptStockPolicyTest` ×1. Adding them to the allowlist without fixing that would have made the gate red. Both classes now scope their reads the same way (`myStockMovements` / `myBatchMovements` / `myStockReservations` / `myStockLevels` / `myBatches` / `myBatchStocks` on `tenant_id`, or `company_id` where `stock_reservations` has no tenant column). Verified: 68 passed with the committing class in the same run.

Cost note for the gate: `backend-test-pgsql` runs on a hosted runner and these 16 classes add roughly 195 tests of PG wall clock. If that is unacceptable under the CI budget, groups (b), (c) and (e) are the trimmable ones; group (a) is not — dropping it means those cases execute nowhere.

## 10. Production defects found — NONE

No item turned out to be a production defect. Every red traced to a test fixture, a test-only helper, a driver-capability difference, or a test-infra bleed:

- §4's `pos_receipts_totals` CHECK and `prevent_receipt_modification()` trigger both behaved exactly as designed — the fixture was writing rows production could not write.
- §5's `RepositoryMovementController` money guard is correct on PostgreSQL (production's only driver); only SQLite trips it.
- §5's `TreasuryDepositBridge` container wiring was already correct; only the hand-built test double was stale (no arity drift in production).
- §8's `add_variant_id_to_product_batches` pgsql-guard is deliberate (`CREATE UNIQUE INDEX CONCURRENTLY` + partial indexes have no SQLite equivalent). **Noted, not fixed:** it leaves the SQLite schema carrying the *pre-variant* uniqueness rule, which is a standing test-schema-parity gap — any future variant-uniqueness test will need the same PG-only guard. Closing it would mean editing the migration, i.e. production code, and is out of this lane's scope.

## 11. Residuals — seen, deliberately NOT touched

1. **`BatchExpiry/BatchExpiryDailyCheckCommandTest` — 1 PG failure** at `:135` (`assertSame([$tenantA->id, $tenantB->id], array_column($seen, 'argument'))` gets `[]`). A `tenants:run`/tenancy-iteration issue, outside this lane's eight items. Not in the PG allowlist and not added.
2. **C-7 gate residual R-4/F-5** — `projectionTableCounts()`'s whole-table snapshot is sound only while the suite is single-process. The docblock in `PosCoreReceiptProjectionTest` already carries this warning; the sibling classes now use tenant scoping instead, so they are paratest-safe.
3. **C-7 gate findings F-1/F-2/F-3** (vacuous child assertion, one missing `assertSame(1, …)` guard, a `0 === 0` stock comparison) are all Minor and all inside `PosCoreReceiptProjectionTest`, which this lane did not touch.
4. **`tests/Unit/POS/ReceiptReturnServiceTest` — 11 `ArgumentCountError`s** (W2-7 handback R5-10: `ReceiptReturnService::__construct()` expects 12 args, the test passes 11). Same *class* of defect as §5's ctor arity, but a different file and not named in this lane's brief. **Recommend a LEDGER row** — the W2-7 handback flags that two of the dead tests were the POS lot-restore pins.
5. **`ReturnNoteIntegrationTest` — 3 PG errors** (`chk_fiscal_mandatory_core` fixture), recorded as inherited in the W2-7 handback. Not in this lane's brief.
6. **`AdvanceReversalGlShapeTest` and `TreasuryDepositBridgeTest` were NOT added to the PG allowlist** — both are fully green on the SQLite leg, so their coverage is intact there and an addition would be pure runtime cost.

## 12. Files changed (all test/fixture/CI — production untouched)

```
.github/workflows/ci.yml                                                       (+25/-1, backend-test-pgsql filter + comment)
apps/api/database/factories/TenantFactory.php                                  (C-7 R-3, slug uniqueness)
apps/api/tests/Feature/BatchExpiry/AtomicFEFOConsumptionTest.php
apps/api/tests/Feature/BatchExpiry/BatchStockServiceVariantTest.php
apps/api/tests/Feature/BatchExpiry/BatchesVariantUniqueTest.php
apps/api/tests/Feature/Document/BatchTrackedSalesOrderConfirmFefoTest.php      (verification only — no edit)
apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundDispositionStockTest.php
apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundNoDecrementTest.php
apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundStockTest.php
apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionVariantStockTest.php
apps/api/tests/Feature/Fiscal/TreasuryDepositBridgeTest.php
apps/api/tests/Feature/Import/PartiesImportBalancesTest.php
apps/api/tests/Feature/Import/ResultWorkbookTest.php
apps/api/tests/Feature/Inventory/LiveCountingSchemaTest.php
apps/api/tests/Feature/Inventory/RepairPhantomDefaultBatchesCommandTest.php
apps/api/tests/Feature/Inventory/StockReservationDefaultBatchTest.php
apps/api/tests/Feature/POS/ReceiptReturnRefactorTest.php
apps/api/tests/Feature/POS/ReceiptStockPolicyTest.php
apps/api/tests/Feature/Treasury/AdvanceReversalGlShapeTest.php
apps/api/tests/Feature/Treasury/RepositoryMovementsEndpointTest.php
```

**No migration.** Not MIGRATION-BEARING.

## 13. Commits

```
8d4765e76  C-10 import uuid + jsonb order; advance-reversal decimal artifact; deposit-bridge ctor arity; repo-movements driver skip
c3e97fa21  Inventory/BatchExpiry: PG uuid fixture, PG-only FEFO row-lock + partial-index cases, PRAGMA -> driver-aware catalog, jsonb key order
6b82804bc  C-7 sibling bleed (3 classes), TenantFactory slug uniqueness (R-3), PG-only skip for the transaction-opt-out class
2f26f253e  ReceiptReturnRefactorTest PG fixtures + backend-test-pgsql allowlist (16 classes)
e80d843a1  Merge local dev (04ddf0266) — ci.yml actionlint-clean lane landed meanwhile
b3efda1e5  phpstan/pint hygiene on touched files
713465d7d  normaliseDecimal: is_numeric() narrowing instead of inline @var
c988481c3  W2-7 provenance classes scoped too (C-7 bleed x8) so the allowlist addition is safe
```

## 14. LEDGER updates the parent should make

- **C-7** → CLOSED. Target class was already fixed and merged; this lane closed residuals R-1/R-2 (three sibling classes scoped, the committing class given a documented PG-only skip) and R-3 (global `TenantFactory` slug fix). Full 14-file cluster: 86 passed on PG.
- **C-10** → CLOSED. Both `tests/Feature/Import` defects fixed and the two classes added to the live PG gate, so the directory's PG counts are no longer polluted.
- **NEW row recommended** — `tests/Unit/POS/ReceiptReturnServiceTest` 11 `ArgumentCountError`s (residual 4 above): two of the dead tests are the POS lot-restore pins.
