# A4 Frontend Conventions Review — Round 1

- **Reviewer:** `frontend-conventions-reviewer`
- **Model:** Opus
- **Scope:** Initial A4 frontend diff
- **Mode:** Read-only

## Findings

- **BLOCKER:** Legacy-delta lines have no replay boundary, so the preview is null and all three new columns render `-` even though finalize posts `final - theoretical` (`CountingReplayPreviewService.php:35-38`, `ApplyStockAdjustmentsOnCountingCompleted.php:87-93`, `ReconciliationTable.tsx:476-490`).
- **MAJOR:** The new quantities use storage scale instead of the product UoM display precision because the reconciliation product payload lacks `quantity_decimals` (`ReconciliationTable.tsx:478-491`, `types.ts:108-114`).
- **MAJOR:** `blocked_reason` is returned but not rendered, leaving the reviewer without the reason a line will not post (`types.ts:145`, `ReconciliationTable.tsx:499-506`).
- **MAJOR:** The legacy table path clips horizontal overflow after adding a fifteenth column (`ReconciliationTable.tsx:356`, `DataTable.tsx:121-128`).
- **MAJOR:** Rewriting the existing replay-column test removed coverage of the post-finalize `replay_audit` branch and its derived adjustment (`ReviewReplayColumns.test.tsx:110-131`, `ReconciliationTable.tsx:491-497`).
- **MINOR:** Remove silent zero fallbacks and asymmetric null probes; use a shared quantity scale for decimal comparison; show a positive sign on positive adjustments; add `scope="col"` to the new header; query the warning by accessible text; add the new keys to Arabic in A5.

## Verification note

The reviewer sandbox could not execute web commands. The implementation session independently ran typecheck and focused Vitest successfully before review; the next review round must re-run the requested web gates.

## Verdict

**VERDICT: NEEDS REVISION**
