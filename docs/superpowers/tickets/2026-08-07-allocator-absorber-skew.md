# ProportionalMoneyAllocator absorber skew (P3, deliberate deferral from R2-P gate)

The allocator conserves exactly via a last-positive-base ABSORBER (not largest-remainder —
docblocks corrected in-lane). Consequence: per-part downward truncation concentrates up to
(n−1) currency sub-units on the absorber line's product — measured 0.009292 skew at n≤12;
a 30-line PO can skew ~0.029 onto one product's WAC/COGS. Conservation holds; attribution
is slightly lumpy. Upgrade path: true largest-remainder distribution (spread the remainder
across the largest fractional parts instead of one absorber). Also from the same gate:
- calculateAllocatedCost() preview (LandedCostService.php:383-398) still has the OLD
  truncate-then-multiply shape AND returns float — dead today (test-only callers); fix or
  delete before anyone wires it.
- Mixed-sign bases mis-attribute (negative base counted in subtotal but share forced 0) —
  conservation holds; add a guard or test if negative PO line_totals are reachable.
- bcmul at workingScale truncates the numerator pre-divide; workingScale*2 would be exact.
