# ADR — Negative stock is hard-blocked (no overselling, no override)

**Status:** Accepted
**Date:** 2026-06-30
**Authors:** Claude Code (Product Bible discovery session) + Houssam (founder)
**Branch:** `chore/autonomous-dev-setup`

## Context

Synerivia ERP uses **weighted-average cost (WAC)** valuation, a two-tier
**append-only fiscal hash chain**, and **batch/lot/expiry (FEFO)** tracking.
The first launch vertical is **parapharmacy (Tunisia)**, where every sellable
SKU is batch/expiry-tracked.

The question: when a sale (POS receipt, delivery note, or stock issue) would
drive on-hand stock below zero, what should the system do?

Industry research across certified ERPs (SAP Business One, Microsoft Dynamics
365 Business Central, Odoo, NetSuite, ERPNext) found:

1. The convergent certified-ERP design is **block-by-default, allow-by-explicit
   per-product override**.
2. Negative stock **breaks WAC**: there is no cost layer to consume, so COGS
   must be booked at an *estimate* and then *back-valued* when the real receipt
   arrives (NetSuite "Use Cost Estimate for Negative Inventory", Odoo
   compensation layers, SAP B1 variance account, ERPNext zero-then-recompute).
   This deferred-recosting engine is significant, error-prone scope.
3. **Batch/lot/expiry-tracked items must never go negative** — there is no lot
   to attribute the sale to, which breaks both costing *and* recall
   traceability. ERPNext removed negative stock for serial/batch items in v15.
4. NF525 governs transaction unalterability and audit-trail integrity; a sale
   event referencing phantom, lot-less stock undermines the audit trail the
   system must defend.

## Decision

**Negative stock is hard-blocked everywhere. There is no overselling and no
override.**

- Any operation that would drive available quantity below zero is **rejected**
  at validation time (the existing `StockAdjustmentService::issue()` available-
  quantity check is the enforcement point; POS, delivery-note confirmation, and
  manual issues all route through availability validation).
- This applies **uniformly** — there is no per-product, per-category, or
  per-location "allow negative" flag, and no manager-override path. (This is
  *stricter* than the industry block-with-override norm, chosen deliberately.)
- Because negative stock can never occur, the system **does not implement** a
  deferred back-valuation / estimated-cost / recosting engine. WAC stays
  trivially correct: every issue consumes a real, already-costed layer.

### Offline POS interaction

No change required. The POS device performs **read-time availability** and does
**not** author the authoritative stock decrement; the server projection is the
single source of truth. The hard block is enforced server-side at projection
time, so an offline device cannot create a real negative-stock condition that
survives sync.

### Explicitly deferred / out of scope

- **Allowing negative sales / overselling** — deferred **indefinitely**, and may
  be **scratched permanently**. Not on the roadmap.
- **Back-valuation / deferred recosting engine** — **not built**, and not needed
  while the block holds. Reintroducing overselling later would require building
  this first (and re-opening this ADR).
- **Backorder** — a *possible* future, distinct feature: selling against a
  quantity that has been **backordered against an incoming PO**, with a clear
  user notice. This is NOT an override of the block (it sells against committed
  incoming stock, not phantom stock). Not committed; no design yet.

## Consequences

- **Costing model stays simple and always correct.** No estimated-cost layers,
  no compensation entries, no risk of WAC going zero/negative silently. This
  removes a large, bug-prone subsystem that every other certified ERP carries.
- **Fiscal/audit integrity preserved.** Every sale references real,
  lot-attributable stock — no phantom inventory in the hash chain, FEFO and
  recall traceability remain sound.
- **Trade-off accepted: the till can refuse a sale** when stock data lags
  physical reality (e.g. goods received but not yet keyed in). The mitigation is
  operational discipline (timely goods receipt) plus the future backorder
  feature, not a costing workaround. For the parapharmacy launch this is
  acceptable: regulated stock must be accurately tracked anyway.
- **Simpler agent guidance.** Domain agents never need to reason about
  negative-stock costing edge cases; "stock can't go negative" is an invariant.

## Alternatives rejected

| Alternative | Why rejected |
|-------------|--------------|
| Block-by-default **with per-product override** (SAP B1 / Dynamics / Odoo norm) | Requires the deferred back-valuation engine for the override path; added scope and fiscal risk for a capability the launch vertical (all batch-tracked) can't use anyway. |
| **Allow-on-POS / block-on-documents** | Same back-valuation requirement; pushes a costing-correctness liability downstream; conflicts with batch/lot traceability. |
| **Allow-all (global)** | High fiscal risk; breaks WAC and recall traceability; rejected outright. |

## References

- Negative-stock industry research brief (this session, 2026-06-30) — SAP B1,
  Dynamics 365 BC, Odoo, NetSuite, ERPNext, pharmacy-POS FEFO norms, NF525.
- Enforcement point: `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`
  (`issue()` availability validation).
- FEFO / batch tracking: `apps/api/app/Modules/BatchExpiry/`.
- Precision contract: `docs/architecture/precision-contract.md` (WAC, scales).
