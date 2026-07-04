# Procurement Wave 5 — Inventory/Costing Adversarial Review

Scope: uncommitted working-tree diff in `apps/erp.procurement-v2` (branch `feat/procurement-wave3`),
Wave 5 Tasks 12–16 of `2026-07-03-procurement-completeness-wave3-6-receipt-ledger-plan.md`
(matcher + posting on receipt-line basis). Waves 3+4 already committed — reviewed only new work.
Focus: costing/consumption correctness. Reviewer read every changed/added file.

## Verdict: NEEDS-REVISION  (spec ✅ / quality CHANGES-REQUESTED)

The costing math itself is CORRECT and well-guarded — no Critical defect. FIFO consumption walks the
LOCKED receipt-line collection (no TOCTOU), 408 clears per-slice at each line's immutable basis, WAC is
untouched, bcmath strings throughout (no float), the interim B3 guard is cleanly retired. The problem is
TEST QUALITY: the two headline behaviors of this wave (snapshot-honoring and lock serialization) are not
actually exercised, and a real free-window misclassification path has zero coverage. Fix the tests
(W5I-1, W5I-2, W5I-3) before merge.

---

## Findings

### W5I-1 [Important] Snapshot-vs-live basis is never distinguished by any test — Task 14's core requirement is unverified
`apps/api/tests/Feature/Procurement/SupplierInvoiceSnapshotTest.php:124-147`
The "no-reclassify" test stamps snapshot 5.200 (receipt RL1 100@5.200), then adds a LATER receipt
50@6.000 and asserts `match()` is still Matched. But for invoice qty=100 the FIFO-LIVE weighted basis is
ALSO 5.200 — the 6.000 receipt (higher created_at/id) is never inside the first-100 FIFO window
(`ReceiptLineConsumptionPlanner::plan` orders by created_at,id — `ReceiptLineConsumptionPlanner.php:28-32`).
So the assertion passes whether or not the snapshot is honored: it is a tautology. Same weakness in
`SupplierInvoiceMatcherReceiptBasisTest.php:107-124` and every `SupplierInvoiceReceiptClearingTest.php` case
— in all of them snapshot == live weighted, so `priceBasisForInvoiceLine`'s snapshot branch
(`SupplierInvoiceMatcher.php:524-527`) is never proven to differ from the planned-weighted branch.
Net: the entire point of the H5 snapshot (freeze basis so a later receipt cannot reclassify a matched draft)
is untested.
Fix: after stamping, MUTATE the consumed receipt line's `accrual_unit_cost` (or insert an EARLIER-FIFO
cheaper receipt) so the live weighted diverges from the frozen snapshot, then assert `match()` uses the
snapshot (e.g. snapshot 5.280 stays Matched while live 6.000 would be PriceVariance).

### W5I-2 [Important] Receipt-line matchableQty counts free_qty into the PAID ceiling, but 408 is accrued on paid qty only — over-paid billing misclassifies as Matched; zero free_qty coverage
`ReceiptLineConsumptionPlanner.php:62-71` defines `matchableQty = received_qty + free_qty − quantity_invoiced`,
and the matcher aggregates it as the PAID hard-qty ceiling (`SupplierInvoiceMatcher.php:159, 362-380`).
But 408 is credited on PAID `received_qty` only: `PostGrIrOnGoodsReceipt.php:37` fires with
`receivedQty = $qtyToReceive` from the paid branch (`GoodsReceiptService.php:313-336`); the free movement
fires NO `GoodsReceived` event. Consequence: a paid invoice line up to `received+free` passes the matcher's
Step-4 ceiling (`SupplierInvoiceMatcher.php:376`) as Matched instead of QuantityVariance. It is saved from
actually over-DEBITing 408 only by the downstream PO-line derived over-clear guard
(`SupplierInvoicePostingService.php:171`, which caps at paid `quantity_received`) — which then throws a raw
DomainException for an invoice the matcher green-lit. This is a precision regression of the qty guard
(pre-Wave-5 the ceiling was paid-only: `git diff` old `matchableQty` = `quantity_received − quantity_invoiced`).
It follows the plan's stated formula, but the formula conflicts with the free-vs-paid 408 accrual semantics.
Critically, NO Wave 5 test uses `free_qty > 0` (every `createReceiptLines` sets `'free_qty' => '0.0000'`), so
this interaction is entirely unverified.
Fix: add a `free_qty > 0` test (paid invoice attempting to bill into the free window) and either exclude
`free_qty` from the PAID matchable or route free consumption through the bonus counter so 408 nets exactly 0.

### W5I-3 [Important] Plan-contracted concurrency/lock test is missing, and the suite cannot verify FOR UPDATE at all (sqlite)
Plan Task 15 requires "concurrent posts on a shared receipt line serialize (lock test per the credit-note
D1 precedent)". `SupplierInvoiceReceiptClearingTest.php` has no such test. Furthermore the suite runs on
`sqlite :memory:` (`apps/api/phpunit.xml:41-42`), where `lockForUpdate()` is a no-op — so the new
`goods_receipt_lines ... lockForUpdate()->orderBy('id')` (`SupplierInvoicePostingService.php:82-87`) and the
`orderBy('id')` added to the PO-line lock (`:76`) are unverified by ANY test. Attack surface #6 answered:
there is no contention test; it would also not contend under sqlite.
Fix: add a pgsql-gated contention test (or document the limitation explicitly and assert the deterministic
ordering another way).

### W5I-4 [Minor] Test count corroborates the gaps; snapshot stamped on all lines incl. bonus
14 tests for Tasks 12–16 is thin, consistent with the three missing cases above. Also
`CreateSupplierInvoiceService.php:152` stamps `matchSnapshotAttributes` on EVERY invoice line including any
bonus line (harmless — matcher/rematch skip `is_bonus_line` for price — but noisy). No action required.

---

## Verified correct (no defect)

- Pure planner truly pure, no writes; FIFO order (created_at, id) stable; matchableQty at scale 4, bcmath
  strings; short plan on insufficient (creation/matcher tolerate; posting throws with caller guard)
  — `ReceiptLineConsumptionPlanner.php:17-71`.
- Posting FIFO walk operates on the LOCKED collection re-sorted by created_at,id
  (`SupplierInvoicePostingService.php:271-274`), reading locked models' `quantity_invoiced` — NO TOCTOU vs
  the pure unlocked planner.
- Per-receipt-line over-clear guard present (`:284-296`); PO-line derived guard retained as double-check,
  re-derived from `SUM(quantity_invoiced)` (`:156-158, 171-180`).
- `accruedHt = Σ slice_qty × per-slice basis` at working scale, one-round (`:145-153`). Multi-price
  60@5.200 + 40@5.400 → 528.000, net-408 EXACTLY 0.000, both lines fully invoiced
  (`SupplierInvoiceReceiptClearingTest.php:106-138`); partial 50 FIFO consumes only RL1 (`:140-169`);
  idempotent re-post no-op preserved (`:223-248`); zero-receipt fallback identical to legacy (`:201-221`).
- Interim B3 guard cleanly retired — no dead refs to `INTERIM_408` / `receiptAccrualBasesForPoLine`
  anywhere in app or tests.
- GL test edits STRENGTHEN, not weaken (retired throw-tests now assert real FIFO GL:
  `SupplierInvoiceGlTest.php` test 13 + multi-receipt-divergent).
- No float on money/qty (grep clean). `generated.d.ts` delta is transform-produced (PPV enum only).
- Matcher dual-threshold tolerance math untouched (`SupplierInvoiceMatcher.php:455-519`); bonus free-window
  logic (`:387-446`) preserved.

## What to fix before merge
Add tests that (1) make live basis diverge from the frozen snapshot to prove snapshot-honoring, (2) exercise
`free_qty > 0` against receipt-line consumption + 408, and (3) cover lock serialization (or document the
sqlite limitation) — then re-run.
