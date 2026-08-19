## M2 adversarial merge-gate review — round 3

**Milestone:** M2 — THE CUTOVER COMMIT (T14+T15+T16+T16b+T16d+T16e+T17)
**Reviewed:** `git diff 26b63f0ff..HEAD`; M2 = `f848dab39` + fix commit `28a2d854b` (round-1 closure), preceded by `90b8f57e7` (citation re-run). Round 2 was a tool error (`M2-round2.md`).
**Amending authority:** none.
**Lenses:** inventory-costing — applies. fiscal-pos — applies (T16e/T16d byte identity, sealed-bytes surface). tenancy-authz / treasury — not named, not applied beyond standing checks.

### What holds up (re-verified this round, not carried over)

T16e is genuinely proven, not asserted: the reorder is real (`PosCoreReceiptProjection.php:470-484` — `applyStockMovementForLines` now precedes `redeemVouchers`), and `InventoryGlVoucherLockOrderTraceTest.php:132-200` drives the **production** `apply()` frame, asserts `first_company_advisory > last_inventory_statement` over the whole trace (which also discharges I-1 for `writePayments`/`earnLoyaltyPoints`, not just the voucher), and pins `voucher_ledger.amount=-10.00000`, currency, balance, terminal/user, GL `source_type/source_id`, `entry_date` and balanced totals. Round-1 finding 1 is genuinely closed (`is_historical=false` unconditionally on the sale leg, `:1942-1944`; covering test at `CogsRelocationCharacterisationTest.php:352`). Round-1 finding 3 is closed with a real, register-independent Guard 4 (`InventoryGlPostingBoundaryGuard.php`, registered at `InventoryServiceProvider.php:120-135`) — I confirmed by execution that it detects leaks. Round-1 findings 7, 11 are closed. The unique-index migration is fail-closed and driver-guarded. Idempotency re-checks inside and outside the inner transaction are correct in `createInventoryMovementEntry`.

---

## Register

**1 — P1 — CONFIRMED (executed) — `InventoryServiceProvider.php:127`; `InventoryGlPostingBuffer.php:59`**
The branch ships a **red regression surface in suite directories the diff touches**. Under `RefreshDatabase` (the house default) the test's outer transaction makes every controller root frame `transactionLevel() === 2`, so `flushIfOutermost()` always takes the `$level > 1` defer branch; the new test-only boundary guard then throws at `RequestHandled`. Only the wave's own tests opt out via `connectionsToTransact(): []` (6 files); every pre-existing feature test that issues/receives stock over HTTP now fails.
I ran these, on this HEAD:
```
tests/Feature/Document/StandaloneInvoiceGuidedDeliveryTest.php   8 failed,  4 passed  (all 8 = guard)
tests/Feature/Document/InvoiceDeliveryNoteConfirmationTest.php   4 failed,  4 passed  (guard)
tests/Feature/POS/ReceiptReturnFlowTest.php                      1 failed, 19 passed  (guard)
```
All are `LogicException: Inventory GL posting buffer leaked at request boundary` — a class that does not exist on the base, so none of these are pre-existing. `InvoiceDeliveryNoteConfirmationTest` (+21) and `StandaloneInvoiceGuidedDeliveryTest` (+70) are files **this wave edited**. The M2 evidence's "broad document run passed 71 tests" did not cover them.
*Failure scenario:* CI (`tests/Feature/Document`, `tests/Feature/POS`) goes red on merge; the house rule "the declared regression set must include every suite directory the diff touches" is violated in exactly the way it was written to prevent.

**2 — P1 — CONFIRMED (executed) — `InvoiceController.php:1003`**
C-5 (`createDeliveryAndPost`) calls `deliveryNoteService->confirm($deliveryNote)` inside its own root transaction (`:916`) with **no root tail flush** — the nested confirm defers and nothing posts. This is not only a test artifact: in production the endpoint issues stock, posts the invoice, and emits `Log::critical` with **zero COGS**. Per V-10's own rationale this is THE path for every standalone goods invoice under `require_delivery_first`. The brief scopes "C-5's registration + flush tail" to M3, but M2 is the commit that breaks it, and the guard now proves it empirically (`test_the_guided_flow_creates_confirms_links_and_posts`). This is the "silent zero COGS is a worse failure than the deadlock this design removes" case the M2 section names.

**3 — P1 — CONFIRMED — `ReceiptReturnService.php:1523-1533`, `:1536-1553`; `PosCoreReceiptProjection.php:1212-1231`**
The round-1 finding 5 fix (R-1 basis on the interactive POS return path) is **vacuous in production**. `receiptLineUnitCost()` reads `pos_receipt_lines.unit_cost`, but the fiscal projection's `writeLines()` never writes that column — and the only writer that does, `ReceiptCreationService::createReceipt` (`:342`), is **retired / 410 Gone** (`PosCoreReceiptProjection.php:1704-1706`). So for every receipt the interactive path can ever return, `receiptLineUnitCost()` returns `null` and `resolveReturnUnitCost()` falls through to live `product->resolveMovementUnitCost()`. The covering test is green only because the fixture injects a value production never writes (`PosReturnScrapWriteOffTest.php:423-427`, `'unit_cost' => '2.5000'` + `cost_price => 12.000000`).
*Failure scenario:* sale at WAC 10 through the device, WAC drifts to 12, v3 server-side return of the same unit → `inventory_entry` credits COGS 12 against a 10 relief. Verbatim the residual R-1 exists to eliminate; the brief says "do not merge T16 with the question open."

**4 — P1 — CONFIRMED — `PosCoreReceiptProjection.php:2367-2374`**
Round-1 finding 2 is closed only on the happy branch. `originalPosSaleBasis()` returns `['unit_cost' => null, 'is_historical' => false]` whenever the original sale movement is missing **or carries a NULL `unit_cost`** — conflating "unknown cost" with "post-cutover". The `gl_is_historical` value is already computed and available in that branch and is thrown away. Critically, `unit_cost` on POS sale movements is a **this-wave** addition: `git show dev:…/PosCoreReceiptProjection.php` contains zero occurrences of `unit_cost`, and `stock_movements.unit_cost` is nullable. Every POS sale movement that exists at the deploy instant therefore has `unit_cost IS NULL`, so this fallback is the **dominant** path for pre-cutover refunds, not an edge case.
*Failure scenario:* customer buys before go-live, refunds after → `is_historical=false` → a one-sided Dr Inventory / Cr COGS at **live** cost, with no matching pre-cutover relief. Inventory overstated, COGS negative, for the whole return window. `postMovement()`'s `isHistorical` short-circuit (`InventoryGlPostingService.php:131`) is the only guard and it is being told the wrong thing.

**5 — P3 — CONFIRMED — `ReceiptReturnService.php:1338`; `phpstan-baseline.neon:789-793`; `phpstan.neon:11`**
Rule-19 guard bypass. The new `bcmul($quantity, $resolvedUnitCost, 6)` carries no `// precision-ok:` marker (unlike its sibling at `ReturnScrapWriteOffService.php:137`). It passes only because a stale baseline slot for this file (`bcmul`, count 1) absorbs it and `reportUnmatchedIgnoredErrors: false` makes stale slots silent. I ran `./vendor/bin/phpstan analyse app/Modules/POS/Application/Services/ReceiptReturnService.php` → `[OK] No errors`, confirming the guard did not fire on newly introduced code. The literal 6 is substantively correct (COST_SCALE); the defect is that the standing check was not actually enforced.

**6 — P3 — CONFIRMED — `InventoryGlLockOrderContentionTest.php:131-158`, `:29-42`**
"All ten T11c pairs green" overstates the evidence. `runTerminalOrder($pair)` uses `$pair` **only** to salt the advisory key; the SQL sequence is byte-identical for all ten, i.e. one synthetic model run ten times under ten labels. The load-bearing evidence is the per-frame production traces (pairs 1/2/3 in `CogsRelocationCharacterisationTest.php:211-241`, 7/8 in `PosReturnScrapWriteOffTest.php`, 9/10 in `InventoryGlVoucherLockOrderTraceTest.php`), which do cover all four real frames — so the invariant is defensible, but the M2 evidence's phrasing should be corrected to say so rather than claiming ten distinct pair proofs.

**7 — P3 — CONFIRMED — `ReceiptReturnService.php:1296-1299`**
The `string $currencyCode = 'TND'` / `?CarbonInterface $entryDate = null` defaults that round-1 finding 8 got removed from the projection are **reintroduced** here on `restoreStock()`. Both current call sites pass explicitly, so it is latent — a EUR company gains a silently TND-scaled GL entry the day a third caller appears.

**8 — P3 — CONFIRMED — `ReceiptReturnService.php:1546-1551`**
`resolveReturnUnitCost()`'s `Product::withTrashed()->where(tenant)->where(company)->findOrFail()` is now on the **always-taken** path (per finding 3), not a rare fallback. A return whose product row is hard-deleted or company-mismatched now throws out of `runReturnTransaction` and refuses the customer's refund, where previously `restoreStock` loaded no product at all. No covering test.

**9 — P3 — NOTE — `…_journal_entries_source_inventory_movement.php:26-35`**
The migration throws on any pre-existing duplicate `(source_type, source_id)`, and `InventoryGlSourceTypes::ALL` includes the **pre-existing** `batch_write_off` / `batch_write_off_reversal` types whose writer had no idempotency guard before this commit (the guard is added in the same diff, `GeneralLedgerService.php:4828-4840`). Under `origin/dev` auto-deploy + `tenants:migrate`, one duplicate on one tenant fails the deploy. M0 explicitly labelled R-11's local probe vacuous and deferred deploy-target execution to T19/M3 — carry that forward as a hard cutover precondition, and add the `batch_write_off` duplicate count to it.

**10 — P3 — NOTE — `f848dab39` + `28a2d854b`**
M2's "ONE commit" gate is now satisfied only by squash intent. Acceptable for a gate fix round; flagging so the merge does not land two separately-deployable states.

---

## Bypasses attempted that FAILED (the implementation survived)

- **Buffer instance identity across services** (would break the scrap pair's cross-service `mark()`/`rollbackTo()`) → failed: `$this->app->scoped(...)`, `InventoryServiceProvider.php:35`.
- **A GL collaborator reaching the company advisory before the POS frame's last inventory lock** (loyalty, payments, VAT breakdown) → failed: the production-trace assertion in `InventoryGlVoucherLockOrderTraceTest.php:157-180` is over the whole `apply()` frame and would catch any of them.
- **T16e changing voucher bytes** → failed: `voucher_ledger` amount/currency/terminal/user/balance and GL `source_type`/`source_id`/`entry_date`/balanced totals all pinned; the change is a statement move only.
- **Unbalanced or double-rounded inventory entries** → failed: `InventoryGlPostingService::amount()` rounds once at `scale+6` → `scale` with an explicitly injected currency, and both journal lines reuse the same rounded string.
- **Non-positive / periodic-mode / unmapped-accounts paths posting silently** → failed: all three log and return null before writing.
- **`inventory_gl_cutover_at` migration being non-additive or clock-dependent** → failed: `hasColumn` guard, `useCurrent()` default, idempotent backfill.
- **`getScale()` called bare in a queued/projection context** → failed: every reachable call passes `$ctx->currencyCode`.
- **Sealed bytes** → failed: nothing in the diff touches `fiscal_events`, `chain_sequence` computation, or hash inputs; `return_cost_basis[]` writes to `payload`, which the invoice hash excludes.
- **GR path regression from the guard** → failed: `tests/Feature/Inventory/GoodsReceiptTest.php` = 25 passed.

## Required to clear the gate

Findings **1, 2, 3, 4** must be closed. Finding 1 needs the pre-existing feature suites made green (either the guard scoped to tests that opt into root-commit semantics, or those suites migrated) **and** a re-declared regression set covering `tests/Feature/Document` and `tests/Feature/POS` in full. Finding 2 needs C-5's tail — it cannot be deferred past the commit that makes the path silently zero-COGS. Findings 3 and 4 cannot be closed by test alone: finding 3's current test is green-by-fixture, and finding 4's fallback must carry the computed `gl_is_historical` rather than defaulting to `false`.

VERDICT: CHANGES-REQUIRED
