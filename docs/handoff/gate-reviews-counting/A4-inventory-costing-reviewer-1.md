# A4 Inventory Costing Review — Round 1

- **Reviewer:** `inventory-costing-reviewer`
- **Model:** Opus
- **Scope:** Initial A4 backend and replay diff
- **Mode:** Read-only

## Verified

- The shared replay computation is arithmetic-identical to the former locked apply code; lock order and stock writes are unchanged.
- Preview and finalize share `FinalQuantityAsOfResolver`.
- The focused test proves stock levels, movements, and item attributes are unchanged by preview.
- Quantities remain decimal strings and the happy-path equivalence test passed.

## Findings

- **IMPORTANT:** The unpaginated reconciliation endpoint adds several queries per line and evaluates the opening gate twice. Batch stock, movement, basket-window, and first-count reads; reuse the computed opening gate (`CountingReplayPreviewService.php:41-50,53,81-92`, `CountingReconciliationPayloadBuilder.php:37`).
- **IMPORTANT:** Legacy-delta lines return no preview even though finalize posts them (`CountingReplayPreviewService.php:35-38`, `ApplyStockAdjustmentsOnCountingCompleted.php:87-93`).
- **IMPORTANT:** Post-finalize blocked lines display a derived adjustment even though their audit records a skipped post; annotate or suppress based on blocking reasons (`ReconciliationTable.tsx:487-499`).
- **IMPORTANT:** Preview duplicates the listener guard chain and has no blocked-branch parity tests. Extract a shared evaluator and test basket-window, negative-at-apply, and pending-opening-cost parity (`CountingReplayPreviewService.php:75-102`, `ApplyStockAdjustmentsOnCountingCompleted.php:216-272`).
- **MINOR:** Clarify that missing opening cost blocks the whole finalize; normalize DTO conventions/null handling; communicate that preview is a request-time snapshot.

## Verdict

**VERDICT: NEEDS REVISION**
