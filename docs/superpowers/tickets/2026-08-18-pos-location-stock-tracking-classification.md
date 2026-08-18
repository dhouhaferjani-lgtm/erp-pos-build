# Replace POS location-grain inference with an immutable stock-tracking property

**Severity:** MEDIUM — D-f classification hardening.
**Owner:** Inventory + Product architecture.
**Removal trigger:** before multi-location POS rollout treats an unseeded
product-level grain as an inventory anomaly rather than a non-stock-tracked
line.

The projected POS writer currently has no immutable product property that says
whether a physical catalogue item is stock-tracked at a location. For
product-level lines it therefore treats absence of the exact `stock_levels`
grain as the established non-stock-tracked outcome and persists
`stock_movement_expected = false`. A missing variant grain is not included in
that exception: it remains an anomaly, emits the variant-scoped warning, and is
reported by D-f.

Add an immutable stock-tracked snapshot to the sale source line (or an
equivalent durable product/location assignment), populate it at authoring, and
migrate D-f plus both POS stock writers to that property. The change must prove
that a stock-tracked item sold at a newly opened location remains reportable
before its grain is seeded, while genuine non-stock-tracked physical lines stay
silent. Do not infer the final property from movement existence, because a
missing movement is the condition D-f detects.
