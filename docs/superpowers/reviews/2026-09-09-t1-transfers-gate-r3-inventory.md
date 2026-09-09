# Gate r3 — lane T-1 (inventory-costing-reviewer, 2026-09-09)

**Audited HEAD:** `af6e83028e3b448cbddda73040ca4fe17d90acf6` (`lane/t1-transfers-edge`, worktree `.worktrees/t1-transfers`).
**Delta reviewed:** `a2fd11174..af6e83028` (5 commits `e769e768d`, `58fe90cde`, `568f3d38f`, `758fabeb1`, `af6e83028`) — 13 files, +446/−53, of which `apps/api` is **tests only**. Re-verified against the whole-lane diff from `63e0e5e16`: 16 files, +1361/−9, `apps/api/app` = one file (`CreateTransferFromRequestsRequest.php` +26/−2, unchanged this round, budget ratified — not re-raised), `apps/web` = **empty**, `docs/superpowers/specs/` = **empty**. Every line number re-derived at HEAD. All PG work ran with a verified-exclusive `autoerp_test_t`.

## Round-2 closure table

| # | r2 finding | Verdict | Evidence at HEAD |
|---|---|---|---|
| **MAJOR-1** | PG concurrency harness non-deterministic; `db:wipe` destroys shared schema | **CLOSED** | `grep -n 'DatabaseMigrations\|db:wipe\|RefreshDatabaseState'` over the three lane test files → no matches. `StockTransferCompleteConcurrencyPostgresTest.php:57` restores the `// No RefreshDatabase` comment; `:71-87` is the bounded hand-clean: six tables by `tenant_id` (`:78-80`), `locations` by `company_id` (`:81`), `users` by `id` (`:82`), `companies` by `id` (`:83`), and `:84` `self::assertSame(1, DB::table('tenants')->where('id', $this->tenant->id)->delete())` — the delete is asserted, which the r1 version at `691eacba2` did not do. Harness is the sibling pattern of `StockTransferIdempotencyCollisionPostgresTest.php:147-167` named as precedent at `.github/workflows/ci.yml:1120`. Exclusive-DB requirement stated in the class docblock `:29-33` and in the handback resume recipe `HANDBACK-T1-2026-09-09.md:49` and round-2 note `:118`. Determinism reproduced — see **Determinism evidence**. |
| **MINOR-1** | production footprint +26/−2 over the ≤20-line seam | **CLOSED (ratified)** | `git diff --numstat 63e0e5e16..HEAD -- apps/api/app` → `26 2 …/CreateTransferFromRequestsRequest.php`; `git diff a2fd11174..HEAD -- apps/api/app` → empty. Ratification at evidence `:5` and `HANDBACK-T1-2026-09-09.md:93`. |
| **MINOR-2** | denial message is three copies of one literal | **CLOSED (ticket, accurate)** | `2026-09-09-t1-location-denial-message-constant.md:5` — all three citations verified: `ValidLocationAccess.php:86`, `StoreStockTransferRequest.php:40,43`, `CreateTransferFromRequestsRequest.php:30`. Envelope identity re-confirmed (`StoreStockTransferRequest.php:50`, `CreateTransferFromRequestsRequest.php:31`). |
| **MINOR-3** | retirement table landed in superseded spec rev 1 | **CLOSED** | `git diff --stat 63e0e5e16..HEAD -- docs/superpowers/specs/` → empty (rev-1 file byte-identical to lane base). All four tickets carry a retirement acceptance line (`…recalled-in-transit.md:17`, `…idempotency-payload.md:17`, `…settlement-replay.md:21`, `…cancel-in-progress.md:17`), each naming the method to unskip and the companion current-behaviour pin to retire in the same change. Skip-rot green: all six `markTestSkipped` at HEAD carry a ticket path (`StockTransferEdgeCasesTest.php:103,268`; `ReplenishmentEdgeCasesTest.php:110,181`) or a driver reason (`ReplenishmentEdgeCasesTest.php:129`, `StockTransferCompleteConcurrencyPostgresTest.php:55`). |
| **MINOR-4** | 403 reaches operator as raw English string | **CLOSED (ticket, accurate)** | `2026-09-09-t1-replenishment-dialog-refusal-i18n.md:5` — verified `CreateTransferDialog.tsx:99-100` and the reuse target `stock-adjustments/api/refusals.ts:27,60`. `apps/web` has zero lane diff. |

## BLOCKER

None. Nothing in the lane changes WAC arithmetic, stock levels, reservations, batch/FEFO consumption, movement signs, opening balances or GL. Whole-lane `apps/api/app` footprint is one FormRequest's `failedValidation`; `StockTransferService.php`, `WeightedAverageCostService.php`, every counting file, every migration and every DTO are untouched.

## MAJOR

None.

## MINOR

**[MINOR-5] `StockTransferCompleteConcurrencyPostgresTest.php:139` — `self::assertSame(0, $journalsBefore)` is an unscoped global row count inside a class that deliberately does not use `RefreshDatabase`, so it is order-coupled to every other class in the same PHPUnit process.** The delta assertions (`:142` inside the transaction and `:152` after commit) are robust; the absolute `0` converts any committed `journal_entries` row authored by an earlier class in the `backend-test-pgsql` process into a red for this class. `journal_entries` is not in the `:78-83` clean-up list. Currently safe, verified: the only other non-transactional classes in the pgsql filter (`ci.yml:1141`) are `StockTransferIdempotencyCollisionPostgresTest` (`:155-165`, stock tables only) and `SupportAccessPostgresEndToEndTest` (`:98-110`, central tables only); spot-checked others (`ExpensePostTest:34`, `TreasuryReceiptBridgeTest:77`, `BatchChainE2ETest:50`) use `RefreshDatabase`. **Suggested fix (T-2, one line):** scope the count with `->where('tenant_id', $this->tenant->id)`, or drop `:139` and keep the two delta assertions.

**[MINOR-6] `StockTransferEdgeCasesTest.php:172` — the m8 reservation is a raw `StockLevel::query()->…->update(['reserved' => '1.0000'])`, not a reservation authored through the reservation service.** It exercises the read arithmetic correctly (`LocationStockQueryService.php:130-134,176,179`), so the 5.0000/9.0000 pin is right, but it pins only the reader. **Suggested fix (T-2):** reserve through `StockReservation`/the sales-order path when that lane is open.

**[MINOR-7] `HANDBACK-T1-2026-09-09.md:145` claims "git diff --check | PASS" — true for the working tree, false for the lane range.** `git diff --check 63e0e5e16..HEAD` reports three trailing-whitespace hits, all in `HANDBACK-T1-2026-09-09.md:5-7` (markdown hard line breaks). Cosmetic.

**[carried, no action] MINOR-2 and MINOR-4** are correctly filed as T-2/T-3 tickets; both citations verified above.

## Determinism evidence

All runs from the worktree root via `sh docs/sessions/t1/pg-test.sh …` against `autoerp_test_t` @ `127.0.0.1:5433`; exclusivity re-checked before every invocation with the handback's `pg_stat_activity` query. Pre-flight: other clients 0; public base tables 277.

| # | Command | Other clients before | Result | PHPUnit time | wall |
|---|---|---|---|---|---|
| 1 | `pg-test.sh tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php` | 0 | `OK (2 tests, 20 assertions)` | 00:03.959 | 4.31 s |
| 2 | same | 0 | `OK (2 tests, 20 assertions)` | 00:02.362 | 2.53 s |
| 3 | same | 0 | `OK (2 tests, 20 assertions)` | 00:02.806 | 2.99 s |
| 4 | `pg-test.sh --filter '/(ReplenishmentEdgeCasesTest\|StockTransferCompleteConcurrencyPostgresTest\|StockTransferEdgeCasesTest)::/' tests/Feature` | 0 | `OK, but there were issues! Tests: 28, Assertions: 187, PHPUnit Deprecations: 339, Skipped: 4` — no errors, no failures | 01:04.720 | 65.86 s |

Post-run: other clients 0; public base tables 277 — schema intact. Three consecutive green + one green multi-class, as MAJOR-1 required. (Wall time 1:04 vs the handback's 8:12 because the schema was already migrated; both green.)

Assertion-count arithmetic: 20 = test 1 (blocked, child exit, child payload, dest `4.0000`, source `6.0000`, one `TransferIn`, `cost_price 6.000000`, one `Adjustment`) + test 2 (`journalsBefore` 0, in-transaction journal delta, `transactionLevel`, `Completed`, `cost_price`, `quantity_before`, `quantity_after`, `avg_cost_after`, post-commit journal delta, post-commit `cost_price`) + 2 × the `tearDown` tenant-delete assertion at `:84`.

SQLite cross-check from `apps/api`: `StockTransferEdgeCasesTest` → 2 skipped, 15 passed (89 assertions); `ReplenishmentEdgeCasesTest` → 3 skipped, 6 passed (58 assertions). Both match the handback.

**The 339 deprecations — classified.** PHPUnit test-runner metadata deprecations (`Metadata in doc-comments is deprecated and will no longer be supported in PHPUnit 12`), pre-existing, repo-wide, not introduced by this lane: `--display-phpunit-deprecations` on a 2-test filtered run still reports exactly 339 (emitted at suite-build time when `tests/Feature` is scanned; filter-invariant); none names a lane class; the lane's three classes use attributes only (`#[Group('pg')]`, `#[DataProvider]`); running the class by path reports zero deprecations.

## Verified fine

- Costing / rule 19 across the delta: no `(float)|floatval|number_format|parseFloat|(double)` in the touched files; every quantity a scale-4 string, every cost a scale-6 string; no new bcmath scale literal in `app/`.
- WAC assertions at scale 6 and driver-independent (`…ConcurrencyPostgresTest.php:131,146,150,153`; `StockTransferEdgeCasesTest.php:205,211`), backed by `Product.php:164` `decimal:6` and `StockMovement.php:104` `decimal:6`.
- The invariant is pinned on both a quiet and a raced path: `:148-150` pins the in-transaction denominator; the new m1 arm (`:91`, `:131-132`) asserts after the loser is refused `cost_price === '6.000000'` and exactly one `Adjustment` — a real double-capitalisation tripwire; `:130` still pins exactly one `TransferIn`.
- The zero-GL pins are honest: `WeightedAverageCostService.php` contains no `InventoryGlPostingBuffer`/journal reference; `capitalizeTransferCost` (`:631`, `:700-708`, `reason: 'stock_transfer_cost'`) books no GL; the freight ticket states this correctly and forbids GL in T-1.
- Stock-GL MAJOR-2 source-lot pins are real ledger assertions on both the recall and expiry legs (`:125-127`, `:139-141`).
- m8 fix is correct against the actual reader (`LocationStockQueryService.php:124-134,176,179`; the DTO comment `StockDistributionDTO.php:20` says "on-hand", so the field name genuinely lies); both data-provider legs consistent with one reserved source unit.
- m4 refusal pin is data-meaning (`ReplenishmentEdgeCasesTest.php:211,219`).
- m5 / m6 ticket claims verified against source: `settleLine` writes only `replenishment_requests` (`:63-71`); `handle()` catches and logs per-line `Throwable` (`:30-40`); listener registered synchronously (`ReplenishmentServiceProvider.php:19`); `ReplenishmentFulfilled` has no registered listener; `…recalled-in-transit.md:7` cites `StockTransferService.php:358` correctly (destination `receive()` loop, no recall recheck).
- m3 evidence softening correct: `ValidLocationAccess.php:85` → `LocationContext::canAccessLocation` (`:224-239`) returns `true` outright when `allowed_location_ids === null`.
- Lane wiring / style / static analysis at HEAD: manifest check exit 0 (1517 Feature classes in 74 groups); `pint --test` pass; `phpstan` level 8 no errors; `ci.yml` and the lane manifest absent from `a2fd11174..HEAD`; `git status --porcelain` clean.
- Test quality: no `assertTrue(true)`, no mocked subject, no fabricated payloads; `RefreshDatabase` + seeder + real models on both SQLite classes; every added assertion is a balance, lot quantity, row count or journal count; second-company coverage at `ReplenishmentEdgeCasesTest.php:222`; the transfer `SUM(stock_transfer_lines.quantity)` aggregate (`LocationStockQueryService.php:154-158`) is exercised on PostgreSQL by the multi-class run.
- Inventory invariants hunted for and not found: no second stock-movement write path, no device-side decrement, no hand-rolled movement sign, no opening-balance path, no `if (batchTracked) continue;`, no FEFO/expiry-ordering change, no new `stock_level` lock ordering, no new `unique(['tenant_id', …])`, no bare `getScale()` in queue/console-reachable costing. No new noun/table/import type/FE type.

## Merge instructions

1. MAJOR-1 closed and independently reproduced — the promotion hold from gate r2 is lifted. Merge into local `dev` and promote.
2. Carry the exclusivity contract into the promotion note: the class is non-transactional by design; `ci.yml:1141` schedules it into the shared `backend-test-pgsql` process (safe today: bounded, asserted clean-up matching the `StockTransferIdempotencyCollisionPostgresTest` precedent); it must never be scheduled into a parallel/sharded database leg (`StockTransferCompleteConcurrencyPostgresTest.php:29-33`, `HANDBACK-T1-2026-09-09.md:49`).
3. Production budget: whole-lane `apps/api/app` = +26/−2, one FormRequest — ratified; nothing further owed.
4. No spec relocation owed (MINOR-3 closed by restoring the rev-1 file and moving acceptance into the four tickets).
5. Carry into T-2/T-3 as tickets: T1-4 recalled-in-transit (+ valuation arm), T1-10 idempotency payload, T1-11 quantity-blind settlement (prioritise over replay hardening), T1-14 cancel-in-progress, T1-5 product-detail transit + `ProductController.php:1091` scale-2 literal, freight-capitalisation GL, denial-message constant (MINOR-2), replenishment-dialog refusal i18n (MINOR-4), MINOR-5 (scope the `journal_entries` baseline), MINOR-6 (reserve through the real writer).
6. No conflicts expected; re-run `php tools/feature-lane-manifest-check.php` after the merge if any other lane lands ceilings in the same batch.
7. The 339 deprecations are not a lane artefact — do not gate on them; separate mechanical migration.
8. Still unverifiable from the repo: the live demo-tenant fixture cleanup claim at `HANDBACK-T1-2026-09-09.md:55`.

**What to fix before merge:** nothing — MAJOR-1 is closed and reproduced; MINOR-5/6/7 are follow-ups.

VERDICT: MERGE
