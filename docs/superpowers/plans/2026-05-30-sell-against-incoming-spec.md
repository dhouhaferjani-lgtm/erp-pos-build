# Sell Against Incoming Inventory — Feature Spec

**Date:** 2026-05-30
**Status:** Design locked (owner, 2026-05-30). Part A build-ready; Parts B/C gated on the fiscal sign-off (Part D).
**Layers on:** `2026-05-29-wac-serialization-foundation.md` (the in-transit quantity it reads is the same `in_transit`
half of the WAC denominator). Independent of the transfer cost lifecycle (`...-remediation-v4.md` → v5).
**Grounded in:** `docs/superpowers/research/2026-05-30-selling-incoming-inventory-accounting-fiscal.md`.

---

## Problem

A multi-branch business moves product between shops/warehouses and buys from suppliers; stock is frequently *incoming*
(in-transit). Managers want to (a) **see** incoming stock per location — `on_hand (+incoming ↗)` — even when on-hand is
0, and (b) optionally **sell against it**. The risk the owner raised: incoming stock can be lost/damaged and **never
arrive**, so the sell-flow must degrade gracefully.

## Design decision (locked)

**Model this as Available-to-Promise (ATP) visibility + backorder/pre-order — NOT negative-stock overselling.**

Why (from research, verified ERP-mechanics layer):
- Raw negative-stock overselling books COGS at an **estimated** cost and only reconciles when stock arrives; if it
  **never arrives**, the valuation is permanently corrupt *and* you've recorded (maybe invoiced + VAT'd) a sale you
  can't deliver — a messy unwind. Verified across NetSuite/Dynamics/ERPNext.
- A **backorder is a promise**: if supply never arrives you cancel/refund before fulfillment — no negative inventory,
  no cost distortion, far smaller fiscal exposure.
- One model unifies all incoming sources: **branch-transfer-in-transit, supplier-PO-in-transit, and e-commerce
  backorder** all feed the same available-to-promise number.

Non-negotiables:
- **Never drive `stock_levels.quantity` negative** as the selling mechanism.
- **Forward-only** and consistent with the WAC foundation: incoming quantity is read, not mutated, by the availability
  calc.

---

## Domain model

- **incoming(location)** = Σ of inbound not-yet-received quantity destined for that location, across sources:
  - in-transit branch transfers: `stock_transfer_lines.quantity` where `transfer.status = InTransit` AND
    `transfer.destination_location_id = location` (already computed for the WAC denominator).
  - (generalization, later) open supplier POs / goods-in-transit destined for the location.
- **available_on_hand(location)** = `on_hand(location) − reserved(location)` (today's `getAvailableQuantity()`).
- **available_to_promise(location)** = `available_on_hand(location) + (allow_sell_against_incoming ? incoming(location) : 0)`.
- **Backorder line**: a sale line whose quantity exceeds `available_on_hand` (drawing on incoming). It is a *promise*
  linked to the inbound supply; it does not decrement physical stock until fulfillment. Fulfilled when supply arrives;
  cancellable/refundable if it does not.

---

## Part A — Incoming visibility (BUILD-READY, no accounting/fiscal impact)

Pure read + display. No stock or cost mutation. Safe to ship independent of the fiscal gate.

- **A1 — Backend availability read.** Add an `incoming` quantity to the per-location stock read used by stock-level
  list and product detail: `incoming(location)` as defined above. Expose `available_on_hand`, `incoming`, and
  `available_to_promise` (the last gated by the setting; when the setting is OFF, `incoming` is still shown for
  visibility but `available_to_promise == available_on_hand`).
- **A2 — Per-company setting** `allow_sell_against_incoming` (boolean, default **false**), on the company settings
  surface, manager-gated by an existing settings permission. When false: visibility only; selling still hard-blocks at
  `available_on_hand`.
- **A3 — UI.** Show `X (+Y ↗)` where `X = available_on_hand`, `Y = incoming` with an inbound arrow + tooltip ("Y units
  incoming from transfers/POs"). Render even when `X = 0` so staff see stock is coming. Use design tokens; i18n keys.
- **A4 — Tests.** Backend: `incoming` aggregates only `InTransit` transfers destined for the location, scoped by
  tenant+company. Frontend: badge renders `X (+Y ↗)` and `0 (+Y ↗)`.

> A is genuinely safe because it changes no quantities, no cost, no documents — it only surfaces a number we already
> compute for WAC.

## Part B — Setting wiring for sell-against-incoming (gated on D)

- `allow_sell_against_incoming` extends the **availability check** in the sell path from `available_on_hand` to
  `available_to_promise`. **Does not** itself change how the sale is fulfilled — that's Part C. Shipping B without C
  would only relax the check, so B and C ship together, behind the fiscal gate D.

## Part C — Backorder fulfillment flow (gated on D)

When B is ON and a sale line exceeds `available_on_hand`:
- The over-quantity portion becomes a **backorder line** (a promise), linked to the inbound supply that covers it.
- **No negative stock**: physical `stock_levels.quantity` is not driven below zero. The backorder is tracked
  separately (a `backorder`/`promised` quantity), and **fulfilled** (physical decrement + the delivery-triggering
  document) when the inbound supply is received.
- **Never-arrives path**: if the inbound supply is cancelled/lost, the backorder is **cancellable/refundable** before
  fulfillment, with the appropriate document trail (per D).
- Customer-facing: payment-now-fulfill-later is allowed (deposit/pre-order) per the fiscal flow D defines.

Part C's *mechanics* (how a backorder line is stored, fulfilled, cancelled) are designable now; its *document/VAT/
revenue* behavior is the fiscal gate D and MUST NOT be guessed.

## Part D — Fiscal gate (BLOCKING for B + C)

The fiscal/legal treatment did **not** verify in research (it abstained on tool failures — open, not disproven).
Confirm with a **Tunisian accountant** + the NACEF legal pack before B/C ship:

1. May a fiscal receipt be issued for a backorder (goods not yet delivered), or only a deposit/proforma until delivery?
2. VAT tax-point for backordered goods (order vs payment vs delivery) in Tunisia.
3. IFRS 15 revenue-recognition timing (control transfer) for backorders.
4. If a customer paid and the incoming never arrives: required document trail for refund/credit + any inventory effect.
5. Goods-in-transit ownership (Incoterms) for supplier→warehouse incoming — when is it our sellable asset?

These fold into the existing "Tunisia legal pack with accountant" go-live checklist item.

---

## Generalization (noted, not built here)

The same ATP/backorder model extends to:
- **Supplier→warehouse incoming** (open POs / goods-in-transit) — add PO-sourced `incoming` to the availability calc.
- **E-commerce** — backorder/pre-order on the storefront uses the same `available_to_promise` and backorder line.

Designing the incoming source as a polymorphic "inbound supply" (transfer | purchase-order | …) keeps A1's `incoming`
calc extensible. Build transfers first; add PO/e-commerce sources later.

---

## Sequencing

1. **Now:** Part A (visibility + setting) — build-ready, ship independent of fiscal gate.
2. **Gate:** Part D — accountant/NACEF sign-off on the 5 questions.
3. **After D:** Parts B + C (backorder flow) as a follow-up plan.
4. Layers on the WAC serialization foundation; independent of the transfer cost lifecycle (v5).

## Out of scope
- Negative-stock overselling as a selling mechanism (explicitly rejected).
- The fiscal document/VAT/revenue flow (Part D — accountant-owned).
- Supplier-PO and e-commerce incoming sources (generalization; transfers first).
