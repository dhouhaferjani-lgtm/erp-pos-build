# M2 adversarial merge-gate review — round 1

**Milestone:** M2 — THE CUTOVER COMMIT (T14+T15+T16+T16b+T16d+T16e+T17)
**Reviewed:** `git diff 26b63f0ff..HEAD`; M2 commit = `f848dab39` (single commit ✓), preceded by `90b8f57e7` (citation re-run) and followed by `2067ecfbf` (YAML only).
**Amending authority:** none.
**Lenses:** inventory-costing (applies), fiscal-pos (applies). tenancy-authz / treasury not named — not applied beyond the standing checks.

## What holds up

Verified against code, not the report: the buffer is `scoped` (`InventoryServiceProvider.php:35`), so the cross-service enqueue/rollback in the scrap pair is sound; `ApplyFiscalEventProjectionJob.php:394` invokes `apply()` outside any transaction, so the POS flush genuinely lands at depth 1; C-1/C-2/C-3 roots are driven through real HTTP endpoints (`InventoryGlCompositeRootTest.php:142-215`, C-2 at `:186-208`), C-4 is register-EXEMPT per `M0-evidence.md:65`; the contained-failure suite is non-vacuous (three real mechanisms, asserts zero entries + surviving stock + surviving seal, `PosCoreReceiptProjectionRefundDispositionStockTest.php:454-495`); R-1 option (a) is implemented with the required distinguishing test (`:154`); the legacy listener and its registration are deleted and `createCOGSEntry` is correctly left orphaned for T18; the migration is additive and unattended-safe; no float touches money/quantity in the diff.

## Register

**1 — P1 — CONFIRMED — `PosCoreReceiptProjection.php:1927`, `:2305`, `:2350-2355`; `InventoryGlPostingService.php:131`**
The cutover watermark is evaluated against the movement's **`occurred_at` (device event time)**, and a below-watermark result is written to `is_historical`, which `postMovement()` short-circuits. The authoritative plan specifies the watermark as `stock_movements.created_at >= :cutover_at` (`plan-wave3.md:1487-1488`) and explicitly rules the affected population **in** scope for COGS: *"`pending` / `dead_lettered` receipts replayed **after** the deploy — Project normally and **do** get COGS at their movement's snapshot cost"* (`plan-wave3.md:1500-1502`, ADDITION 3 row 2). The implementation does the opposite, and `CogsRelocationCharacterisationTest.php:352` codifies the contradiction as the expected behaviour.
*Failure scenario:* the projection queue holds 400 receipts at the deploy instant; every one is replayed 10 minutes later with `event_time_device` below the watermark → `is_historical = true` → zero `inventory_exit` entries. The same fires permanently for any device that was offline across the cutover instant, and for any device whose clock lags. Because D-a's predicate requires `is_historical = false` (`plan-wave3.md:1927`, `:2293`), **the detector can never report these** — this is precisely the "silent zero COGS is a worse failure than the deadlock this design removes" case the M2 brief names.

**2 — P2 — CONFIRMED — `PosCoreReceiptProjection.php:2305` (via `restockForLines` → `restockStock`)**
A refund/void projected after the cutover always books a full `inventory_entry` (Dr Inventory / Cr COGS), because `is_historical` is computed from the **refund's** device time, never from the original sale's position relative to the watermark. Pre-cutover POS sales posted no COGS at all (the retired invoice listener never saw POS receipts; `ReturnScrapWriteOffService`'s deleted comment stated it explicitly).
*Failure scenario:* customer buys on day −1, refunds on day +1. GL receives a one-sided Dr Inventory / Cr COGS at the sale-snapshot cost with no matching relief — inventory overstated, COGS negative, for the entire return window after go-live. This population is not in the plan's POS cutover table (`plan-wave3.md:1498-1504`); it is neither guarded nor disclosed.

**3 — P2 — CONFIRMED — absent (no such test in `apps/api/tests`)**
D-28 **Guard 4**, the register-independent structural leak test, is not implemented. The brief's M2 section names it as one of the four guards *"that make M2 correct"*, and R-2 states *"Guard 4 (structural leak test) is **NOT optional** — a test-only terminating hook asserting `$buffer->isEmpty()` at every request/job boundary. The only guard that does not depend on the register being right."* What exists instead: scenario-local `isEmpty()` assertions inside `InventoryGlPostingSeamTest.php:294-450`, and `InventoryGlPostingViaBufferOnly`, which only forbids direct `InventoryGlPostingService::postFor*` calls — it does not detect an unflushed buffer at a boundary.
*Failure scenario:* finding 4 below is exactly the leak Guard 4 exists to catch, and nothing in the suite catches it. The next unregistered root repeats the program's fifth occurrence undetected.

**4 — P3 — CONFIRMED — `InvoiceController.php:1003`**
C-5 (`createDeliveryAndPost`, the guided endpoint) calls `deliveryNoteService->confirm($deliveryNote)` inside its own root transaction (`:916`), so the DN's flush runs at depth 2 and defers; **no root tail flushes it**. Every use issues stock with zero COGS and fires `Log::critical` from the leak alarm. Scoped as M3 by the brief ("C-5's registration + flush tail") and the whole of 3C merges together, so this is not an M2 defect — but per V-10's own rationale C-5 is *the* mandated path for every standalone goods invoice post-T25b/c, so 3C must not merge without it, and M0's register (`M0-evidence.md:58`, `:73`) already pins the exact flush point.

**5 — P2 — CONFIRMED — `ReceiptReturnService.php:1310-1332`**
The interactive POS return path — the one T16d explicitly pulled into M2 ("T16d's scope now includes the interactive pair") — resolves the restore cost from **live** `products.cost_price` via `resolveMovementUnitCost()` (`:1317`), then enqueues a GL entry at that value (`:1343-1358`). The projection path uses the original sale movement's cost per R-1 option (a) (`PosCoreReceiptProjection.php:2317-2340`). Two live POS refund paths, two cost bases.
*Failure scenario:* sale at WAC 10 via POS, WAC drifts to 12, server-side (v3) return of the same unit → `inventory_entry` credits COGS 12 against a 10 relief. This is verbatim the residual R-1 was raised to eliminate (`brief:126-134`), and R-1's chosen option was never applied here. (Note: the *scrap* sub-case self-cancels because the write-off leg re-resolves the same live WAC; the plain restock case does not.)

**6 — P2 — CONFIRMED — `PosReturnScrapWriteOffTest.php:454-533`, `InventoryGlVoucherLockOrderTraceTest.php:125-180`**
The "green-after" arm for T11c pairs **7, 8, 9 and 10** is a single-connection `DB::listen` trace asserting `first_company_advisory > last_inventory_statement`. The `$pair` parameter is used only in assertion messages — pairs 7 and 8 execute byte-identical code, as do 9 and 10, and the named counterparties (DN confirm, POS sale) are never driven. There is no `40P01` count for any of the four. The brief's acceptance is *"T11c pairs: the `40P01` counts, red-before **and** green-after, per pair"*, and the 2026-08-11 ruling hardened *all ten pairs GREEN* into M2's gate. Four of the ten are two tests wearing four labels.
*Failure scenario:* a future change that makes pair 8's counterparty (a concurrent POS sale) acquire the company advisory before its stock loop reintroduces the AB-BA cycle; no test in the suite runs those two frames concurrently, so it lands green.

**7 — P3 — CONFIRMED — `PosCoreReceiptProjection.php:2350-2355`**
`isBeforeInventoryGlCutover()` issues `Company::query()->findOrFail($companyId)` per movement, i.e. once per receipt line, inside `apply()`'s transaction. A 40-line receipt adds 40 round trips and lengthens the lock window that T11c exists to keep short. Resolve once per `apply()`.

**8 — P3 — CONFIRMED — `PosCoreReceiptProjection.php:1837-1839`, `:2231-2233`, `:2371`**
`string $currencyCode = 'TND'` and `?CarbonInterface $entryDate = null` defaults on `decrementStock`/`restockStock`, with `$entryDate ?? now()` at enqueue. All three current call sites pass explicitly (`:1727`, `:2041`, `:2138`), so this is latent — but a POS company on EUR gains a silently TND-scaled GL entry the day a fourth caller appears. Make both required.

**9 — P3 — CONFIRMED — `ReceiptReturnService.php:1310-1315`**
`restoreStock` now does `Product::withTrashed()->where(tenant)->where(company)->findOrFail($productId)` where previously it loaded no product at all. A return whose product row is missing or company-mismatched now throws out of `runReturnTransaction` and fails the refund, rather than restocking. Low likelihood, but it is a new refusal path with no covering test.

**10 — P3 — CONFIRMED — `ReturnScrapWriteOffService.php:147`**
The zero-cost guard moved from `bccomp($unitCost, '0', $scaleResolver->getScale($currencyCode))` to `bccomp($resolvedUnitCost, '0', 6)`. Defensible (the column is COST_SCALE=6) and carries a `precision-ok` marker, but it silently changes the threshold for 3-decimal currencies. Not covered by a test.

**11 — P3 — CONFIRMED — `M2-evidence.md:23`**
The revert-replay evidence cites `git revert --no-commit 18b947774`, a SHA that exists only in the reflog (pre-amend form of `f848dab39`) and is unreachable from any ref. The tree is identical, so the evidence is real — but as written it is unreproducible by a reviewer. Restate against `f848dab39`.

**12 — P3 — NOTE — `scripts/wave3-citation-inventory.php` @ `90b8f57e7`**
The pre-cutover citation re-run modified the extractor in the same commit that produced `unresolved = 0` (a relocation entry for `ReturnNoteService.php:698-712` → `ReturnCostBasisResolver.php:35-61`, plus a boundary shift on an existing entry). The added entry carries a semantic anchor expectation, so it is not a bare suppression — recorded because "re-run produced 0" and "edited the tool that produces 0" landed together.

## Bypasses attempted that FAILED (i.e. the implementation survived)

- Non-shared buffer instances would break the scrap pair's cross-service `mark()`/`rollbackTo()` → **failed**: `$this->app->scoped()` at `InventoryServiceProvider.php:35`.
- `apply()` running nested (which would defer every POS flush and post nothing) → **failed**: `ApplyFiscalEventProjectionJob.php:392-394` calls the projector outside `T_apply`'s transaction.
- C-2 / C-4 composite roots uncovered → **failed**: C-2 driven end-to-end, C-4 register-exempt with a stated reason.
- Contained-flush leaving partial entries for a receipt → **failed**: `flushIfOutermost(contained: true)` posts the whole batch in one savepoint and the test asserts zero entries across both `inventory_entry` and `batch_write_off`.
- `is_historical` overload breaking an existing consumer query → **failed**: no `where('is_historical', …)` exists against `stock_movements` in `app/` today (it does, however, disable D-a — see finding 1).
- A surviving direct COGS writer in the cut-over lanes → **failed**: only `BatchWriteOffService.php:106`, a pre-existing out-of-scope lane.
- PHPStan/Pint/PG-by-path results were **not independently re-run** (no live DB env in this worktree); findings 1–6 are established by code reading and cited artifacts, not by test execution.

## Required to clear the gate

Findings **1** (P1) and **2, 3, 5, 6** (P2) must be closed or explicitly ruled on by the orchestrator. Finding 1 needs either the plan's `created_at` basis restored or an amendment overturning `plan-wave3.md:1500-1502` — it cannot be closed by test alone, since the test currently asserts the contradicting behaviour.

VERDICT: CHANGES-REQUIRED
