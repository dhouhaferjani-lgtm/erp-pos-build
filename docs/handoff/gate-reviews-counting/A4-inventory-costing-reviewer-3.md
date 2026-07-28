# A4 Inventory Costing Review — Round 3

- **Reviewer:** `inventory-costing-reviewer`
- **Model:** Opus
- **Scope:** A4 after full-row UoM precision fix
- **Mode:** Read-only

## Verified

The split-precision finding is fully fixed. Replay equivalence, batching, legacy-delta preview, guard parity, read-only behavior, and precision remained intact.

## Finding challenged with runtime evidence

The reviewer claimed `$item->product->unitOfMeasure->decimal_places ?? 4` would throw for a unit-less product. The focused test fixture already creates a product with `unit_id = null`, invokes this exact payload path, and passes on PHP 8.4; chained access on the left side of `??` uses null-coalescing/`isset` semantics. An explicit assertion was added before re-review.

## Verdict

**VERDICT: NEEDS REVISION** pending re-evaluation of the contradicted unit-less-product claim.
