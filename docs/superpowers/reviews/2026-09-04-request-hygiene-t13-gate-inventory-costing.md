# Gate — Request Hygiene Phase A, Task 13 (ID-3/ID-4) — inventory-costing-reviewer (backend half)

- **Reviewer:** inventory-costing-reviewer (adversarial merge gate, backend only; the web half is the frontend-conventions-reviewer's)
- **Date:** 2026-09-04
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t13`, branch `lane/rh-t13-transfer-idempotency`, base `a97631051`
- **Reviewed range:** `a97631051..e4dbc5c57 -- apps/api` (commits `62044b5e7` backend, `fd8fef0d3` web, `e4dbc5c57` handback)
- **Handback:** `docs/handoff/HANDBACK-request-hygiene-T13-2026-09-04.md`
- **Plan contract:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` `## Task 13` (rev 11), lines 2746–3345

## VERDICT: spec ✅ · quality **CHANGES-REQUESTED**

The production change is correct and I could not falsify it. The write sequence of a *successful* `initiate()` is **mechanically proven byte-identical** to pre-refactor (normalized token diff below), the collision semantics are sound at both transaction depths, the re-read is tenant- **and** company-scoped, and the PG harness is a genuine two-connection race. Every named check re-ran green in my hands.

One blocking item, and it is not a code defect: **the only test in the repository that proves ID-4 runs in no automated lane.** It skips on SQLite by construction and is absent from the `backend-test-pgsql` `--filter` allowlist. The guard is dead on arrival. That is a one-line `ci.yml` fix and the plan itself omitted the requirement, so this is a plan gap the lane inherited — but it must close before merge, because the entire value of `StockTransferIdempotencyCollisionPostgresTest` is regression protection on a stock-movement path.

---

## Blocking findings

### B-1 [Important — merge-gating] The ID-4 collision harness is armed in zero CI lanes

- `apps/api/tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php:64` — `markTestSkipped('The real unique-collision harness is PostgreSQL-only.')`. Verified empirically on the default SQLite config:
  ```
  cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php
  SS                                                                  2 / 2 (100%)
  OK, but some tests were skipped!  Tests: 2, Assertions: 0, Skipped: 2.
  ```
- `.github/workflows/ci.yml:1117` — the `backend-test-pgsql` job runs PG-only classes through an explicit `--filter='/\\(ClassA|ClassB|…)::/'` allowlist of ~200 class names (that allowlist exists *precisely* because these classes skip on SQLite), plus a handful of explicit file paths at `ci.yml:1120-1122` and `ci.yml:1124+`. `grep -c StockTransferIdempotencyCollisionPostgresTest .github/workflows/ci.yml` → **0**. It is in neither the allowlist nor the path blocks.
- **Falsifying scenario:** move the `catch (UniqueConstraintViolationException …)` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:170` back *inside* the `DB::transaction()` closure (the exact regression the handback calls "the load-bearing decision"). The re-read then runs on a PostgreSQL connection in the aborted-transaction state and every same-key transfer race 500s in production. Local SQLite: green. `backend-test-pgsql`: green (class never selected). Every other job: green. The regression ships.
- **Why it matters here:** `initiate()` is a stock-movement authoring path. A silent regression on the replay branch means either a hard 500 on a legitimate retry, or — with a future well-meaning "just re-read inside the transaction" patch — an aborted-transaction cascade inside `ReplenishmentFulfillmentService`'s grouped transaction, rolling back an entire multi-group fulfilment.
- **Suggested fix (one line):** add `StockTransferIdempotencyCollisionPostgresTest` to the `--filter` alternation at `.github/workflows/ci.yml:1117`, or append `tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php` to the explicit-path invocation at `ci.yml:1120-1122`. Note the standing `ci.yml:1016-1020` / `1035-1039` caveat: `backend-test-pgsql` does not run on `push -> dev`, so this arms the class on PRs and `main` pushes only — the S-14 promotion leg remains owed either way.

---

## Non-blocking findings

### N-1 [Important] The PG harness proves *identity*, not *stock conservation*, on the replay path

`tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php:200-209` and `:239-250` assert `winnerId === $winner->id`, the seam call counts, `secondLookupTransactionLevel`, and `count() === 1` on `(tenant_id, company_id, idempotency_key)`. They never assert the inventory-integrity guarantee the whole feature exists for: that the loser wrote **nothing**.

The sibling SQLite pre-check test does exactly this — `tests/Feature/Inventory/InventoryTransferServiceTest.php:371-378` asserts `'45.0000'` with the comment `// Source only decremented once.` The new PG harness has no equivalent. It is structurally guaranteed (I read `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:87-111`: a non-concurrency `Throwable` triggers `$this->rollBack()` then rethrows the original `$e`; `rollBack()` performs a full PDO rollback at level 1 and `ROLLBACK TO SAVEPOINT` above it), so this is a coverage gap, not a defect.

Compounding it: the harness's winner is a bare header insert (`:283-303`) with **no lines and no movements**, so `loadMissing('lines')` at `StockTransferService.php:190` returns an empty collection and is never meaningfully exercised. A production winner always has lines.

- **Suggested fix:** in both methods, after the replay, assert the source `stock_levels.quantity` is still `'50.0000'` and that `stock_movements` for the tenant contains exactly the one `COLLISION-SEED` receipt (zero `TransferOut` rows). Optionally give the writer one `stock_transfer_lines` row so the replayed `lines` relation is non-empty.

### N-2 [Important] No second-company proof that the same idempotency key value is not shared across companies

The replay discriminator is `(tenant_id, company_id, idempotency_key)` (`StockTransferService.php:200-204`), backed by `stock_transfers_idempotency_unique` on `(tenant_id, company_id, idempotency_key)` (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:67`) — the scoping is correct, and `generateTransferNumber()` at `StockTransferService.php:1139-1149` is likewise company-scoped. But no test in the lane creates a second company and re-uses company A's key to prove company B gets its own transfer rather than A's. `docs/conventions/09-SECOND-OF-EVERYTHING.md` asks for a second-company leg on any lane touching a number-keyed, operator-visible entity. Not a BLOCKER: the lane adds no new unique key and the existing key already carries `company_id`.

### N-3 [Minor] `expectExceptionObject()` does not pin object identity — the plan's claim is over-stated

`tests/Feature/Inventory/InventoryTransferServiceTest.php:840` / `:854` use `$this->expectExceptionObject($collision)`. PHPUnit's `expectExceptionObject()` expands to class + message + code, not identity. The plan (line ~3255) and the handback both claim "the exact original exception object is rethrown"; a rewrap that preserved class/message/code would pass. The handback's `RuntimeException` mutation proof does show the tests are non-vacuous, so this is a wording/precision issue only. `assertSame($collision, $caught)` inside a `try/catch` would pin it.

### N-4 [Minor] Replay returns HTTP **201 Created** for a resource this request did not create

`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:174-202`: both the fresh path and the replay path fall through to `response()->json([...], 201)`. The response **shape** is identical (the controller re-`load()`s the full relation set at `:199` on the replayed model, so `lines.product.unitOfMeasure`, `lines.variant`, `lines.batchAllocations.batch` are all hydrated the same way). This matches the pre-existing pre-check branch (`StockTransferService.php:108-114` already returned 201 on a repeat) so it is not a T13 regression, and ID-3/ID-4's goal — no duplicate row, no duplicate decrement — is met. Flagging only because a replayed transfer may already be `completed` or `cancelled`, and the client is told `201` with a non-`in_transit` status. Acceptable; document it if the FE ever branches on status.

### N-5 [Minor] The key carries no request fingerprint — a same-key resubmit with a different payload silently returns the old transfer

`StockTransferService.php:108-114` (pre-check) and `:185-190` (collision replay) both key solely on `idempotency_key`. If a client reuses a key with a *different* payload, the server returns the earlier transfer and the new payload is silently dropped. Pre-existing semantics, unchanged by T13, and the FE reset-after-await contract (handback item 4) closes the practical window. Recording it because the collision branch widens the surface from "pre-check hit" to "any unique violation with a committed same-key row".

### N-6 [Minor] The adjustment side of ID-3 has no equivalent collision replay

ID-3 now sends an idempotency key from the adjustment create page, but `apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:87-104` is still read-then-insert with a self-documented benign-but-confusing race (`… the loser's INSERT is refused by stock_adjustments_idempotency_unique …`, gate M-6). Post-T13 the worst case on an adjustment double-click is a 500 instead of a **duplicate posted adjustment** — a strict improvement — so this is a declared residual, not a T13 defect. Worth carrying into Phase B alongside the S-19 different-key number race.

### N-7 [Minor] `findExistingTransfer()` is a footgun when the key is null

`StockTransferService.php:198-205` builds `where('idempotency_key', null)`, which compiles to `idempotency_key = ?` bound to NULL and matches nothing in SQL. Both call sites guard on `!== null` (`:107`, `:181`), so it is correct today. A `whereNotNull` assertion or a `?string` parameter would make the seam self-defending for the next caller.

### N-8 [Minor] PHPStan reports 8 pre-existing errors in `InventoryTransferServiceTest.php`

Not a gate failure: `apps/api/phpstan.neon:6-7` scopes `paths:` to `app/` only, so `tests/` is never analysed in CI, and the plan's Step 6 named only the service, the new PG test and the replenishment service. I ran the wider set anyway; all 8 are on lines the lane did not author (verified against `git show a97631051:` with the +5 import-line shift): `:185`, `:196`, `:239` (`numeric-string` args), `:323` (`precision.floatCastOnDecimalProperty` — `(float) $destStock->quantity`), `:447-448`, `:644`, `:657` (`property.nonObject`). The rule-19 float cast at `:323` is pre-existing test debt on a `decimal:4` column; worth a sweep but out of this lane's scope.

---

## What held up (verified against the eight review questions)

### 1. Stock / WAC / costing integrity — successful path is provably unchanged ✅

I extracted `initiate()` from `a97631051` and from HEAD, stripped comments and whitespace, tokenized on `;{}` and diffed. The **entire** delta is:

```
+try {
-$existing = StockTransfer::query()->where('tenant_id',…)->where('company_id',…)->where('idempotency_key',…)->first();
+$existing = $this->findExistingTransfer($data);
-$transfer = StockTransfer::create([ 'id' => …, 'tenant_id' => …, … 14 keys … ]);
+$transfer = $this->insertTransfer($data, $transferNumber);
+catch (UniqueConstraintViolationException $exception) { … }
+protected function findExistingTransfer(…) { …the removed query, verbatim… }
+protected function insertTransfer(…) { …the removed create(), verbatim, same 14 keys in the same order… }
```

No statement was reordered, added, or removed inside the transaction. Validation order (`bccomp` qty > 0 → `assertVariantValidForProduct` → line insert → batch allocations) is unchanged at `StockTransferService.php:137-165`. FEFO auto-allocation still runs before the header insert (`:130-134`). `moveSourceToInTransit()` — the cost lock, the `stock_level` `lockForUpdate`, the `cost_price` snapshot taken *after* the row lock, the `TransferOut` movements — is untouched (`:514+`), so the canonical `advisory -> stock_level -> product` order documented at `:526-529` is preserved. `ProductCostLock` uses `pg_advisory_xact_lock` (`app/Modules/Inventory/Domain/Services/ProductCostLock.php:47`), i.e. transaction-scoped, so a savepoint rollback cannot leak a lock. No new bcmath, no scale change, no float, no `app()`, no `mixed`.

**Partial-commit on the replay path is impossible.** The `catch` is outside `DB::transaction()`, and Laravel's `handleTransactionException` (`vendor/.../ManagesTransactions.php:87-111`) calls `$this->rollBack()` *before* rethrowing for any non-concurrency `Throwable`. At depth 0 that is a full PDO rollback; at depth ≥1 a `ROLLBACK TO SAVEPOINT`. The catch body therefore never observes a half-written transfer.

### 2. Collision semantics ✅

- Key absent → unconditional rethrow (`:181-183`), proven by `test_initiate_rethrows_unique_violation_when_no_key_was_supplied` (`InventoryTransferServiceTest.php:836`).
- Key present but no committed row at that key → rethrow of the original exception (`:186-188`), proven by `test_initiate_rethrows_collision_on_different_unique_index_when_idempotency_reread_finds_no_row` (`:849`), which deliberately fires a `stock_transfers_company_number_unique`-shaped violation with key `'different-index'`. **A number collision belonging to someone else's key can never return their transfer** — the reread is keyed, and a row already at this key would have been caught by the pre-check at `:108`.
- Not inspecting the constraint name is correct and I re-derived why: a same-key race also collides on `stock_transfers_company_number_unique`, because both callers compute the same `COUNT()+1` (`:1142-1146`). Both indexes confirmed live on the lane DB: `stock_transfers_company_number_unique` and `stock_transfers_idempotency_unique`, and the latter is a plain (non-partial) unique so PostgreSQL's multiple-NULL semantics keep unkeyed transfers out of it.
- **The winner is genuinely committed when we re-read.** PostgreSQL raises `23505` either immediately (conflicting row already committed) or after blocking on the in-flight inserter until it commits — if that inserter had rolled back, our INSERT would have *succeeded*. So a `23505` implies a committed conflicting row, header **and** lines, atomically.
- Nested case rolls back only the savepoint: `secondLookupTransactionLevel` is asserted `0` at depth 0 (`:203`) and `1` at depth 1 (`:242`), and the enclosing `DB::transaction` then **commits successfully** (`:243` asserts level back to `0`). A PG transaction left in the aborted state would have thrown `current transaction is aborted` on the re-read, so the outer commit is itself a falsifier. The winner is committed by a genuinely separate connection (`self::WRITER`, `:169-175`, cloned from `database.default` and purged), not by a savepoint trick.
- Test is genuinely falsifying: `winnerId` is only ever set inside the `insertTransfer` override (`:284-285`), so against unrefactored code the seams are never called and `assertSame($service->winnerId, $winner->id)` compares `''` to a UUID — exactly the red run the handback records.

### 3. Tenancy ✅

Production re-read carries tenant **and** company: `StockTransferService.php:200-204`. `StockTransfer` (`app/Modules/Inventory/Domain/StockTransfer.php:54-56`) declares no global scope and no tenancy trait, so those two explicit predicates *are* the scoping — nothing can be silently dropped. The harness's writer INSERT stamps the same `tenant_id`/`company_id` (`:288-289`). No cross-company replay is reachable: the unique index is `(tenant_id, company_id, idempotency_key)`, so a foreign-company row cannot even collide with ours. Gap: no positive second-company test — see N-2.

### 4. Replay response ✅ (with N-4)

Controller `store()` (`StockTransferController.php:174-202`) receives the replayed model, runs the identical `->load([...])` at `:199` and the identical `formatTransfer($transfer, includeLines: true)` at `:201`. Shape is byte-for-byte the same as a fresh create. Status is `201` in both cases — same as the pre-existing pre-check branch, so not a regression. Acceptable.

### 5. `ReplenishmentFulfillmentService.php:102` (depth-1 consumer) ✅

The call sits inside the service's own `DB::transaction(…, attempts: 3)` (closes at `:106`), after `assertSourceAvailability()` at `:93-98` has taken `lockForUpdate()` on the source `stock_levels`. Two consequences I checked:
- The savepoint rollback discards only the failed group's writes; the pre-savepoint row locks from `assertSourceAvailability()` survive, so the re-read runs with the group's stock still pinned.
- **Over-issue is not reachable.** A concurrent winner for the same source location would have to take the same `stock_level` locks to run `moveSourceToInTransit`, so it cannot commit while we hold them; if it committed *before* we locked, its decrement is already visible in the rows we read. The replay therefore returns a transfer whose decrement really happened, exactly once.
- The idempotency key is deterministic and group-distinct (`'replenishment:'.sha1(src:dst:requestIds)`, `:84-86`), covered by `test_multi_destination_transfer_uses_distinct_group_idempotency_keys` (`tests/Feature/Replenishment/ReplenishmentActionsTest.php:308`).
- `ReplenishmentFulfillmentService` is reachable only from `ReplenishmentActionController` (`app/Modules/Replenishment/Presentation/Controllers/ReplenishmentActionController.php:24`) — **no queue/console caller**, so the rule-19 no-arg-`getScale()` hazard does not apply. `StockTransferService` contains no bare `getScale()`; its only `CurrencyScale::bcformat` calls (`:679`, `:689`, `:726`) are in `complete()` with explicit scales and are untouched by this diff.
- **The SQLite skip is explicit**, not silent: `markTestSkipped('The real unique-collision harness is PostgreSQL-only.')` at `StockTransferIdempotencyCollisionPostgresTest.php:64`, driven by `DB::getDriverName() !== 'pgsql'` at `:63`. Confirmed by the `SS / Skipped: 2` run above. `ReplenishmentActionsTest` covers the depth-1 *happy* path (create-transfer grouping `:86`, FEFO `:107`, insufficient-stock `:148`/`:353`, permissions `:179`); the depth-1 *collision* is PG-only by construction, which is precisely why B-1 matters.

### 6. Deviation 3 and typing hygiene ✅

`insertTransfer()` (`StockTransferService.php:211-232`) keeps the original `/** @var StockTransfer $transfer */` and returns `$transfer`. The justification checks out: `StockTransfer::create()` is inferred as `Model`, so the plan snippet's inline return would fail level 8. Behaviour identical. No `mixed`, no `app()`, no `float`, no `parseFloat` in any production file in the diff; `app()` appears only in the two test harnesses, which is the established test convention. Deviations 1, 2, 4, 5, 6 all match what I read.

### 7. Bootstrap ✅ and idempotent on re-run

`ensureCentralSchemaMigrated()` (`StockTransferIdempotencyCollisionPostgresTest.php:140-145`) checks `Schema::connection('central')->hasTable('tenants')` then `Artisan::call('migrate', ['--force' => true])`. Under `phpunit-pgsql.xml` `DB_CENTRAL_DATABASE` is deliberately unpinned and falls back to `DB_DATABASE` (`config/database.php:133`), and the lane command sets both to `autoerp_test_t13` — so the `central` probe and the default-connection `migrate` hit the same physical database. **`migrate --force` does load the tenant migrations**: `AppServiceProvider::loadTenantMigrationsInTestingEnvironment()` (`app/Providers/AppServiceProvider.php:239-246`) calls `loadMigrationsFrom(database_path('migrations/tenant'))` whenever `environment('testing')`, and `phpunit-pgsql.xml` forces `APP_ENV=testing`. Registered paths are part of the migrator's path set, so both `database/migrations/` and `database/migrations/tenant/` run in one pass. Confirmed at runtime — `stock_transfers`, `stock_levels`, `stock_movements` (all tenant-path tables) exist in `autoerp_test_t13`.

**Re-runnable:** I ran the class three times (once together, twice filtered). Second and later runs skip the migrate (`hasTable` true) and complete in ~0.9 s. `tearDown()` (`:147-166`) deletes every owned row scoped by `tenant_id` / `company_id`, and the writer connection is purged and de-configured first (`:148-149`), so its committed winner is cleaned too. Verified empirically after three runs:
```
select count(*) from stock_transfers;  -> 0
select count(*) from stock_movements;  -> 0
select count(*) from stock_levels;     -> 0
select count(*) from products;         -> 0
select count(*) from tenants;          -> 0
```
Zero leakage into the shared per-session database.

---

## 8. Commands and outputs (all re-run by me in this worktree)

### PG collision class, together

```
cd /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t13/apps/api && \
DB_HOST=127.0.0.1 DB_PORT=5463 DB_DATABASE=autoerp_test_t13 DB_CENTRAL_DATABASE=autoerp_test_t13 \
php artisan test -c phpunit-pgsql.xml tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php

   PASS  Tests\Feature\Inventory\StockTransferIdempotencyCollisionPostgresTest
  ✓ initiate rereads committed winner after dual generated number and k… 0.99s
  ✓ initiate inside outer transaction rereads dual collision after save… 0.46s

  Tests:    2 passed (13 assertions)
  Duration: 1.50s
```

### PG collision class, one `--filter` per process

```
### test_initiate_rereads_committed_winner_after_dual_generated_number_and_key_collision
  ✓ initiate rereads committed winner after dual generated number and k… 0.91s
  Tests:    1 passed (5 assertions)

### test_initiate_inside_outer_transaction_rereads_dual_collision_after_savepoint_rollback
  ✓ initiate inside outer transaction rereads dual collision after save… 0.91s
  Tests:    1 passed (8 assertions)
```

### Same class on SQLite (proves the explicit skip — and B-1)

```
cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php

SS                                                                  2 / 2 (100%)
OK, but some tests were skipped!
Tests: 2, Assertions: 0, Skipped: 2.
```

### Named SQLite backend paths (Step 6)

```
cd apps/api && ./vendor/bin/phpunit \
  tests/Feature/Inventory/InventoryTransferServiceTest.php \
  tests/Feature/Inventory/StockTransferAutoAllocateFefoTest.php \
  tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php \
  tests/Feature/Inventory/StockTransferLocationScopeTest.php \
  tests/Feature/Inventory/StockTransferRestrictedMembershipTest.php \
  tests/Feature/Inventory/StockTransferShowBatchAllocationsTest.php \
  tests/Feature/Inventory/StockTransferVariantTest.php \
  tests/Feature/Replenishment/ReplenishmentActionsTest.php

................................................................. 65 / 77 ( 84%)
............                                                      77 / 77 (100%)

Time: 00:54.401, Memory: 183.00 MB

OK (77 tests, 245 assertions)
```

Matches the handback's 77/245 exactly.

### PHPStan level 8 — plan-named files

```
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5463 DB_DATABASE=autoerp_test_t13 DB_CENTRAL_DATABASE=autoerp_test_t13 \
  ./vendor/bin/phpstan analyse \
    app/Modules/Inventory/Application/Services/StockTransferService.php \
    tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php \
    app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php

 3/3 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors
```

### PHPStan level 8 — plus the modified test file (my addition, see N-8)

```
… + tests/Feature/Inventory/InventoryTransferServiceTest.php
 [ERROR] Found 8 errors
```
All 8 in `InventoryTransferServiceTest.php` at `:185 :196 :239 :323 :447 :448 :644 :657`, all pre-existing lines (`phpstan.neon:6-7` scopes analysis to `app/`, so `tests/` is out of CI scope). Not a gate failure.

### Pint

```
cd apps/api && ./vendor/bin/pint --test \
  app/Modules/Inventory/Application/Services/StockTransferService.php \
  tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php \
  tests/Feature/Inventory/InventoryTransferServiceTest.php

{"result":"pass"}
```

### CI arming probe

```
cd /Users/houssamr/Projects/syneriva/apps/erp && grep -c "StockTransferIdempotencyCollisionPostgresTest" .github/workflows/ci.yml
0
```

### Post-run database residue probe

```
PGPASSWORD=… psql -h 127.0.0.1 -p 5463 -U autoerp -d autoerp_test_t13 \
  -c "select count(*) from stock_transfers" -c "select count(*) from stock_movements" \
  -c "select count(*) from tenants" -c "select count(*) from stock_levels" -c "select count(*) from products"
0 / 0 / 0 / 0 / 0
```

Container `autoerp_pg_t13` (127.0.0.1:5463) left running as instructed. No files in the worktree were modified by this review.

---

## Cross-cutting checks

- **Second-of-everything** (`docs/conventions/09-SECOND-OF-EVERYTHING.md`): the lane adds **no new unique key** — `stock_transfers_company_number_unique` and `stock_transfers_idempotency_unique` both already carry `company_id` (`database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:66-67`), so no `TenantOnlyUniqueOnCatalogueTablesRatchetTest` exposure and no waiver needed. Re-run/idempotency coverage exists (`InventoryTransferServiceTest.php:352` sequential retry with stock conservation; `ReplenishmentActionsTest.php:308` distinct group keys). **Second-location** covered by the existing suite (`StockTransferLocationScopeTest`, `StockTransferLocationAccessRuleTest`, both green). **Second-company: missing** → N-2.
- **One surface per concept** (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`): no new noun. `idempotency_key` on `stock_transfers` predates the lane; `CreateStockTransferInput` / `CreateStockAdjustmentInput` already declared the field, so no hand-rolled FE type beside the generated DTO and no type-generation change. Two write paths do exist for idempotent creation (`StockTransferService::initiate` and `StockAdjustmentDocumentService::createDraft`) with different collision behaviour — different tables, different concepts, so not a second surface, but see N-6. `docs/glossary.md` (81 lines) carries no `idempotency key` and no `stock transfer` row; pre-existing gap, not introduced here.
- **Benchmark-first** (`docs/conventions/10-BENCHMARK-FIRST-SPECS.md`): not applicable — this is a concurrency-correctness lane on an existing flow, not a new user-facing flow.
- **Data-meaning tests**: the new tests assert real behaviour (returned row identity, transaction depth, row counts, exception propagation), not status codes or "no exception". The one gap is that they assert row *counts* where the guarantee is about a *balance* → N-1.
- **Rule 19 precision**: no float, no `parseFloat`, no `number_format`, no scale change, no new bcmath in the diff. The single float cast surfaced by PHPStan (`InventoryTransferServiceTest.php:323`) is pre-existing test debt outside CI's analysis scope.

## What to fix before merge

Add `StockTransferIdempotencyCollisionPostgresTest` to the `backend-test-pgsql` selection in `.github/workflows/ci.yml` (allowlist at `:1117` or the explicit-path block at `:1120`) — the ID-4 guard currently runs in no lane; then, ideally in the same commit, add the source-stock-conservation assertions to both PG methods (N-1).
