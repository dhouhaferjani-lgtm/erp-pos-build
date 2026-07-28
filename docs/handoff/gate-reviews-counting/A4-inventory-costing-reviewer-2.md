# A4 Inventory Costing Review — Round 2

- **Reviewer:** `inventory-costing-reviewer`
- **Model:** Opus
- **Scope:** A4 after round-one fixes
- **Mode:** Read-only

## Verified fixed

- Preview stock/movement reads are batched and opening gates are reused.
- Legacy-delta lines expose the actual adjustment.
- Post-finalize blocked lines suppress the unposted adjustment.
- Preview, listener, and locked apply share one guard evaluator; basket-window, negative-at-apply, and pending-opening-cost parity tests cover the shared outcomes.
- Replay/apply math, precision, company scoping, and read-only behavior remain correct.

## Remaining finding

- **IMPORTANT:** The same review row still used UoM precision only for new replay cells, while theoretical/final/count cells used storage precision (`ReconciliationTable.tsx:174,508,535`).

Minor follow-ups were noted for the pre-existing per-line first-count read, soft-deleted product handling, future-skew basket windows, PostgreSQL-only aggregate verification, and snapshot-time communication.

## Verdict

**VERDICT: NEEDS REVISION**
