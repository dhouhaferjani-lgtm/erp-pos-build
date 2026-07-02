# Purchase Bonus Quantities ("Unités Gratuites") — Design Spec

> **Status:** DESIGN — Revision 2 (post Codex adversarial review). Not implemented. No code, no migrations, no commits.
> **Date:** 2026-07-02 (Rev 1) · Revision 2 folds in the `2026-07-02-bonus-quantity-codex-review.md` REVISE disposition.
> **Verticals:** Parapharmacy (Tunisia) first; opt-in for other verticals/countries.
> **Scope:** Purchasing side only (PO → goods receipt → supplier invoice → supplier credit note → GL/WAC). No sales-side free goods, no rebate engine.

> **Revision 2 headline:** the single biggest change is that supplier-invoice ingestion is now specified as a **dual invoice-shape contract** (§9a) — real Tunisian supplier invoices legally carry designation/HT/VAT-rate/VAT-amount per line and commonly express free units as an explicit 100%-discount / *remise en nature* line **on** the invoice. Rev 1 assumed "invoice bills paid qty only", which hard-fails `SupplierInvoiceMatcher`. Rev 2 accepts BOTH shapes and defines exactly how each maps onto the paid/free counters while keeping the matcher green. Full finding-by-finding disposition in the last section.

---

## 1. Business requirement (normalized)

Suppliers grant tiered free goods on purchases — e.g. buy 20 get 1 free, buy 50 get 3, buy 100 get 10. This is standard practice for parapharmacies in Tunisia and exists in other countries/verticals.

Entry model on a purchase-order line:

1. **Paid quantity** — the units the supplier will invoice.
2. **Free quantity** — optional, may be empty (0), the "gratuité" units shipped at no charge.
3. **Price** — the user enters **either** the per-unit price **or** the total amount paid for the line; the system derives the other.

Costing rule: **weighted average cost is computed over the ENTIRE received quantity (paid + free)** — effective unit cost = total paid / (paid + free). Example: 20 paid + 1 free at 5.000 TND/unit → 100.000 TND for 21 units → effective cost ≈ 4.762 TND.

Accounting, tax, 3-way match, and reporting must stay clean. The feature is **activated per vertical and/or per country** (parapharmacy Tunisia first) following the existing two-layer gating pattern.

---

## 2. Core design decision — the "zero-value plane split"

**Scope decision (P0-2, decisive): v1 supports SAME-PRODUCT bonuses only** (buy 20 of X, get N of X). Cross-product bonuses (buy 20 of X, get 2 of Y) are **explicitly Phase 2** — justified and sketched in §2a. This keeps the v1 representation a single additive column on one line.

**Chosen representation: ONE document line carrying `quantity` (= PAID units, unchanged semantics) plus an additive `free_quantity` column. Free units never enter any value-bearing counter or amount. At goods receipt, free units are recorded as a companion `recordPurchase` movement at unit cost `'0'` — WAC dilution emerges from the existing blend math, and downstream value systems (VAT, landed cost, GR-IR, AP) are untouched because free units carry exactly zero value.**

The governing invariant:

> **Value flows only through paid quantities. Free quantities flow only through inventory, at zero incremental value, diluting WAC via the existing blend.**

**Honest matcher scope (revised from Rev 1's "zero matcher changes"):** the 3-way matcher is untouched for the **paid-only invoice shape (a)**; the **free-line invoice shape (b)** requires ONE bounded, additive matcher change — a `is_bonus_line` skip in `buildQtyGroupStatuses` (§9a). Rev 1's blanket "zero changes to the matcher" claim was false against `SupplierInvoiceMatcher.php:263-345`, where a linked free line inflates the per-PO-line aggregate (`21 > matchable 20 → QuantityVariance`) and an unlinked free line trips the null-source-line Exception (`:182-190`). §9a specifies the exact contract.

### Alternatives rejected

| Alternative | Why rejected |
|---|---|
| **(a) `quantity` = paid+free total, `unit_price` = diluted effective cost** | Breaks the `unit_price` contract (net stated supplier price — see `docs/architecture/precision-contract.md` §unit_price semantics), breaks the price check in the 3-way match (supplier invoices the *stated* price, not the diluted one), and injects a rounded division (`100/21`) into `line_total`, so `line_total ≠ total paid` exactly. |
| **(b) Zero-price document sub-line** | Pollutes the document layer: a second line per bonus appears in totals/tax/print/matching; the matcher (`SupplierInvoiceMatcher::matchableQty`, `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:141-147`) would expose a permanently un-invoiceable line (received > 0, invoiced forever 0 → stuck "partial"); GR-IR would accrue 0 for it but the line still counts in `quantity_received`-driven UIs. Two lines also break the "one commercial line" the supplier prints. |
| **(c) `quantity_received` counts paid+free physical units, match on "paid-equivalent"** | Forces a units conversion (`invoiced_paid × (paid+free)/paid`) inside `SupplierInvoicePostingService` clearing math and the matcher — a proportional-fraction scheme with rounding residue on 408 and a modified over-clear guard (`SupplierInvoicePostingService.php:162-171`). Larger blast radius for zero benefit. |

### Why the chosen design is clean (verified against code)

- **3-way match: ZERO changes for invoice shape (a); ONE additive skip for shape (b).** `matchableQty = quantity_received − quantity_invoiced` (`SupplierInvoiceMatcher.php:141-149`, scale 4) stays in paid units on both sides — free units are tracked in a *separate* counter (`free_quantity_received`) and never enter `quantity_received`. When the supplier invoice bills paid units only (shape a), 20 @ 5.000 against 20 received-paid → **Matched, green**, and `buildQtyGroupStatuses` (`:263-345`) and the price check are untouched. When the supplier prints free units as an explicit *remise en nature* line (shape b), the matcher gains a single additive rule: lines flagged `is_bonus_line` are excluded from the per-PO-line qty aggregation and from the price check (full contract in §9a). This is the only matcher change in the design.
- **GR-IR: ZERO changes.** `GeneralLedgerService::createGoodsReceiptGrIrEntry` computes `amount = bcround(bcmul($unitCost, $receivedQty, $scale+2), $scale)` and **returns null when the amount is ≤ 0** (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1075-1079`) — the free movement (unit cost `'0'`) produces no 408 accrual, so no residue can exist. The invoice clears exactly what the paid movement accrued (§7).
- **WAC: ZERO changes.** `WeightedAverageCostService::recordPurchase(product, location, quantity, landedUnitCost, …)` (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:144-153`) blends `newValue = value + qty×cost` over `newCompanyQty = qty_before + qty` and divides once at the cost boundary (`:236-248`). Calling it twice — paid units at the stated/landed cost, free units at `'0'` — yields *exactly* `totalPaid/(paid+free)` with **no intermediate rounding of the effective unit cost entering the ledger** (a single 21-unit movement at a pre-rounded 4.761904 would add 99.999984, not 100.000).
- **Landed cost: ZERO changes.** Allocation is proportional by `line_total` (`apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:83`, `:103-121`) and `landedUnitCost = (line_total + allocated + nonRecoverableTax) / line.quantity` at cost scale 6 (`:395-419`). The bonus line's paid value absorbs its full landed share; free units absorb it *indirectly* through the WAC blend (§8).
- **`unit_price` keeps its documented meaning** (net stated price per paid unit — B2B/HT per the precision contract), so tax math (`LandedCostService.php:207-209` computes `lineSubtotal = quantity × unit_price`) and print stay coherent.

---

## 2a. Different-product bonus — v1 excluded, Phase 2 shape (P0-2)

**Decision: v1 = same-product only. Cross-product ("buy 20 of X, get 2 of Y") is Phase 2.** This is deliberate, not an oversight.

**Why it cannot be folded into `free_quantity`.** `DocumentLine` carries exactly one `product_id`/`variant_id` per line (`DocumentLine.php:19-31`), and receiving resolves that single `product_id` before `recordPurchase` (`GoodsReceiptService.php:129-147`). `free_quantity` is definitionally same-SKU. A different awarded SKU (Y) needs its own physical/batch/expiry identity, its own WAC, and its own reporting row — none of which a scalar column on X's line can hold.

**Why it is genuinely harder (the WAC dilution question).** For same-product bonus, the free units dilute against the paid units *of the same product* on the same receipt — the blend denominator is `paid + free` and the numerator is the paid value; WAC converges to `totalPaid/(paid+free)` cleanly (§6). For cross-product bonus there is **no paid value for Y on this deal** — Y arrives at zero cost. A zero-cost `recordPurchase` for Y blends `0` value over Y's *existing* company stock, pulling Y's WAC **down toward zero** proportionally to how much free Y arrives vs Y on hand. That is arguably correct (you did get Y for free) but it is a different accounting judgement from same-product dilution and must be signed off by the expert-comptable before we ship it — e.g. some accountants prefer to book the received Y at its own standard/last cost with an offsetting "supplier bonus income" credit rather than depress WAC. v1 must not silently make that call.

**Phase 2 shape (sketch, not v1):** a gated **bonus child line** on the PO — `bonus_source_line_id` (FK to the paid line that earned it), `bonus_kind` enum (`SameProduct`/`DifferentProduct`), `is_bonus_line = true`, `quantity = awarded units`, `unit_price = 0`, `line_total = 0`. It is a real `DocumentLine` (so Y gets product/batch/expiry/reporting identity) but is **non-matchable for AP** (excluded from `aggregateInvoicedQtyPerPoLine` and price checks, exactly like the shape-(b) supplier-invoice bonus line in §9a) and **non-value-bearing for GR-IR** (unit cost 0 → `createGoodsReceiptGrIrEntry` returns null, `GeneralLedgerService.php:1075-1079`). The open decision for Phase 2 is purely the WAC-vs-bonus-income treatment above; the plumbing reuses the shape-(b) machinery this spec already introduces. Landed-cost allocation for a zero-value Y line needs its own rule (a `line_total = 0` line gets zero proportional share today — correct, but must be tested) — deferred with the rest of Phase 2 (review §5 item 11).

---

## 3. Schema deltas (all additive)

All on `tenant` connection; `document_lines` was created by `apps/api/database/migrations/tenant/2025_11_30_080001_create_document_lines_table.php:19-24` (`quantity` decimal(15,4), `unit_price`/`line_total` widened to scale 3 by `2026_03_11_200000_widen_monetary_columns_to_scale_3.php:60-64`, `landed_unit_cost` widened to decimal(19,6) by `2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:60`).

| Column | Table | Type | Default | Purpose |
|---|---|---|---|---|
| `free_quantity` | `document_lines` | `decimal(15,4)` | `0` NOT NULL | Bonus units on a purchase line. Mirrors `quantity` scale. Only meaningful on purchase-side documents. |
| `free_quantity_received` | `document_lines` | `decimal(15,4)` | `0` NOT NULL | Receiving counter for free units. Mirrors `quantity_received` (decimal(15,4) default 0, `2025_12_13_081142_add_quantity_received_to_document_lines_table.php:21`). |
| `free_quantity_invoiced` | `document_lines` | `decimal(15,4)` | `0` NOT NULL | AP counter for free units billed on the supplier invoice as a *remise en nature* line (§9a shape b). Mirrors `quantity_invoiced` (`2026_06_26_100000_...:36-38`); never touched by paid-line posting. |
| `price_entry_mode` | `document_lines` | `varchar(8)` + PHP enum `PriceEntryMode { Unit = 'unit', Total = 'total' }` | `'unit'` | Records which field the user entered (rule 9: enum, no magic strings). Drives authoritative-value semantics (§5) and re-edit UX. |
| `is_bonus_line` | `document_lines` | `boolean` | `false` NOT NULL | Marks a **supplier-invoice** line (and, in Phase 2, a bonus child line) that represents free/*remise-en-nature* units. Consumed by `SupplierInvoiceMatcher` to skip the line from qty aggregation + price check (§9a) and by posting to skip `quantity_invoiced` increment. `false` on every existing row and on all PO paid lines — semantically correct default. |

**Discount columns already exist (verified — no new discount schema).** `document_lines.discount_percent` (`decimal(5,2)`) and `discount_amount` (`decimal(15,2)`, cast `decimal:3` in the model) are present since the create migration (`2025_11_30_080001_create_document_lines_table.php:21-22`; model `DocumentLine.php:87-88,126-127`), and `calculateDiscountedSubtotal` (`DocumentLine.php:249-267`) applies `discount_percent` first then `discount_amount`, yielding `line_total = 0` for a 100% discount. This is the exact representation a supplier's *remise en nature* line uses (§9a shape b) — the schema already supports it; we only add the `is_bonus_line` marker so the matcher can tell a legitimate 100%-discount bonus line apart from an erroneous zero-value paid line.

**Where paid-vs-total lives:** `quantity` **is** the paid quantity (no rename, no backfill — every existing row already means "paid"). Total physical = `quantity + free_quantity`. The total amount paid lives where it always has: `line_total`.

No changes to `documents`; the paid-side `quantity_invoiced` (`2026_06_26_100000_...php:36-38`) and `accrual_unit_cost` (decimal(15,6), `2026_06_27_100000_add_accrual_unit_cost_to_document_lines.php:23`) keep their meaning unchanged. The free invoice side gets its own additive counter `free_quantity_invoiced` (see the schema table row above / §9a) — it never touches `quantity_invoiced`.

**DTO/types:** extend the Document line DTO + run `php artisan typescript:transform` (rule 7 — never hand-edit `packages/shared/types/`).

---

## 4. Entry UX (PO form)

Components: `apps/web/src/features/documents/DocumentForm.tsx` + `apps/web/src/features/documents/components/DocumentLineEditor.tsx` (uses `MoneyInput` atom, `:13`; client-side subtotal preview via `calculateDiscountedSubtotal`, `:41-67` — backend recomputes authoritatively per the precision contract's known-deferred note).

Line editor gains two gated elements: a **Qté gratuite** input (`QuantityInput`, emits strings) and a **unit-price ⇄ total-paid toggle** on the price cell.

```
┌────────────────────────────────────────────────────────────────────────────────┐
│ Produit            Qté payée   Qté gratuite   Prix [PU ▾|Total]   TVA   Montant│
│ ───────────────────────────────────────────────────────────────────────────────│
│ Doliprane 1000mg    [  20  ]    [   1   ]      [   5.000  ] PU    19%   100.000│
│                                                                                │
│   ├─ 21 unités au total · Coût unitaire effectif: ≈ 4.762 TND                 │
│   └─ Économie gratuité: 5.000 TND (1 × 5.000)                                 │
└────────────────────────────────────────────────────────────────────────────────┘

Toggle flipped to "Total":

│ Doliprane 1000mg    [  20  ]    [   1   ]      [ 100.000 ] Total  19%   100.000│
│   ├─ PU dérivé: 5.000 TND · Coût unitaire effectif: ≈ 4.762 TND               │
```

Rules:
- Free-quantity field and toggle render **only** when the feature gate passes (§10); otherwise the row is pixel-identical to today.
- Inline computed values use the Big.js helpers in `apps/web/src/lib/decimal.ts` — **never `parseFloat`/`Number`** (ESLint `no-parsefloat-on-money`). Effective cost = `bcdiv(line_total, bcadd(paidQty, freeQty))`, display via `formatCurrency`. Savings = `bcmul(freeQty, derivedUnitPrice)`.
- `free_quantity` input: optional, default empty ⇒ payload `"0"`; regex ceiling `/^\d+(\.\d{1,4})?$/` (quantity scale), must be `>= 0`; a line may have `free_quantity > 0` only with `quantity > 0` (no all-free lines in v1 — see Open Question 2).
- Payloads send all three as **strings** (precision contract, Frontend tier).

---

## 5. Either-or price entry — authoritative-value rule (precision contract)

Ingress validation extends the existing rules at `apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php:89-93` (quantity `regex:/^\d+(\.\d{1,4})?$/`, unit_price `regex:/^\d+(\.\d{1,3})?$/`); a `line_total`-mode entry gets the money ceiling `/^\d+(\.\d{1,3})?$/`.

**Mode `unit` (default — existing behavior, unchanged):** `unit_price` is authoritative. `line_total = bcformat(quantity × unit_price − discount, 3)` — one round at the write boundary, intermediates at scale+1.

**Mode `total`:** the entered total is **authoritative and stored verbatim in `line_total`** (scale 3). `unit_price` is a **DISPLAY derivation**: `bcdiv(line_total, quantity, scale+1)` rounded once to scale 3 for the column.

**Justification (the 100/7 case):** entering total 100.000 for 7 paid units derives unit price 14.2857… → stored 14.285 (truncated at scale 3). If the derived unit price were authoritative, recomputing `7 × 14.285 = 99.995 ≠ 100.000` — a 5-millième drift that would propagate into GR-IR accrual, VAT base, and the supplier-invoice price check. Storing the total as authoritative means every value consumer (VAT base, landed-cost proportion `LandedCostService.php:103`, GR-IR via `landed_unit_cost`) reads the exact paid amount; the derived `unit_price` is cosmetic. `price_entry_mode` records which is which so edits reopen in the same mode and the backend recompute knows **not** to overwrite an entered total.

**Cost-basis materialization rule:** for any line with `price_entry_mode = total` **or** `free_quantity > 0`, the PO posting path materializes `landed_unit_cost = bcdiv(line_total + allocated_costs + non_recoverable_tax, quantity, working)` at cost scale 6 — exactly the existing `LandedCostService::landedUnitCost` formula (`LandedCostService.php:395-419`) — even when allocated costs are zero. Why: `GoodsReceiptService` costs the receipt at `landed_unit_cost ?? unit_price` (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:156`); for a total-mode line the scale-3 `unit_price` fallback would reintroduce the 100/7 drift, while the scale-6 `landed_unit_cost` column absorbs the division residue (≤ 1e-6/unit, below the 3-dp posting round). Division at working scale (`scale+4`), one boundary round to cost scale 6 — the established WAC convention.

**bcmath scheme summary:**

| Value | Formula | Scales |
|---|---|---|
| `line_total` (mode unit) | `qty × unit_price − discount` | intermediates scale+1 → round once to 3 |
| `unit_price` (mode total, display) | `line_total / qty` | bcdiv at scale+1 → round once to 3 |
| `landed_unit_cost` | `(line_total + allocated + nonRecTax) / qty` | bcdiv at working (scale+4) → `bcformat` to cost scale 6 (`LandedCostService.php:419`) |
| Effective unit cost (display only) | `line_total / (qty + free_quantity)` | Big.js bcdiv, display via `formatCurrency` |
| WAC (persisted) | emerges from the two-movement blend | `WeightedAverageCostService.php:236-248`, cost scale 6, `bcformat` truncates |

**Precision honesty — exact invariant, not "exactness everywhere" (P1-5).** Rev 1 overclaimed. The correct, bounded statement:

> **GL is exact at currency scale (3 dp).** Both the GR-IR accrual (`bcround(unitCost × qty, 3)`, `GeneralLedgerService.php:1071-1079`) and the supplier-invoice clearing of `accruedHt` (`:1202-1214`) round half-up to 3 dp against the *same* basis, so 408 nets to exactly `0.000`.
> **WAC / movement value is accurate to cost scale 6** and, when the total is not divisible by the paid quantity, may sit below the authoritative `line_total` by less than `paid_qty × 1e-6` plus the standard WAC truncation residue.

Worked case (review §3 B2): total-mode `line_total = 23.333`, paid qty 7 → `landed_unit_cost = bcdiv(23.333, 7, 7) → 3.333285` (cost scale 6). Free-first then paid movement gives persisted WAC `2.333299`; the paid movement value is `23.332995`, i.e. `0.000005 TND` under the authoritative `23.333`. GR-IR still posts and clears `23.333` exactly (both boundaries round half-up), so **GL and AP are penny-perfect**; only the WAC sub-ledger carries the sub-millième residue, which is below display precision and is the *same* class of residue every existing WAC movement already tolerates (it is not new drift introduced by the two sequential `recordPurchase` calls — verified in review §3). Test §15.15 asserts this exact residue rather than asserting false exactness.

---

## 6. Receiving flow

`GoodsReceiptService::processReceiptLines` (`GoodsReceiptService.php:100-234`) changes, all gated on `free_quantity > 0`. **Rev 1 called this "no changes except the call site" — that was wrong (P1-1).** The current loop `continue`s the entire line when the paid `qtyToReceive ≤ 0` (`GoodsReceiptService.php:113-115`), *before* product lookup, batch validation, WAC, event emission, and counters — so a free-only delivery would be silently skipped. §6 therefore restructures the loop's eligibility gate; it does **not** touch the matcher/GR-IR/WAC services themselves.

1. **Request shape:** alongside `received_quantities[line_id]`, add `free_quantities[line_id]` (optional, string, quantity regex, gated).
2. **New loop eligibility gate (P1-1):** replace the `if (bccomp($qtyToReceive,'0',4) <= 0) continue;` guard with
   `$paidToReceive = received_quantities[line_id] ?? '0'; $freeToReceive = free_quantities[line_id] ?? '0'; if (bccomp($paidToReceive,'0',4) <= 0 && bccomp($freeToReceive,'0',4) <= 0) continue;`
   Product resolution (`:142-148`), batch-required validation (`:150-152`), `hasReceivedItems = true`, and status recompute must run when **either** paid or free is being received. `hasReceivedItems` is set true if *either* a paid or a free movement was recorded.
3. **Guards (independent, per direction):** paid over-receive guard unchanged (`remaining = quantity − quantity_received`, throws `"Cannot receive more than ordered…"`, `:120-127`) but only evaluated when `paidToReceive > 0`. New parallel guard evaluated when `freeToReceive > 0`: `freeToReceive ≤ free_quantity − free_quantity_received`. Paid and free receipts are independent — the supplier may ship the gratuité in a later delivery, or a delivery may be **free-only**.
4. **Movements (up to two per line; free FIRST when both present):**
   - When `freeToReceive > 0`: `recordPurchase(product, location, freeToReceive, '0', reference, 'Document', poId, variantId)` — free units, zero cost. WAC dilutes; `GoodsReceived` emitted with unitCost `'0'` → GR-IR listener (`PostGrIrOnGoodsReceipt.php:25-34`) posts nothing (`amount ≤ 0` → null, `GeneralLedgerService.php:1075-1079`).
   - When `paidToReceive > 0`: `recordPurchase(product, location, paidToReceive, landed_unit_cost ?? unit_price, …)` — the unchanged existing call (`:167-176`).
   - **Free-only delivery** = only the first movement fires; no paid movement, no GR-IR, `quantity_received` unchanged. Its own batch data is required (§6.6).
   - Ordering rationale: `recordPurchase` updates `product.last_purchase_cost` and the margin auto-update per movement (`WeightedAverageCostService.php:286-292`); free-first ensures both end on the *paid* movement's stated cost. Final WAC is order-independent (value sum is commutative). For a free-only receipt, `last_purchase_cost` is intentionally NOT rewritten to 0 — a free movement carries cost `'0'`; confirm `recordPurchase` does not clobber `last_purchase_cost` on a zero-cost movement (test §15.16), else guard the update to `unitCost > 0`.
5. **Counters:** `quantity_received += paidToReceive` (existing, `:224`, only when paid > 0); `free_quantity_received += freeToReceive` (new, only when free > 0). Physical received on the line = sum of both.
6. **Batch tracking — two batch movements when two stock movements (P2-1).** `receiveBatchStock` creates one `BatchMovement` **linked to a single stock movement id** and does not touch aggregate stock (`BatchStockService.php:169-183`; call site `GoodsReceiptService.php:193-214`). With two stock movements we therefore record **two batch movements**, each `movementId`-linked to its own stock movement (free→free-movement, paid→paid-movement), **both pointing at the same batch** when the physical lot is shared (same case → same `batch_number`/`expiry_date`, resolved once via `findOrCreateBatch`). Aggregate batch quantity for the lot = `paid + free`, matching the two stock movements. A free-only delivery still requires its own `batchData` (same validation as today, `:150-152`) and produces exactly one batch movement (free). Invariant to test (§15.17): batch on-hand for the lot equals sum of its linked movement quantities = `paid + free`.
7. Receiving UI shows two receive fields per bonus line ("Reçu payé / Reçu gratuit"), each defaulting to its own remaining; a free-only line renders only the free field.

### WAC worked example (owner's case, verified against the blend math)

Fresh product, TND (scale 3, working scale 7, cost scale 6). PO line: 20 paid + 1 free @ 5.000 → `line_total = 100.000`, `landed_unit_cost = 5.000000`.

| Step | qty | unit cost | value added | company qty | company value | WAC (scale 6, truncated) |
|---|---|---|---|---|---|---|
| Free movement | 1 | 0 | 0.000 | 1 | 0.000 | 0.000000 |
| Paid movement | 20 | 5.000000 | 100.000 | 21 | 100.000 | `bcdiv(100.000, 21, 7)` = 4.7619047 → **4.761904** |

Effective cost persisted = **4.761904 TND** (exactly `100/(20+1)` before the single cost-scale round). Displayed ≈ **4.762 TND** (owner's figure; display rounding at `formatCurrency` — note the existing bcformat-truncates vs half-up owner sign-off item in the precision contract applies here identically, ≤ 1 millième). Total inventory value = 21 × WAC ≈ 100.000 = amount paid. ✓

---

## 7. GL walk-through — bonus line end-to-end (proves 408 nets to zero)

Accounts: 3x Inventory + 408 GRNI (`SystemAccountPurpose::Inventory` / `GoodsReceivedNotInvoiced`, `GeneralLedgerService.php:1081-1082`), 401 supplier, 4456 recoverable VAT. Case: 20 paid + 1 free @ 5.000 TND, VAT 19%, no other landed costs.

**Goods receipt** (listener `PostGrIrOnGoodsReceipt.php:34` → `createGoodsReceiptGrIrEntry`, amount = `bcround(unitCost × receivedQty, scale)`, `GeneralLedgerService.php:1075`):

| Movement | Entry | Debit | Credit |
|---|---|---|---|
| Free (1 @ 0) | **none** — amount 0 → `return null` (`:1075-1079`) | — | — |
| Paid (20 @ 5.000) | Dr 3x Inventory 100.000 / Cr 408 100.000 (`:1111-1130`) | 100.000 | 100.000 |

Note the deliberate asymmetry: the GL inventory debit (100.000) equals the inventory sub-ledger value added (0 + 100.000) — GL and WAC stay reconciled because *both* value the free units at zero incremental cost.

**Supplier invoice posting** (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php`): invoice states 20 units @ 5.000, HT 100.000, VAT 19.000, TTC 119.000.

- Clearing basis = `landed_unit_cost ?? unit_price` = 5.000000 (`:132`); B3 guard compares it to the stored `accrual_unit_cost` at scale 6 (`:139-151`) — same basis the receipt accrued on, passes.
- `lineAccrual = bcmul(20, 5.000000) = 100.000` (`:153`); over-clear guard `invoiced(20) ≤ quantity_received(20)` holds in paid units (`:162-171`).

| Entry | Debit | Credit |
|---|---|---|
| Dr 408 GRNI (clear accrual) | 100.000 | |
| Dr 4456 VAT recoverable | 19.000 | |
| Cr 401 Supplier | | 119.000 |

**408 balance: 100.000 accrued − 100.000 cleared = 0.000.** No residue is arithmetically possible for the free units because they never accrued. The residue detection in `SupplierInvoicePostingService.php:134-151` sees identical bases. ✓

---

## 8. Landed cost allocation rule

`LandedCostService::allocateCosts` / `allocateCostsAndTaxes` distribute additional costs and document-level non-recoverable taxes **proportionally by line value** (`line_total`) with largest-remainder absorption (`LandedCostService.php:78-129`, `:147-230`).

**Rule (no code change):** free units have no line value of their own — they share the paid line's value, so the line absorbs its full proportional share via `line_total`, and `landed_unit_cost` stays *per paid unit* (`(line_total + allocated + nonRecTax) / quantity`, `:395-419`). Free units then absorb landed cost **through the WAC blend**: the paid movement carries the entire landed value; blended over paid+free, the effective landed cost per physical unit = `(line_total + allocated) / (paid + free)`. Example: 10.500 TND freight on the 20+1 line → landed_unit_cost = 110.500/20 = 5.525000; blend = 110.500/21 = 5.261904 effective. Allocation invariants (`Σ allocated = additional costs total`) are untouched because the denominators never see `free_quantity`.

---

## 9. Fiscal / tax

- **VAT is computed on paid value only.** The recoverable/non-recoverable line tax estimation uses `lineSubtotal = quantity × unit_price` (`LandedCostService.php:207-209`) and document tax totals derive from `line_total` — `free_quantity` appears in none of these expressions. No tax on free units by construction.
- **Tunisia stamp (timbre fiscal)** is document-level, computed off document totals — unaffected. **Withholding** applies to invoiced amounts — paid-only, unaffected.
- **Supplier invoice VAT treatment is NOT "the supplier's problem" — see §9a.** Rev 1 dismissed it; the review (P0-1) is right that an expert-comptable-clean purchase system must ingest whatever legally-shaped invoice the supplier issues. Tunisian VAT-taxpayer invoices are legally required to state, per line, the goods/services designation, the HT price, the VAT rate, and the VAT amount (Ministère des Finances: https://www.finances.gov.tn/fr/node/75 and https://www.finances.gov.tn/fr/node/952 — invoicing obligations for assujettis à la TVA, cited in this repo at `docs/superpowers/reviews/2026-05-13-pos-fiscal-event-engine-codex-review-round3.md:68-70`). Free units commonly appear as an explicit 100%-discount / *remise en nature* line **on** that invoice. §9a defines how we ingest both shapes without breaking the matcher.
- No fiscal hash-chain impact: purchases are not SALE_RECEIPT events; the two-tier chains are untouched.

---

## 9a. Supplier-invoice ingestion — the dual invoice-shape contract (P0-1, highest-risk)

This is the load-bearing section of Revision 2. It replaces Rev 1's assumption that "the invoice bills paid qty only" and defines a contract that stays green for **both** real Tunisian invoice shapes.

### Fiscal grounding (Tunisia)

VAT-registered suppliers ("assujettis à la TVA") must issue **numbered** invoices that mention, per line, the **designation** of goods/services, the **HT unit price**, the **VAT rate**, and the **VAT amount** (Ministère des Finances de Tunisie, obligations de facturation: https://www.finances.gov.tn/fr/node/75 §"factures", https://www.finances.gov.tn/fr/node/952 — same obligations page; both cited in-repo at `docs/superpowers/reviews/2026-05-13-pos-fiscal-event-engine-codex-review-round3.md:68-70`). Free goods ("gratuité" / *remise en nature*) are therefore commonly itemized **on** the commercial invoice, most often as a line whose quantity is the free units and whose value is reduced to zero by a **100% remise (discount)** — so the invoice's HT/VAT totals still reflect only the paid units. **Action item before coding (review §5.12): collect 2–3 real supplier invoices from the launch parapharmacy and have the expert-comptable confirm which shape(s) they actually use.** The contract below supports both regardless.

### The two shapes and how each maps onto the counters

| # | Invoice shape (what the supplier prints) | How we ingest it | PO paid counter (`quantity_invoiced`) | PO free counter (`free_quantity_invoiced`) | Matcher outcome |
|---|---|---|---|---|---|
| **(a)** | **Paid qty only** — one line `20 @ 5.000 HT`, VAT 19%. Free units not on the invoice. | Single invoice line, `is_bonus_line = false`, `source_line_id = PO line`. | `+20` (via existing posting `quantity_invoiced += billedQty`) | unchanged | `matchableQty = quantity_received(20) − quantity_invoiced(0) = 20`; aggregate invoiced `20 ≤ 20` → **Matched, green**. Zero matcher change. |
| **(b)** | **Paid line + explicit free line** — `20 @ 5.000 HT` **plus** `1 @ 5.000, remise 100% → net 0.000` (*remise en nature*/gratuité). Invoice HT still `100.000`, VAT `19.000`. | Two invoice lines both `source_line_id = same PO line`: the paid line `is_bonus_line = false` (qty 20); the free line `is_bonus_line = true` (qty 1, `discount_percent = 100` ⇒ `line_total = 0`, `unit_price = 5.000` stated). | `+20` (bonus line skipped) | `+1` (bonus line increments the free counter) | Matcher **skips** the `is_bonus_line` line from qty aggregation and price check; paid aggregate `20 ≤ 20` → **Matched, green**. Free line validated separately: `1 ≤ free_quantity_received(1)` at **zero value**. |

Both shapes converge to the same PO state: `quantity_invoiced = 20`, `free_quantity_invoiced = 1`. GR-IR clears exactly `100.000` (§7) in both because the free line's `line_total = 0` contributes nothing to HT, VAT, or 408.

### Exact matcher change for shape (b) (the ONLY matcher change)

`SupplierInvoiceMatcher::buildQtyGroupStatuses` (`SupplierInvoiceMatcher.php:263-345`) today sums **every** invoice line's `quantity` per `source_line_id` and Exception-blocks any line whose `source_line_id` is null (`:182-190`, `:270-273`). A shape-(b) free line therefore either inflates the aggregate to `21 > matchable 20 → QuantityVariance` (if linked) or trips the null-line Exception (if left unlinked). The bounded, additive fix:

1. **Skip bonus lines from the paid aggregation.** In the `foreach ($supplierInvoice->lines …)` loop of `buildQtyGroupStatuses` (`:269-285`), `if ($invoiceLine->is_bonus_line) { continue; }` **before** the null-source check and before `bcadd` into `$groupQtys`. Effect: bonus lines never enter `matchableQty` comparison and never trip the null-line Exception.
2. **Require bonus lines to be linked, validate them against the free counter.** A bonus line MUST still carry `source_line_id` (audit + counter). A new, additive validation pass sums bonus-line qty per `source_line_id` and asserts it `≤ free_quantity_received − free_quantity_invoiced` (the free-side analogue of `matchableQty`). Over-claim on free units is its own `QuantityVariance` (free), symmetric with the paid path. This lives in the matcher but is a *separate* aggregation from the paid one, so the paid path is byte-for-byte unchanged.
3. **Skip bonus lines from the price check.** The per-line price loop (`match()` `:108-125`, `assertPostable()` `:210-224`) adds `if ($invoiceLine->is_bonus_line) { continue; }` — a 100%-discounted line has a stated `unit_price` but zero net; comparing its net-vs-PO price is meaningless and would false-positive `PriceVariance`.

### Exact posting change for shape (b)

`SupplierInvoicePostingService` increments `quantity_invoiced += billedQty` per matched line and accrues/clears 408 on `line_total`. Two additive rules:
- **Bonus lines increment `free_quantity_invoiced`, not `quantity_invoiced`.** Guard: `free_quantity_invoiced += bonusQty` where `free_quantity_invoiced ≤ free_quantity_received` (over-clear guard, mirrors the paid `:162-171` guard).
- **Bonus lines contribute 0 to 408 / VAT / AP.** Because `line_total = 0` the existing accrual math (`lineAccrual = unitCost × qty` on the *cleared* basis) must read the **net** line value (`line_total`), not `unit_price × qty` — confirm the clearing basis is `line_total`/`accrual_unit_cost`-derived and not the stated `unit_price` for bonus lines; a bonus line's clearing basis is `0`. Net effect: 408 balance identical to shape (a).

### New schema counter for the free invoice side

Add `free_quantity_invoiced decimal(15,4) NOT NULL default 0` to `document_lines` (mirrors `quantity_invoiced`, `2026_06_26_100000_...:36-38`) so the free-side match/posting is auditable and idempotent. Listed in §3 and §14. (Rev 1 tracked only received-side free counters; the invoice side needs its own counter for shape (b).)

### Import / manual-entry behavior

- Manual supplier-invoice entry (and any future OCR/import per `project_supplier_invoice_ocr_capture`): a line the operator marks as gratuité sets `is_bonus_line = true` and (typically) `discount_percent = 100`; the UI links it to the same PO line as its paid sibling.
- If an operator enters shape (b) but forgets the bonus flag, the matcher correctly blocks with `QuantityVariance` (fail-safe: better to block than to silently over-clear). The block message names the offending line so the operator can flag it.
- A supplier who prints a single line `21 @ (diluted)` with no discount is a **third, unsupported shape** in v1 — it collapses paid+free into one value-bearing quantity and breaks the paid-vs-free separation. v1 rejects it at entry (validation: a bonus-flagged line requires `line_total = 0`); the operator splits it into a paid line + a 100%-discount bonus line. Document this in the operator guide.

---

## 10. Vertical/country gating (two-layer, per `docs/architecture/vertical-module-gating.md`)

`config/verticals.php` is the SoT, but the module **name must first exist as a `ModuleName` enum case** — the drift guard (`tests/Unit/Enums/ModuleNameTest.php`) fails CI if `verticals.php` names a module with no enum case (`docs/architecture/vertical-module-gating.md:93-103,141-144`). Rev 1 under-specified this (P1-4): `ModuleName` currently has **no `PurchaseBonus` case** (it ends at `Merchandising`, `ModuleName.php:14-39`), and Rev 1 mis-cited parapharmacy's config — the real `compatible_extras` is `['Loyalty', 'Ecommerce', 'CompositeItems']` and `default_modules` already include `BatchExpiry`, `Parapharmacy`, `Merchandising` (`config/verticals.php:335-360`). Corrected end-to-end checklist:

**1. Add the enum case.** `ModuleName::PurchaseBonus = 'PurchaseBonus'` (`app/Enums/ModuleName.php`). Update `tests/Unit/Enums/ModuleNameTest.php` and any module-drift test that snapshots the full case set.

**2. Wire it into vertical config** (`config/verticals.php`): add `'PurchaseBonus'` to parapharmacy `default_modules` (the list at `:344-360`, alongside `Parapharmacy`/`Merchandising`); add it to `compatible_extras` for other verticals that may opt in (e.g. append to `pharmacy` / `retail` extras lists — verify the exact vertical keys at edit time, do not trust Rev 1's line numbers). Update the vertical-config drift/snapshot tests.

**3. Country layer** (the "and/or per country" half): new `config('procurement.bonus_quantity_countries', ['TN'])` checked against `Company.country_code` (ISO 3166-1 alpha-2, `app/Modules/Company/Domain/Company.php:41`, fillable `:199`). Empty list = all countries.

**4. Backend authority helper (not FE-derived).** `hasModule('PurchaseBonus')` on the FE cannot know country eligibility, so add a single backend gate — `PurchaseBonusGate::enabledFor(Company $company): bool` = `moduleEnabled(PurchaseBonus) && (countryList empty || country in list)`, constructor-injected (no `app()`). This is the authority both the FormRequests and the CompanyConfig payload consult.

**5. Backend field-level gating (route-level is wrong here).** PO create/update are shared Document routes, so `module:PurchaseBonus` middleware (`bootstrap/app.php:45-53` → `RequireModule`) would wrongly gate all document types. Instead the FormRequests consult `PurchaseBonusGate`: when disabled, `lines.*.free_quantity` → `prohibited`, `lines.*.price_entry_mode` → `in:unit`, `lines.*.is_bonus_line` → `prohibited`; when enabled, the §4/§5/§9a rules apply. The goods-receipt request gates `free_quantities` the same way. This is the documented field-gating half of the two-layer pattern.

**6. CompanyConfig payload.** Expose `purchase_bonus_enabled` (= `PurchaseBonusGate::enabledFor(company)`) in `/company/config` so the FE reads one authoritative boolean and never hardcodes country logic. FE consumes it via `CompanyConfigContext` (`apps/web/src/contexts/CompanyConfigContext.tsx:64-75`, which already surfaces `all_enabled_modules`).

**7. Frontend gate = module AND flag.** Free-qty column, toggle, bonus-line marker, and receiving fields render only when `hasModule('PurchaseBonus') && purchase_bonus_enabled`. Any future dedicated screen wraps in `RequirePermission moduleKey="PurchaseBonus"` (`apps/web/src/features/auth/components/RequirePermission.tsx:10,40,50-51`).

**8. Migration for existing tenants.** The new module is off by default for every existing tenant except where a vertical's `default_modules` now includes it. Provisioning already materializes `default_modules` per tenant, but **existing** parapharmacy tenants provisioned before this change need a one-off: a data migration (or the standard module-reconcile command) that enables `PurchaseBonus` for tenants whose vertical now defaults it. Non-parapharmacy tenants get it only via explicit `compatible_extras` upgrade. Verify the exact reconcile mechanism against the tenancy provisioning path before writing the migration; do not silently enable it for verticals that did not request it.

---

## 11. Printed / PDF purchase order

Template: `apps/api/resources/views/documents/templates/purchase_order.blade.php` via the shared `components/line_items.blade.php`. Today Qty renders at `line_items.blade.php:28`.

Tunisian suppliers expect the gratuité stated explicitly. Rendering (conditional on `free_quantity > 0`, so all other documents/verticals are pixel-identical):

```
#  Description                      Qté        PU        TVA    Montant
1  Doliprane 1000mg [DOL1000]        20      5.000      19%    100.000
   dont gratuité : +1 unité gratuite
                                   ─────
Total ligne: 21 unités livrées attendues
```

- Qty column keeps the **paid** quantity (that's what the supplier bills); a sub-row `+ N gratuité(s)` appears under the description (pattern of the existing `notes` sub-row, `line_items.blade.php:24-26`).
- Amount column unchanged (paid value). Strings via `__()` lang files (blade layer; the FE uses `t()` per rule 11).

---

## 12. 3-way match representation (recap)

- PO line: `quantity = 20` (paid), `free_quantity = 1`.
- Receipt: `quantity_received = 20`, `free_quantity_received = 1`.
- Supplier invoice, **shape (a)** (paid-only): line 20 @ 5.000 → `matchableQty = 20 − 0 = 20` (`SupplierInvoiceMatcher.php:141-149`) → qty **Matched**; price 5.000 vs 5.000 → **Matched**. Zero matcher change.
- Supplier invoice, **shape (b)** (explicit gratuité line): paid line 20 + `is_bonus_line` line 1 → matcher skips the bonus line, paid aggregate `20 ≤ 20` **Matched**, free line validated `1 ≤ free_quantity_received` (§9a). One additive matcher skip.
- Posting: `quantity_invoiced → 20 ≤ quantity_received 20` (`SupplierInvoicePostingService.php:162`) ✓; free counter `free_quantity_invoiced → 1` (shape b); 408 cleared exactly (§7).
- Edge: supplier under-ships the gratuité (20 paid received, 0 free) — match is still green (free counters are match-invisible); the shortfall is a purchasing follow-up surfaced on the PO detail ("gratuité en attente: 1"), not an AP exception. This matches the commercial reality: you cannot short-pay a supplier over goods you were never billed for.

---

## 12a. Supplier credit notes / returns of bonus stock (P1-2 — promoted to v1)

Rev 1 deferred this to Phase 2, but the code already has a supplier-credit-note posting path with a goods-return quantity ledger, and returns of free stock are a normal dispute/return workflow — deferring pushes operators to manual stock adjustments outside the AP audit trail. v1 must define it.

**What the code does today.** A `SupplierCreditNote` with reason `GoodsReturn` decrements `quantity_invoiced` per PO line (`SupplierCreditNoteReason.php:13-20,30-35`) via `guardAndDecrementGoodsReturn` (`SupplierCreditNotePostingService.php:373-410`), which rejects if `quantity_invoiced` would go below zero, and bounds cumulative credited HT against the invoice subtotal (`:333-370`). Negative stock is hard-blocked with no back-valuation engine (`docs/adr/2026-06-30-negative-stock-hard-block.md:35-49`).

**v1 rules for returning bonus stock (cost basis = diluted WAC):**

- **Returning FREE units (zero-value credit).** The physical stock leaves at the **current diluted WAC** (a real COGS/inventory reduction — you are giving back stock you carry at a diluted cost), but the credit note is **zero HT/VAT** and must **NOT** reopen paid `quantity_invoiced`. Represent it as a credit-note line with `is_bonus_line = true` and a new `returned_free_quantity`; posting decrements `free_quantity_invoiced` (and surfaces a follow-up to reduce `free_quantity_received`), never the paid counter. GL: credit Inventory (3x) at `returnQty × WAC`, debit an accounting-signed-off account — **inventory-shrinkage/variance or bonus-reversal expense, NOT 401 supplier** (there is no payable to reduce for a free unit). The exact debit account is an **owner/accountant sign-off item** (Open Question 4).
- **Returning PAID units.** Unchanged existing path — `GoodsReturn` decrements `quantity_invoiced`, credit note carries HT/VAT, cumulative-HT guard applies (`:333-370`).
- **Mixed return** (some paid, some free on one credit note): two line kinds on one credit note — paid lines flow the existing path; bonus lines flow the zero-value path above. The cumulative-HT guard sees only the paid lines' HT (bonus lines contribute 0), so it is unaffected.
- **Guards:** free return `≤ free_quantity_received`; negative-stock/batch availability enforced by the existing hard-block (a return that would drive on-hand negative is rejected, same as any issue).

This reuses the shape-(b) `is_bonus_line` machinery (§9a) end-to-end — the credit-note matcher/posting skip is the mirror of the invoice one.

---

## 12b. PO revision after partial receipt (P1-3 — data-integrity invariant)

Rev 1 defined additive counters but no revision rule. `DocumentStatus::isEditable()` returns true for **both** `Draft` and `Confirmed` (`DocumentStatus.php:19-24`; `Document::isEditable` delegates, `Document.php:481-483`), and `PurchaseOrderController::update()` **deletes and recreates ALL lines** when `lines` is provided (`PurchaseOrderController.php:303-309,336-340`). After a partial receipt this would orphan supplier-invoice `source_line_id` links, reset `quantity_received`/`free_quantity_received`/`accrual_unit_cost` onto fresh line IDs, and let ordered qty drop below received. Bonus counters make it strictly worse.

**v1 invariants (enforced in `UpdateDocumentRequest`/controller for PurchaseOrder):**

1. **No wholesale line replacement after any receipt.** If any line on the PO has `quantity_received > 0` **or** `free_quantity_received > 0`, reject the delete-and-recreate path. Editing header fields stays allowed.
2. **Preserve line IDs.** Post-receipt edits operate on existing line IDs (targeted update), never delete+recreate — accounting/matching continuity depends on stable `source_line_id`.
3. **Quantities cannot drop below received.** `quantity ≥ quantity_received` and `free_quantity ≥ free_quantity_received` on every edited line (and, symmetrically for AP, `≥ quantity_invoiced` / `free_quantity_invoiced`).
4. **Increases are allowed** (a supplier can raise the paid or free quantity mid-fulfilment) through the targeted-update path; new lines may be appended.
5. This invariant is broader than the bonus feature (it fixes a pre-existing gap) but the bonus counters are why it becomes mandatory now. Scope note: implement the guard as part of this feature; do not refactor the whole PO-edit flow.

---

## 13. Reporting impact

- **Margins dilute automatically.** All margin/COGS reporting reads `product.cost_price` (the blended WAC persisted by `recordPurchase`, `WeightedAverageCostService.php:287`) — after a bonus receipt it already reflects `totalPaid/(paid+free)`, so sales margin on every subsequent sale is computed against the diluted cost with **zero report changes**. State this in release notes: margins will (correctly) look better on bonus-purchased products.
- Stock valuation: qty × WAC = amount paid (§6 example) — GL/sub-ledger reconciliation holds.
- `last_purchase_cost` shows the stated paid cost (free-first ordering, §6.4) — intentional: it answers "what does the supplier charge per unit".

### Buyer-facing bonus analytics — moved to Phase 1 (P2-2)

Rev 1 called purchase analytics a "Phase-2 nicety"; the owner bar (outperform generic ERPs via vertical customization) makes bonus KPIs a Phase-1 differentiator — a parapharmacy buyer expects, on day one, to answer "which supplier actually gave the best net cost?" and "how much free stock did we get / miss this month?". Phase 1 therefore ships these (persisting computed facts is optional — the spec commits to the **queries + acceptance tests**, all derivable from `free_quantity*` counters + `line_total` + WAC, no float per rule 19):

- **Per line:** effective unit cost after bonus (`line_total / (quantity + free_quantity_received)`), bonus savings (`free_quantity_received × derived_unit_price`).
- **Per PO / receipt:** free ordered vs received vs outstanding (`free_quantity − free_quantity_received`).
- **Per supplier / month:** total bonus value received (Σ savings), same-product-only in v1 (cross-product counted once §2a Phase 2 lands).
- Acceptance tests assert these against the §6 worked case (20+1 @ 5.000 → effective 4.762, savings 5.000) and the free-only + partial-receipt cases.

---

## 13a. Industry comparison — honest positioning

Corrects Rev-review §4 industry claims (which had a placeholder table). Sources verified in the review:

| System | Free-goods on **purchasing** side | VAT treatment | Effective-cost / supplier-savings reporting |
|---|---|---|---|
| **Odoo 18** | The "Free Product" reward is a **sales** loyalty/discount mechanic (https://www.odoo.com/documentation/18.0/applications/sales/sales/products_prices/loyalty_discount.html); vendor pricelists model vendor/min-qty/unit-price (…/purchase/products/pricelist.html), **not** a first-class purchase-bonus-quantity field. | Follows order/invoice lines + fiscal positions; no documented TN purchase-gratuité flow. | Focused on promotions/pricelists, not parapharmacy supplier-bonus savings. |
| **SAP Business One** | No verified official free-goods **purchase** representation; implementations typically use zero-price/discount lines or add-ons (external knowledge, not official docs). | Not verified from official docs. | Config/add-on dependent. |
| **MS Dynamics 365 Business Central** | Models purchase price/**line-discount**/invoice-discount agreements and best-price by vendor/item/min-qty (https://learn.microsoft.com/en-us/dynamics365/business-central/purchasing-how-record-purchase-price-discount-payment-agreements); **line discounts are handled as discount %, not as a bonus-quantity** with WAC dilution; discounts may post separately or be subtracted per setup. | Discount posting configurable. | No cited free-goods savings report. |
| **This design** | First-class same-product purchase-bonus quantity with **automatic diluted WAC** via a zero-cost companion movement; dual TN invoice-shape ingestion (§9a); cross-product as Phase 2 (§2a). | VAT blind to `free_quantity`; ingests the legally-shaped supplier invoice (§9a) rather than assuming it away. | Effective cost + savings inline **and** supplier/month bonus KPIs in Phase 1 (§13). |

**Honest advantage:** none of the three generic ERPs model a purchase **bonus quantity with automatic WAC dilution** as a first-class concept — they lean on discounts (BC's line-discount, Odoo/SAP zero-price lines) which do **not** produce the `totalPaid/(paid+free)` effective cost automatically. This design's edge is (1) that automatic dilution, (2) the TN dual invoice-shape contract, and (3) Phase-1 buyer KPIs. The edge is *not* breadth — cross-product bonus is deferred (§2a) and these ERPs' discount engines are broader. We win on the specific parapharmacy-TN gratuité workflow, honestly stated.

---

## 14. Migration plan

1. Additive tenant migration on `document_lines`: `free_quantity` decimal(15,4) NOT NULL default 0, `free_quantity_received` decimal(15,4) NOT NULL default 0, `free_quantity_invoiced` decimal(15,4) NOT NULL default 0 (free-side AP counter, §9a), `price_entry_mode` varchar(8) NOT NULL default `'unit'`, `is_bonus_line` boolean NOT NULL default false (§9a matcher/posting skip). No backfill (defaults are semantically correct for every existing row), no index needed. DECIMAL/boolean addition is non-destructive; per-tenant rollout via the standard tenant migration runner.
2. `PriceEntryMode` PHP enum + Eloquent casts (`decimal:4` on the three quantity columns, `boolean` on `is_bonus_line`).
3. DTO extension + `php artisan typescript:transform` (never hand-edit `packages/shared/types/`).
4. `ModuleName::PurchaseBonus` enum case + drift-test update; `config/verticals.php` module wiring (parapharmacy `default_modules` + opt-in `compatible_extras`) + vertical-config drift-test update; `config/procurement.php` `bonus_quantity_countries` addition (§10 steps 1-3).
5. `PurchaseBonusGate` backend helper + `purchase_bonus_enabled` in the `/company/config` payload (§10 steps 4,6).
6. **Existing-tenant module reconcile** (§10 step 8): one-off data migration / module-reconcile command enabling `PurchaseBonus` for tenants whose vertical now defaults it (parapharmacy). Verify the provisioning reconcile mechanism before writing it; do NOT enable for non-requesting verticals.
7. Lang keys (FR/EN/AR — "Qté gratuite", "gratuité", "remise en nature", RTL-safe per i18n context doc).

---

## 15. Test plan (TDD — red first, per rule 2)

**Backend (PHPUnit, run by path — never the full suite):**
1. FormRequest: `free_quantity` rejected (`prohibited`) when module off / country not allowed; accepted with regex ceiling `1,4` when gated on; `price_entry_mode=total` requires entered `line_total`, forbids >3dp.
2. PO create/update persists `free_quantity` + `price_entry_mode`; unit-mode line_total computation unchanged (regression).
3. Total-mode: entered `line_total=100.000, qty=7` → stored line_total exactly `100.000`, derived unit_price `14.285`, materialized `landed_unit_cost = 14.285714`; recompute does NOT overwrite the entered total.
4. Receiving: free over-receive guard throws; paid guard regression; partial free-only delivery updates only `free_quantity_received`.
5. Receiving records two movements (free @ '0' first, paid second); final `cost_price = 4.761904` for the 20+1 @ 5.000 case; `last_purchase_cost = 5.000000`.
6. GR-IR: free movement produces NO journal entry; paid movement accrues 100.000 (idempotency regression on both movement UUIDs).
7. Supplier invoice: match green (20 vs 20), posting clears 408 to exactly `0.000` (assert 408 balance), B3 accrual-basis guard passes.
8. Landed cost + bonus combined: freight 10.500 → landed_unit_cost 5.525000, WAC 5.261904; allocation sum invariant holds.
9. VAT: document tax totals identical with `free_quantity` 0 vs 5 (tax blindness proof).
10. PDF render test: gratuité sub-row appears iff `free_quantity > 0` (rendered-HTML assertion per rule 17).
11. Batch-tracked product: free units land in the paid delivery's batch.

**Frontend (Vitest):**
12. `DocumentLineEditor`: free-qty field + toggle hidden when `!(hasModule && purchase_bonus_enabled)` (mock `usePermissions`/`CompanyConfigContext`), visible when both true; effective-cost/savings inline values computed via decimal.ts (string in, string out — assert no float artifacts on `100/21`).
13. Toggle: entering total derives displayed PU; entering PU derives total; payload carries strings + `price_entry_mode`.
14. Receiving screen: dual receive fields (paid/free), free-only line renders only the free field, guards mirrored client-side.

**Backend (Rev 2 additions — the review findings):**
15. **Precision residue (P1-5):** total-mode `line_total=23.333`, qty 7 → persisted WAC `2.333299`, paid-movement value `23.332995`; assert GR-IR posts/clears exactly `23.333` and 408 nets to `0.000`. Assert the residue, not false exactness.
16. **Free-only receipt (P1-1):** `received_quantities[line]="0.0000"`, `free_quantities[line]="1"` → line processed (not skipped), one free movement @ '0', `free_quantity_received += 1`, `quantity_received` unchanged, no GR-IR entry, `hasReceivedItems` true; `last_purchase_cost` NOT clobbered to 0.
17. **Batch two-movement (P2-1):** batch-tracked 20+1 receipt → two `BatchMovement`s (free + paid) on the same batch, aggregate batch on-hand = 21 = sum of linked movement quantities; free-only receipt → one batch movement.
18. **Shape-(b) matcher (P0-1):** invoice with paid line `20 @ 5.000` + bonus line `1 @ 5.000 remise 100%` (`is_bonus_line=true`), both linked to same PO line → paid aggregate `20 ≤ 20` **Matched**; bonus line skipped from qty aggregation + price check; free-side validated `1 ≤ free_quantity_received`; posting increments `quantity_invoiced=20` and `free_quantity_invoiced=1`; 408 clears to `0.000`. Regression: shape-(a) paid-only invoice still Matched with zero matcher behavior change. Fail-safe: bonus line without the flag → `QuantityVariance` block.
19. **Supplier credit-note bonus return (P1-2):** zero-value credit note with `is_bonus_line` return of 1 free unit → stock issued at diluted WAC, `free_quantity_invoiced` decremented, `quantity_invoiced` untouched, no HT/VAT, GL debit hits the signed-off variance/expense account (not 401); negative-stock guard rejects an over-return.
20. **PO revision after receipt (P1-3):** wholesale line replacement rejected once any line has `quantity_received>0` or `free_quantity_received>0`; targeted qty increase allowed; `quantity < quantity_received` / `free_quantity < free_quantity_received` rejected; line IDs preserved.

---

## 16. Phasing

**Phase 1 — demo-relevant (parapharmacy Tunisia):** schema migration (incl. `is_bonus_line`, `free_quantity_invoiced`); full gating stack (§10 steps 1-8, incl. existing-tenant reconcile); gated PO-line entry (paid/free/either-or); receiving with dual counters + two-movement WAC + **free-only receipts** (§6, P1-1) + **two batch movements** (§6.6, P2-1); **dual invoice-shape ingestion** (§9a, P0-1) incl. the `is_bonus_line` matcher/posting skip; **supplier credit-note bonus returns** (§12a, P1-2); **PO-revision-after-receipt invariants** (§12b, P1-3); printed gratuité mention; **buyer bonus KPIs** (§13, P2-2); FR translations. Same-product bonus only.

**Phase 2 — post-demo:** **cross-product bonus** (§2a — bonus child line + WAC-vs-bonus-income accountant decision + cross-product landed-cost rule); receiving/PO "gratuité en attente" indicator; per-supplier tier hints/automation; AR translation polish.

## 17. Non-goals (v1)

- **No supplier rebate/discount engine** — gratuities are not price discounts; the existing discount fields remain orthogonal (and are disabled in total-entry mode, where the net paid already embeds them).
- **No automatic tier suggestions** ("you ordered 50, supplier usually gives 3") — manual entry only; tier automation is future work on supplier agreements.
- No sales-side free goods / BOGO (POS promotion domain, entirely separate).
- No retroactive recosting of historical receipts.
- **No cross-product (different-SKU) bonus in v1** — explicit Phase 2 (§2a).
- **Scope of code touched (revised from Rev 1's "call site only"):** the design changes (1) the goods-receipt loop eligibility gate for free-only receipts (§6, P1-1), (2) an additive `is_bonus_line` skip in `SupplierInvoiceMatcher` + free-side counter, and the mirror in `SupplierInvoicePostingService` and the credit-note posting service (§9a, §12a), and (3) a PO-revision guard (§12b). It does **not** change the WAC service, the GR-IR poster, or the landed-cost allocator — those stay untouched because free units carry zero value. Rev 1's "no matcher/posting changes at all" was inaccurate for the free-line invoice shape; this is the honest boundary.

## 18. Open questions for the owner

1. **Display rounding of the effective cost:** ledger carries 4.761904 (scale 6, truncating `bcformat`); do you want the inline display half-up (4.762, your example) accepting a ≤1-millième display-vs-ledger delta, or truncated (4.761)? (Same question as the pending WAC truncation sign-off in the precision contract.)
2. **All-free lines:** may a PO line be 100% gratuité (paid qty 0 — e.g. a pure promotional shipment)? v1 requires `quantity > 0`; allowing 0 needs a zero-value line path through tax/match (doable, but is it real?).
3. **Discounts in total-entry mode:** confirm that "total paid" is the net figure and line discount fields should be hidden in that mode (v1 assumption).
4. **Bonus stock returns — GL debit account (now v1, §12a):** returning a free unit leaves stock at diluted WAC (a COGS/inventory reduction) against a zero-value credit note. The **credit** is Inventory (3x); the **debit** account needs your accountant's call — inventory-shrinkage/variance vs a "bonus reversal" expense. Confirm before build.
5. **Real invoice shapes (§9a, blocking):** please provide 2–3 real supplier invoices from the launch parapharmacy so we validate which shape(s) — paid-only (a) vs explicit 100%-discount gratuité line (b) — your suppliers actually issue, and whether any use the unsupported single-diluted-line third shape. The design supports (a) and (b); we should not code the matcher against an assumed invoice.
6. **Cross-product bonus (§2a):** do your suppliers ever grant a *different* product as the gratuité (buy X, get Y)? If common, we reprioritise the Phase 2 cross-product model and settle the WAC-vs-bonus-income treatment.

---

## Revision 2 — Codex review disposition

Every finding from `docs/superpowers/audits/2026-07-02-bonus-quantity-codex-review.md`. Verdict was **REVISE**; all findings are **accepted** (the core same-product WAC/GR-IR direction was affirmed by the reviewer). Code evidence is cited where a claim was independently re-verified.

| # | Finding | Disposition | Change in Rev 2 |
|---|---|---|---|
| **P0-1** | Supplier-invoice shape unsafe; a 100%-discount/free line breaks 3-way matching; "supplier's problem" too weak | **Accepted** | New **§9a dual invoice-shape contract**; §9 rewritten with TN Ministry of Finance citations; `is_bonus_line` column (§3) + `free_quantity_invoiced` counter; exact additive matcher skip (`SupplierInvoiceMatcher.php:263-345,182-190`) + posting skip; import/manual-entry rules; §2 thesis corrected from "zero matcher changes". Verified: a linked free line → aggregate `21>20` QuantityVariance; unlinked → null-line Exception. |
| **P0-2** | Same-product `free_quantity` can't model different-product bonuses | **Accepted (decisive: v1 same-product only)** | New **§2a**: cross-product excluded from v1, Phase 2 bonus-child-line shape sketched with the WAC-vs-bonus-income question named. Verified `DocumentLine` single `product_id` (`DocumentLine.php:19-31`), receiving resolves it (`GoodsReceiptService.php:129-147`). |
| **P1-1** | Free-only receipt specified but current loop skips zero-paid lines | **Accepted** | §6 rewritten: new loop eligibility gate `(paid>0 || free>0)`, `hasReceivedItems` for either, free-only path. Verified the `continue` at `GoodsReceiptService.php:113-115` precedes product/batch/WAC/event/counter. Test §15.16. |
| **P1-2** | Credit notes / returns of bonus stock can't be deferred | **Accepted (promoted to v1)** | New **§12a**: zero-value free returns at diluted WAC, no `quantity_invoiced` decrement, `free_quantity_invoiced` decrement, GL debit = variance/expense (accountant sign-off, OQ4). Verified `guardAndDecrementGoodsReturn` decrements `quantity_invoiced` (`SupplierCreditNotePostingService.php:373-410`) + cumulative-HT guard (`:333-370`); negative-stock hard-block (`adr/2026-06-30`). Test §15.19. |
| **P1-3** | PO revision after partial receipt dangerous (confirmed POs editable, lines replaced wholesale) | **Accepted** | New **§12b** invariants: no line replacement after any receipt, preserve line IDs, qty can't drop below received/invoiced, increases allowed. Verified `isEditable()` true for Confirmed (`DocumentStatus.php:19-24`) + delete/recreate lines (`PurchaseOrderController.php:336-340`). Test §15.20. |
| **P1-4** | Module-key plumbing under-specified / impossible as written | **Accepted** | §10 rewritten as 8-step checklist: add `ModuleName::PurchaseBonus`, correct config, `PurchaseBonusGate` backend authority, `purchase_bonus_enabled` in CompanyConfig, FE `hasModule && flag`, existing-tenant reconcile migration. Verified no `PurchaseBonus` case (`ModuleName.php:14-39`) and real parapharmacy extras `['Loyalty','Ecommerce','CompositeItems']` (`verticals.php:335-360`) — Rev 1's cited set was wrong. |
| **P1-5** | Total-mode recurring decimals: small cost-basis mismatch; spec overclaims exactness | **Accepted** | §5 precision-honesty paragraph: GL exact at 3 dp; WAC accurate to 6 dp with `<paid_qty×1e-6` residue. Worked 23.333/7 case. Test §15.15. |
| **P2-1** | Batch/expiry under-specified for two movements | **Accepted** | §6.6: two batch movements (free+paid), same batch when lot shared; aggregate = paid+free. Verified `receiveBatchStock` is per-movement and doesn't touch aggregate (`BatchStockService.php:169-183`). Test §15.17. |
| **P2-2** | Reporting below the vertical-differentiation bar | **Accepted (moved to Phase 1)** | §13 buyer bonus KPIs (effective cost, savings, free ordered/received/missing, supplier/month) promoted to Phase 1 with queries + acceptance tests. |
| **Review §4** | Industry table corrections (Odoo loyalty = sales-side; BC line-discount handling; SAP unverified) | **Accepted** | New **§13a** honest comparison: advantage = automatic WAC dilution + TN dual invoice-shape + Phase-1 KPIs; not breadth (cross-product deferred). |
| **Review §5.12** | Need real TN invoice fixtures before coding matcher/import | **Accepted** | Blocking action item in §9a + Open Question 5. |

**Nothing rejected.** The reviewer's affirmation that the same-product WAC/GR-IR core is sound (sequential `recordPurchase` introduces no extra rounding beyond existing 6 dp WAC truncation; GR-IR skips zero-amount movements) is retained as the design's foundation.
