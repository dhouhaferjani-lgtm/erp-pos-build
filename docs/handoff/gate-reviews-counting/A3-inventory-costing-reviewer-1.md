# A3 Inventory Costing Review — Round 1

- **Reviewer:** `inventory-costing-reviewer`
- **Model:** Opus
- **Scope:** Initial uncommitted A3 diff
- **Mode:** Read-only

## Required Findings

- **Critical:** residual detection started at `counting.finalized_at`, but the correction is applied asynchronously. A pre-count sale arriving between finalize and apply is absorbed by the correction and is not a double subtraction. Start at the successful per-item apply marker (`replay_audit.windowTo`).
- **Important:** exclude flagged items where no correction was posted.
- **Important:** permission- and device-bind the health-report endpoint.
- **Important:** verify the acknowledgement against the health snapshot server-side and persist the acknowledgement, actor, and snapshot in the finalized counting audit event.
- **Important:** include the exact count boundary (`<= final_qty_as_of`), document legacy rows without a boundary, and keep the feature explicitly sale-only.
- **Important:** remove the direct Inventory-to-POS domain dependency through a shared contract.
- **Important:** format residual quantities with product unit precision.
- **Important:** constrain the company-wide candidate query to the inspected counting's stock grains and explicitly scope movements by company.

## Verified Clean

- Frozen fiscal projection/parser/generated surfaces were untouched.
- The original latest-qualifying-count attribution rule was correct.
- Detector was read-only and quantity arithmetic remained decimal-string/bcmath based.
- Client-side acknowledgement correctly re-armed when its observed risk changed.

## Verdict

**VERDICT: CHANGES_REQUIRED**
