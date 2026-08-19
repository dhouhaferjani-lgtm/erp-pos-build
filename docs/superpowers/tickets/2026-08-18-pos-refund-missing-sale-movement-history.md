# POS refund conflates a missing original sale movement with a post-cutover sale

**Severity:** MEDIUM-HIGH (inventory/COGS historical classification).
**Raised by:** wave3-3c M2 adversarial review round 7, P3-8.
**Disposition:** ship-with-ticket under
`ORCHESTRATOR-RULING-2026-08-18-m2-stop-a.md` ruling 5.

## Defect

`PosCoreReceiptProjection::originalPosSaleBasis()` returns
`is_historical => false` when the original sale movement is absent. That treats “no evidence” as
“known post-cutover”. A later refund can therefore create a live inventory-entry GL leg even though
there is no attributable original inventory-exit leg.

The neighboring branch for an existing movement with `unit_cost IS NULL` correctly derives history
from `stock_movements.created_at < companies.inventory_gl_cutover_at`; the absent-movement branch
has no timestamp from which to make that determination.

## Completion criteria

1. Define an owner-approved classification for the missing-movement case without guessing a sale
   timestamp.
2. Add pre/post-cutover tests that prove a refund cannot create a one-sided live entry when the sale
   movement is absent.
3. Preserve signed-event processing and the existing warning/diagnostic trail.
4. Keep movement cost and historical classification decisions explicit and independently testable.

This ticket is not resolved by the round-8 interactive-path movement lookup; that lookup has a
receipt-line fallback and does not decide the projection’s missing-movement history policy.
