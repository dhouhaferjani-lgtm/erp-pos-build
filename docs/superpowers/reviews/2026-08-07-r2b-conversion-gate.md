# GATE RECORD — R2-B (quote totals) — Document-conversion axis

**Round 1. Verdict: APPROVE-WITH-FIXES.**
**Branch:** `fix/r2b-quote-totals` @ `ca37ad51e` (2 commits on `264e6c483`). Reviewer axis: conversion lifecycle out of quotes.

The diff is correct, minimal, a faithful mirror of the sibling controllers, and introduces no regression on any reachable path. Merge is safe once I-1 is fixed in-diff. C-1 and C-2 are pre-existing defects on the same axis that the lane's finding-4 declared clear — that claim is falsified; they must be ticketed at merge, not silently inherited.

## Evidence base
- `phpunit tests/Feature/Document/{QuoteDiscountTotalsTest,ConversionChainVatIntegrityTest,DocumentConversionScenarioTest}.php` → OK (23 tests, 113 assertions), by path only.
- RED verification (controller reverted to base): **6 of 7 fail** — genuinely red pre-fix.
- PHPStan level 8 on the controller: no errors. Pint: pass.
- 4 independent scratch probes written, executed, deleted.
- **Worktree hygiene incident:** mid-review the worktree contained the controller staged in its reverted state plus two probe files from a parallel gate session. Restored from HEAD, byte-compared to the fixed version (MATCHES), suites re-run green. Orchestrator must re-verify `git status` clean before merging.

## Axis 1 — FULL CHAIN PARITY: CLEAR
Probe A (store → confirm → convert-to-order → order→invoice; 1×100.000 @10%, VAT 20%): quote/order/invoice all `180.000/36.000/216.000`, `line_total 180.000` — byte-stable end-to-end. Mechanism: `CopiesDocumentData::copyLine()` verbatim (`:142`) + `recalculateTotals()` sums the stored column (`:305`); `SalesOrderToInvoiceConverter` uses the same pair. Incidental: `POST /orders/{id}/confirm` 422s for a service-only line (stock reservation) — orthogonal to money.

## Axis 2 — MIXED-STATE HAZARD: per-path truth table (probe-verified)
Legacy pre-fix row (2×100.000 @10%, VAT 20%): `line_total 200.000`, header `200/40/240`; canonical `180/36/216`.
| Path on a pre-fix quote | Outcome | Wrong document? |
|---|---|---|
| left as draft | 200/40/240 | wrong but non-fiscal |
| → confirm | subtotal **200.000 unchanged**, tax 36.000, total 216.000 | **YES — newly incoherent** (200+36≠216); confirm writes only tax+total (`QuoteController.php:544-548`) |
| → lines-less PATCH → confirm | identical (probe C: lines-less PATCH leaves 200/40/240 intact) | YES |
| → PATCH with lines | fully repaired 180/36/216 | no |
| → confirm → convert-to-order | order **200/40/240** — fully re-inflated, diverges from its own source quote's confirmed 216.000 | **YES — propagates downstream** |
Confirm doesn't merely fail to repair: it converts a self-consistent-but-wrong draft into a self-INCONSISTENT confirmed quote, and conversion discards the repair (reads stale `line_total`, not `calculateTotal()`).

## Axis 3 — CONFIRM IDENTITY GUARD: non-vacuous for tax/total (fails `-70/+60` with fix reverted), **vacuous for subtotal** (`:340` — confirm never writes the column). See m-1.

## Axis 4 — SIBLING CONVERSION SOURCES
Quote-row writers (grepped `DocumentType::Quote` + all DocumentLine write sites): QuoteController store/update (fixed) · Workshop DocumentGenerationAdapter::generateQuote — **claim FALSIFIED, see C-1** · DraftPersistenceService — safe (zero discount handling; `bcmul` at `:250` can't be discount-blind) · CartConversionService — N/A (PO/SO only) · imports/duplication — none found.

## Axis 5 — SIBLING-PATH SWEEP independently re-derived
Implementer's table accurate for the quote route block. Two out-of-block mutation routes can target a quote — both money-safe: `POST /documents/auto-save` (DraftPersistenceService) and `POST /documents/{id}/revert` (status columns only). `additional-costs` routes never write document money columns; their `landedCostBreakdown` read-model uses FLOAT math on money (`DocumentAdditionalCostController.php:122-133`) — pre-existing rule-19 violation, flagged for the precision-drift lane.

## FINDINGS

### C-1 (critical, pre-existing, out-of-diff, PROBE-EXECUTED) — WO→quote persists a tax-INCLUSIVE line_total; every conversion out of that quote invoices VAT on VAT
`Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:199` — `'line_total' => $wol->line_total_incl_tax` (net column `line_total_excl_tax` exists, unused; semantics proven at `WorkOrderLineService.php:278-284`). `DocumentTotalsCalculator::recalculate()` derives the header from `calculateTotal()` — masking the bad column; `CopiesDocumentData::recalculateTotals()` (`:305`) reads the stored column — the mask does not survive conversion.
Probe D: WO 1×100.000 @10%, VAT 20% → WO-shaped quote header 90/18/108 with `line_total 108.000` → order 108/21.600/129.600 → invoice **129.600 for a 108.000 job (+20%)** on a fiscal hash-chained document. No `work_order_id` guard in `QuoteToSalesOrderConverter::getConversionErrors()` (`:65-95`). One-line fix + red test + legacy-row assessment → own ticket/lane.

### C-2 (critical, pre-existing, out-of-diff, code-read) — full-delivery FEFO batch split recomputes line_total GROSS
`SalesOrderToDeliveryNoteConverter::copyLinesForFullDelivery()` batch branch `:289-292` `bcmul(batchQty, unitPrice)` with no discount; `:303-306` keeps `discount_percent`, nulls `discount_amount`. The partial-delivery twin (`:378`, `:424-430`) applies the discount — the branches disagree. DN→invoice (`DeliveryNoteToInvoiceConverter.php:209` verbatim) re-inflates: discounted 216.000 order → 240.000 invoice still displaying 10% discount, so TaxCalculationService (net) and stored subtotal disagree on a posted fiscal document. Arithmetic verified; reachability high-confidence-but-unexecuted (needs batch-tracked FEFO fixture). Ticket required.

### I-1 (important, IN-DIFF, fix before merge) — chain test never reaches an invoice
`QuoteDiscountTotalsTest.php:365-405` stops at quote→order + forced status flip; no convert-to-invoice call, no invoice created — suite readers believe order→invoice is regression-guarded when it is not. Probe A shows the endpoint returns 201 for this fixture: append the real call and assert on the invoice (or rename).

### I-2 (important) — no backfill for legacy discount-blind quotes; confirm() leaves them internally inconsistent
Per the Axis-2 truth table: (a) confirmed legacy quote where subtotal+tax≠total; (b) converted order fully re-inflated and divergent from its source. Pre-existing, not worsened, but the lane now owns the knowledge. Needs a backfill migration OR an explicit written owner ruling (accountant-disposition family). Silence not acceptable.

### m-1 (minor, IN-DIFF) — subtotal assertion at `:340` structurally vacuous (confirm never writes it). Comment as never-written invariant or assert non-write explicitly.
### m-2 (minor) — shared worktree not exclusively owned during review (merge-mechanics note).

## Verified CORRECT (no findings)
`lineDiscount()` byte-equivalent to siblings · all three call sites route through the canonical `computeLineTotal` (`DocumentLine.php:280-301`) · store/update now agree with `TaxCalculationService::calculateSubtotal` and `DocumentTotalsCalculator::recalculate` · boundary guards real (`CreateDocumentRequest.php:132` percent min/max, `:133-141` LineDiscountAmountWithinGross; same in Update) · header-level discount_amount not settable on quote create/update.
