# A4 Inventory Costing Review — Round 4

- **Reviewer:** `inventory-costing-reviewer`
- **Model:** Opus
- **Scope:** Final A4 backend diff and disputed unit-less fallback
- **Mode:** Read-only

## Re-evaluation

The reviewer withdrew the round-three claim about chained property access before `??`. PHP null-coalescing evaluates the full chain with `isset` semantics, so a null `unitOfMeasure` correctly falls back to `4`. The focused regression explicitly asserts `unit_id` is null, invokes the real payload builder, and receives `quantity_decimals = 4`.

## Verified

- Preview/apply arithmetic remains single-sourced under the stock row lock.
- Replay guards remain centralized across preview, listener, and locked apply.
- Preview is read-only and batched at the reconciliation caller.
- Unit precision remains string-safe end to end with no float coercion.

## Verdict

**VERDICT: APPROVED**
