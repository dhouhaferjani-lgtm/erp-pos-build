# Selling Against Incoming / In-Transit Inventory — Accounting & Fiscal Research

**Date:** 2026-05-30
**Purpose:** Decide the "sell in-transit stock" fulfillment flow for the inventory-transfer feature (and its
generalization to supplier→warehouse incoming and e-commerce), grounded in ERP practice + accounting/fiscal rules.
**Method:** Deep-research fan-out (6 angles, 29 sources, 126 claims, 25 verified → 18 confirmed / 7 killed).
**Critical caveat:** the **fiscal/legal half failed to verify** — most fiscal claims abstained (0-0) due to
verifier-agent tool failures, not substantive refutation. So the fiscal layer below is **UNVERIFIED / OPEN** and must
be confirmed with a Tunisian accountant before building. The **operational/costing** findings verified strongly
(unanimous, primary vendor docs).

---

## 1. Two layers — keep them separate

- **Operational layer (verified):** does the ERP let you sell below on-hand, and how is cost handled?
- **Fiscal/legal layer (UNVERIFIED here):** when may you legally invoice, recognize revenue, and charge VAT for goods
  not yet delivered? Governed by IFRS 15 (transfer of control), VAT tax-point rules, e-invoicing/fiscalization, and —
  for us — Tunisia NACEF. **Needs an accountant; do not infer from this research.**

## 2. Overselling / negative inventory — supported, but it distorts cost (verified)

- Every major ERP (NetSuite "Use Cost Estimate for Negative Inventory"; ERPNext "Allow Negative Stock"; Dynamics)
  **supports** driving on-hand negative via a toggle — it is an operational choice, not forbidden.
  *(NetSuite + ERPNext primary docs; 3-0)*
- **The distortion:** when on-hand is negative, COGS is booked at an **estimated** cost (last purchase price / zero /
  average), because the true acquisition cost isn't known yet. *(NetSuite, Dynamics SL, ERPNext; 3-0)*
- **The reconciliation:** when stock is replenished (vendor bill / PO receipt / adjustment), the ERP posts a **linked
  correcting COGS entry** to fix the earlier estimate. *(3-0)*
- **The residual error if you don't reconcile:** balance quantity can reach 0 while balance **value** is non-zero —
  a corrupt valuation. ERPNext shows this concretely. *(3-0)*
- **Method-dependent:** FIFO + perpetual + negative is structurally incoherent ("no such thing as −5 apples valued at
  50"); only **Moving Average** tolerates negatives; Specific-Identification forbids them. *(3-0 / 2-1)*
- **Vendor-documented control:** the clean fix is **receive-before-dispatch sequencing** — enter the (backdated)
  purchase/transfer receipt *before* the outbound sale, so valuation is correct and no estimate/adjustment is needed.
  *(ERPNext; 3-0)*

**Implication for "what if it never arrives" (your risk):** under raw overselling, the sale already happened at an
*estimated* cost. If the incoming stock **never arrives**, the estimate is never trued-up → permanent valuation error,
*and* you've recorded a sale (possibly invoiced + charged VAT) for goods you cannot deliver → you must unwind it with a
credit note / refund and a shrinkage write-off. This is the messy path.

## 3. The disciplined alternative — Available-to-Promise + backorder (verified)

- **ATP** computes sellable quantity by **incorporating incoming supply** (future-dated POs/transfers), so you can
  promise against incoming stock **without driving on-hand negative**. NetSuite offers Discrete and Cumulative-with-
  Look-Ahead ATP; the calc consumes future-dated PO/SO/WO. *(NetSuite primary; 3-0)*
- **Backorder vs Preorder** (Dynamics 365): backorder = can't fulfill now due to lack of supply; preorder = ordered
  before product release. Distinction is by *trigger*, not severity. *(3-0)*
- **Deferred fulfillment is a supported pattern:** Dynamics can **take payment now** for a backordered item ("a promise
  of future delivery"), run a background inventory check, and advance the line to fulfillment when supply arrives, with
  a back-in-stock date surfaced. *(2-0; doc is preview-stage)*

**Why this handles your risk gracefully:** a backorder is a **promise**, not a completed delivery. If the incoming
stock never arrives, you **cancel/refund the backorder** before fulfillment — no negative inventory, no cost
distortion, no delivered-goods-you-don't-have, far smaller fiscal exposure.

## 4. Fiscal / legal layer — OPEN, needs an accountant (NOT verified here)

The research could not verify any of the following (claims abstained due to verifier tool failures). Treat as open
questions to put to a Tunisian accountant + the NACEF legal pack the project already plans:

- **IFRS 15 / revenue recognition:** revenue is recognized when *control transfers* to the customer — generally at
  delivery. Invoicing earlier doesn't by itself recognize revenue. *(unverified — confirm)*
- **VAT tax-point / time of supply:** when VAT becomes chargeable for goods invoiced/paid before delivery (advance-
  payment rules differ from delivery-based rules). *(unverified — confirm)*
- **Fiscal receipt for undelivered goods:** whether a homologated fiscal device may issue a *sale* ticket for goods not
  handed over, vs. a deposit/proforma. *(unverified — confirm)*
- **Tunisia NACEF (eff. 1 Jul 2026):** how the homologated MDF / e-invoicing treats tickets for undelivered goods; our
  hash-chain does not substitute for the MDF (see `project_tunisia_nacef_fiscal`). *(unverified — confirm)*
- **Goods-in-transit ownership / Incoterms / GRNI / loss-in-transit write-off:** when incoming stock is your asset, and
  how a loss reverses an already-recorded sale. *(unverified — confirm)*

## 5. Recommendation

**Model "sell against incoming" as ATP-visibility + backorder/pre-order — NOT raw negative-stock overselling.**

Rationale:
1. **Avoids the verified cost distortion** entirely (no negative on-hand → no estimated COGS → no reconciliation debt).
2. **Cleanly handles the "never arrives" risk** you raised: cancel/refund a promise vs. unwind a completed (and maybe
   fiscally-recorded) sale + write-off.
3. **Unifies your generalization:** branch-transfer-in-transit, supplier-PO-in-transit, and e-commerce backorder all
   become the same ATP/backorder model — incoming supply (from any source) raises available-to-promise; the order is a
   promise fulfilled when supply lands.
4. **Lower fiscal exposure** and a cleaner seam for the (still-open) fiscal questions — a backorder/deposit is a
   different and usually clearer fiscal event than invoicing delivered goods.

Concretely, the feature decomposes into:
- **A. Visibility (safe, build anytime):** show `on_hand (+incoming ↗)` per location, where incoming = in-transit
  quantity destined for that location (the same number already in the WAC `(on_hand + in_transit)` denominator). No
  accounting/fiscal impact.
- **B. Per-company setting** `allow_sell_against_incoming` (default OFF), manager-gated.
- **C. Backorder fulfillment flow** when B is ON and a sale exceeds on-hand: the line becomes a backorder/promise tied
  to the inbound supply; fulfilled (and the *delivery*-triggering fiscal document issued) when stock arrives;
  cancellable/refundable if it never does. **NOT** a negative-stock oversell.
- **D. Fiscal gate (blocking):** the exact document/VAT/revenue flow for B+C is confirmed with a Tunisian accountant and
  folded into the existing "Tunisia legal pack" checklist before B+C ship. Visibility (A) needs no gate.

**What to avoid:** enabling raw negative-stock overselling at POS as the mechanism — it imports the cost distortion and
the never-arrives unwind problem, and takes a harder fiscal posture (a *sale* of goods not delivered).

---

## 6. Open questions for the accountant / NACEF legal pack
1. May a fiscal receipt be issued for a backorder (goods not yet delivered), or only a deposit/proforma until delivery?
2. VAT tax-point: at order, at payment, or at delivery — for backordered goods in Tunisia?
3. Revenue recognition timing (IFRS 15 control transfer) for backorders.
4. If incoming never arrives after a customer paid: required document trail for refund/credit + inventory write-off.
5. Goods-in-transit ownership (Incoterms) for supplier→warehouse incoming — when is it our sellable asset?

## Key sources (verified findings only)
- NetSuite negative-inventory cost estimate: https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_1497451045.html , .../section_N2195087.html
- NetSuite ATP methods: https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2301636.html
- ERPNext negative-stock COGS distortion + control: https://docs.erpnext.com/docs/user/manual/en/stock-adjustment-cogs-with-negative-stock
- ERPNext maintainer on FIFO+negative incoherence: https://github.com/frappe/erpnext/issues/28990
- Dynamics GL effect of negative inventory: https://support.microsoft.com/en-us/topic/how-general-ledger-is-affected-by-negative-inventory-quantities-e577365e-f923-a0df-be05-e1ab4a2ba124
- Dynamics 365 backorder/preorder + take-payment-now: https://learn.microsoft.com/en-us/dynamics365/intelligent-order-management/backorder-preorder

> **Fiscal-layer sources were NOT verified** (IFRS/VAT/Tunisia links surfaced but claims abstained) — do not cite them
> as settled; route to an accountant.
