# WAC / Moving-Average Cost Under Concurrency — ERP Industry Best Practices

**Date:** 2026-05-29
**Purpose:** Ground the Inventory-Transfer v4 concurrency-model decision in how mature ERPs and
PostgreSQL actually solve moving-average-cost recomputation under concurrent stock operations.
**Method:** Deep-research fan-out (6 angles, 23 primary/secondary sources, 105 claims, 25
adversarially verified → 19 confirmed / 6 refuted). All load-bearing findings rest on primary
vendor docs (Microsoft Learn, Odoo, Frappe/ERPNext) or PostgreSQL official docs / ERPNext source.

---

## 1. The single most important finding: two regimes, not one

Mature ERPs split moving-average costing into two distinct regimes, and **conflating them is the
core mistake the v3 plan is at risk of making**:

1. **Ordinary live transactions** (receipt / issue / transfer) recompute the running average
   **synchronously, inline, at posting time**, using the **live on-hand quantity as the divisor**.
   Odoo AVCO: `Avg Cost = ((Old Qty × Old Avg) + (Incoming Qty × Price)) / Final Qty`, where
   `Final Qty` = on-hand after the move. D365 moving average is the same perpetual principle.
   *(verified 3-0; Odoo + Microsoft Learn)*
2. **Retroactive / backdated corrections** (a cost event lands behind already-posted later events)
   are pushed to a **deferred, queued, chronological-replay worker** — ERPNext's *Repost Item
   Valuation*, which replays Stock Ledger Entries from a point in time in strict
   `(posting_datetime, creation)` order. *(verified 3-0; Frappe docs + ERPNext `stock_ledger.py`)*

**Crucial nuance (explicitly refuted, vote 0-3):** ERPNext does **not** defer *all* WAC recompute
to a queue. Claims that "mature ERPs always recost asynchronously" were killed. Only the
*retroactive replay* is queued; the normal path is inline.

**Implication for us:** our cost-event ledger capitalizes each event *forward* against the
*current* `(on_hand + in_transit)` at the instant it's recorded. That is the **inline, forward-only
regime — not the retroactive-replay regime.** So we do **not** need ERPNext's heavy reposting
worker. We need correct serialization of the inline path.

---

## 2. The torn-denominator hazard is real and industry-acknowledged

Because the divisor is the live on-hand balance at posting time (finding 1), concurrent quantity
writers genuinely can shift it mid-calculation. This is not a theoretical worry we invented — it is
inherent to every single-running-average model (AVCO / standard / moving-average), which hold one
blended cost applied uniformly to all outgoing stock, as opposed to per-layer FIFO. *(verified 3-0;
Odoo + ERPNext)* Our append-only ledger is a **single-running-average model maintained via a
ledger** — so the hazard is the shared divisor, not layer-consumption ordering.

---

## 3. PostgreSQL primitives — what is and isn't safe (verified against official docs)

| Primitive | Verified guarantee | Applies to our options |
|---|---|---|
| `SELECT … FOR UPDATE` | Serializes writers **on a single row**; others' UPDATE/DELETE/FOR UPDATE block until txn ends. *(3-0)* | Safe **only** when WAC + its divisor live on **one row**. A **multi-row divisor** (summing `stock_levels` + `in_transit` lines in PHP — our option b) is **NOT** protected by single-row FOR UPDATE alone. |
| Consistent lock-acquisition ordering (sort the lock acquisition by a stable key) | **Primary** deadlock defense: if all txns lock the same rows in the same order, no deadlock. *(3-0)* | Validates sorting product locks by `product_id` for multi-product transfers. Caveat (from PG docs): consistent ordering is **hard to enforce across many independent writer code paths** — which is *why a single-writer/serialized model is attractive: it collapses all writers to one ordering.* |
| Automatic deadlock detection + **transaction retry** | PG aborts one txn on deadlock; recommended fallback when ordering can't be guaranteed in advance is to **retry**. *(3-0)* | Belt-and-suspenders for any locking option. |

> **Not researched / unverified:** Postgres **advisory locks** (our option c), optimistic
> version-column concurrency, and serializable isolation as applied to costing produced **no
> surviving verified claim** — the search didn't surface a primary source. Treat advisory-lock
> reasoning below as standard PG engineering knowledge, *not* something this research corpus
> verified.

---

## 4. The retroactive / late-arriving-cost fork: D365 vs ERPNext

The two dominant vendors diverge sharply, and the choice maps directly onto our late-cost feature
("unit cost during transport can change until it lands"):

- **D365 — forward-only, expense-the-difference.** *"You can't backdate an inventory adjustment to
  correct the moving average cost… Backdated transactions are assigned the current moving average
  cost, and the physical quantity is updated, but the moving average cost isn't affected."* When a
  purchase invoice differs from the receipt, the difference is **proportionally capitalized to
  current on-hand stock and the remainder expensed** to a "Price difference for moving average"
  account. **Sidesteps retroactive re-flow and its concurrency cost entirely.** *(verified 3-0;
  Microsoft Learn)*
- **ERPNext — retroactive re-flow.** Replays all subsequent SLEs chronologically. Accepts the
  concurrency cost and mitigates with **per-item serialization + off-peak scheduling** — and it
  *still* hits deadlocks: ERPNext ships a "Limit timeslot for Stock Reposting" setting whose stated
  purpose is *"to avoid deadlock issues which occur during the reposting."* *(verified 3-0; Frappe
  docs + corroborating GitHub issues #36825, #41493)*

**Takeaway:** even a per-item-serialized recosting queue is **not deadlock-free** and needs
temporal scheduling. The queued model (our option d) buys eventual consistency at real operational
cost; it's the right tool for *backdated* corrections, overkill for our *forward* path.

## 5. Landed / freight / transfer cost capitalization (late-arriving cost)

- **D365 two-phase + clearing accounts:** estimated landed cost posts at PO-invoice time; actual
  posts later as **reverse-estimated** through per-cost-type clearing accounts (duty/freight/
  insurance). The reversal nets against the actual → **idempotent, can't double-count.** *(3-0)*
- **Odoo single-phase:** landed cost is folded into the receipt "Purchase Price" valuation. *(3-0)*
- Odoo materializes each valuation-affecting move as an append-only **Stock Valuation Layer (SVL)**
  that generates the journal entry — **validating that our append-only cost-event ledger mirrors a
  production pattern.** *(3-0; caveat: Odoo 19 moved valuation onto stock moves; SVL-as-separate-
  record is ≤18.0.)*

---

## 6. Mapping to our four candidate options

| Option | Verdict in light of research | Why |
|---|---|---|
| **(a) Lock the Product row first in every writer** | **Theoretically sound, enforcement is the failure mode.** | Single-row FOR UPDATE on Product *is* verified-safe as a serialization token — **but only if every divisor-component writer takes it.** Both v3 reviews proved this invariant is currently false (public `StockMovementController` receive/adjust, `cancel()`, leaf `issue`/`receive` don't lock Product). PG docs themselves note consistent ordering across many code paths is hard — this is that problem. Making it true = cross-app retrofit (sales/returns/manual adjustments), colliding with the precision session. |
| **(b) Row-lock `stock_level` rows + in-transit lines, sum in PHP** | **Closes the common case; residual phantom hole.** | Verified: single-row FOR UPDATE does **not** cover a multi-row divisor, so you must lock **every** contributing row. Even then, a concurrently **INSERTed new** `stock_level` (via `getOrCreateStockLevel`) isn't covered by FOR-UPDATE-on-existing-rows → phantom. Rare for transfer costing (source already has rows) but not airtight. This is exactly the open question the research flags. |
| **(c) Product-keyed Postgres advisory lock** (`pg_advisory_xact_lock(hash(tenant,company,product_id))`) | **Best engineering fit — but un-verified by this corpus.** | A product-keyed advisory lock serializes **all** costing operations for product P **regardless of which table rows they touch**, auto-releases at txn end, has **no phantom-insert gap** (doesn't depend on rows existing), and doesn't deadlock with unrelated row locks. Acquire in sorted `product_id` order for multi-product transfers (verified deadlock defense). Touchpoints are one-liners (not query refactors) and can be centralized in `StockAdjustmentService`'s transaction wrapper + WAC. **Caveat:** advisory-lock guidance was *not* in the verified findings; this rests on standard PG knowledge. |
| **(d) Queued single-writer recosting worker** | **Right tool for backdated corrections; overkill/eventual-consistency for our forward path.** | This is the ERPNext model — and it's the *retroactive* layer only, scoped per-item, still deadlock-prone, scheduled off-peak. For a live POS/retail flow it would make `cost_price` eventually-consistent (stale until the worker runs). Keep in the back pocket for if/when we add true backdated cost corrections. |

---

## 7. Recommendation

**Adopt option (c): a per-product advisory-lock serialization token, forward-only capitalization,
keeping the append-only ledger.** Concretely for v4:

1. **One serialization token per product.** Replace v3's "every writer must remember to
   `Product::lockForUpdate()`" invariant with an explicit `pg_advisory_xact_lock` keyed on
   `(tenant_id, company_id, product_id)`, acquired at the top of (i) `WeightedAverageCostService`'s
   recompute and (ii) each `StockAdjustmentService` quantity mutator's transaction. Centralize it so
   there's exactly one helper, and gate **that helper** in the architecture test (not the leaf
   methods — fixing the test contradiction both reviews flagged).
2. **Forward-only, like D365/Odoo.** Our events already capitalize forward against current
   `(on_hand + in_transit)`. Do **not** add retroactive replay. Document that recorded order is the
   truth (industry doesn't promise algebraic order-independence — it promises a total order +
   serialized application). The v3 "WAC invariant under any event ordering" property is *stronger*
   than what D365/Odoo guarantee; it's fine to keep but it is not load-bearing for correctness once
   serialization is real.
3. **Sorted acquisition + retry.** For multi-product transfers, acquire advisory locks in ascending
   `product_id` order (verified primary deadlock defense). Wrap the service entry points in a
   deadlock-retry loop (verified standard fallback).
4. **Idempotent late cost.** Keep the append-only ledger as source of truth (Odoo SVL pattern);
   reversal events net against originals via the `reverses_event_id` partial-unique index (an
   idempotency mechanism analogous to D365's reverse-estimate-via-clearing-account). **Fix the
   fillable/DTO wiring both reviews flagged** so `reverses_event_id` + `idempotency_payload_hash`
   actually persist.

**If the team rejects advisory locks** (e.g. wants to stay within verified primitives only): the
fallback is **(a) done rigorously** — a single `lockProductForCosting()` choke point that every
divisor-component writer calls, accepting the cross-app retrofit scope. Option (b) alone is not
recommended (phantom hole). Option (d) is reserved for a future backdated-correction feature.

---

## 8. Open questions the research could not close

- SAP S/4HANA and Oracle NetSuite serialization primitives — no primary source survived
  verification; the "industry consensus" here rests on ERPNext, Odoo, D365.
- Advisory locks (option c), optimistic version columns, serializable isolation for costing — in
  scope of the question but **un-evidenced** by the corpus. The recommendation for (c) is
  engineering judgment, not a verified claim.
- The exact deterministic divisor + rounding rule at the moment in-transit converts to on-hand, and
  double-capitalization prevention if a cost event and the receipt event race — warrants a direct
  spec decision (the D365 clearing-account/reverse-estimate pattern is one verified answer).

---

## Key sources (all primary unless noted)

- Odoo AVCO valuation: https://www.odoo.com/documentation/18.0/applications/finance/accounting/get_started/avg_price_valuation.html
- Odoo inventory valuation config (AVCO/FIFO, SVL): https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/inventory_valuation/inventory_valuation_config.html
- D365 moving average (forward-only, expense-the-difference): https://learn.microsoft.com/en-us/dynamics365/supply-chain/cost-management/moving-average
- D365 landed cost (two-phase + clearing accounts): https://learn.microsoft.com/en-us/dynamics365/supply-chain/landed-cost/landed-cost-overview
- ERPNext Repost Item Valuation (deferred chronological replay): https://docs.frappe.io/erpnext/repost-item-valuation
- ERPNext reposting settings (timeslot limiter to avoid deadlocks): https://docs.frappe.io/erpnext/stock-reposting-settings
- ERPNext stock ledger source (sort by posting_datetime, creation): https://github.com/frappe/erpnext/blob/develop/erpnext/stock/doctype/stock_ledger_entry/stock_ledger_entry.py
- PostgreSQL explicit locking (FOR UPDATE, lock ordering, deadlock retry): https://www.postgresql.org/docs/current/explicit-locking.html
- PostgreSQL transaction isolation: https://www.postgresql.org/docs/current/transaction-iso.html
