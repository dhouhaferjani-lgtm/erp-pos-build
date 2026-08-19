# Give D-f an immutable physical-goods snapshot

**Severity:** LOW — R-6 detector-history hardening.
**Owner:** Inventory + Product architecture.

D-f detects a missing stock movement, so unlike D-a/D-b/D-e it has no movement
row whose immutable attributes can classify the source line. Its delivery,
POS, and goods-receipt arms therefore use the current tenant/company-scoped
`products.is_physical` flag. The cutover watermark bounds the exposure, but a
later catalogue edit can still change whether an already-created source line
belongs to the detector population.

Add an immutable, source-line physical/stock-tracked snapshot at each writer,
then migrate all three D-f arms to that snapshot together. Preserve the scoped
`PhysicalLinePredicate` parity test and prove a later product edit does not
retroactively add or remove a detector finding. Do not infer the snapshot from
the presence of a movement: absence is the condition D-f exists to report.
