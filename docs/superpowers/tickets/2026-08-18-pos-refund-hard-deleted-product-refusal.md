# Interactive POS refund can be refused when its historical product row is unavailable

**Severity:** MEDIUM (customer refund availability and legacy compatibility).
**Raised by:** wave3-3c M2 adversarial review round 7, P3-9; behavior predates this wave.
**Disposition:** ship-with-ticket under
`ORCHESTRATOR-RULING-2026-08-18-m2-stop-a.md` ruling 5.

## Defect

When neither the original sale movement nor the receipt-line snapshot supplies a unit cost,
`ReceiptReturnService::resolveReturnUnitCost()` falls back to
`Product::withTrashed()->where(tenant)->where(company)->findOrFail()`. A hard-deleted product, or a
legacy row no longer matching the company scope, throws out of the return transaction and refuses
the customer’s refund.

Soft deletion is covered by `withTrashed`; hard deletion and historical ownership anomalies are
not. Cost recovery and refund authorization should not be conflated.

## Completion criteria

1. Add a regression for a valid historical receipt whose product row is hard-deleted.
2. Decide the auditable cost fallback for that population (for example a retained immutable line or
   movement snapshot), with no live-WAC guess presented as historical fact.
3. Ensure the refund’s fiscal/payment outcome and any stock outcome are explicit when cost evidence
   is unavailable.
4. Preserve tenant and company isolation; do not relax the scoped product query as a workaround.
