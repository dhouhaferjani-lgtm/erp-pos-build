# W-4 purchasing & inventory defects (money campaign, 2026-08-03)

**Source:** wave W-4 of the pre-launch full-E2E money campaign
(`docs/qa/2026-08-02-full-e2e-campaign-plan.md`, brief
`.superpowers/sdd/2026-08-02-full-e2e-campaign-plan/wave-4-brief.md`).
**Discovered by:** live runs against the local stack (web :5173 / api :8010, tenant
`demo-pharmacy-tn`). No product code was changed; each item below is TRIPWIRED by a
spec assertion that pins today's behaviour and goes red when the fix lands.

Consolidated per the wave's hard rule 3 (one ticket file for the wave).

---

## #1 — P1: `ProportionalMoneyAllocator` truncates the PROPORTION before multiplying, so landed-cost shares drift by a millime

**Where:** `apps/api/app/Shared/Domain/ProportionalMoneyAllocator.php:56-58`
(used by `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php`
`allocateCosts()` / `allocateCostsAndTaxes()` / `reallocateCosts()`).

**Tripwire:** `apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts` — `MTP-PUR-17`.

### What happens

```php
$proportion = bcdiv($base, $subtotal, $workingScale);                   // TRUNCATED here
$share      = bcformatStrict(bcmul($formattedTotal, $proportion, $workingScale), $scale);
```

`workingScale` is `max(currencyScale + 4, COST_SCALE + 1)` = **7** for TND.

Freight `30.000` over line values `100.000` / `50.000`:

| step | value |
|---|---|
| `bcdiv(100.0000000, 150.0000000, 7)` | `0.6666666` (residue dropped) |
| `× 30.000` | `19.999998` |
| truncate to scale 3 | **`19.999`** |
| absorber (last positive line) gets the remainder | **`10.001`** |

Verified live (`document_lines`):

```
line 1 | line_total 100.000 | allocated_costs 19.999000 | landed_unit_cost 11.999900
line 2 | line_total  50.000 | allocated_costs 10.001000 | landed_unit_cost 12.000200
```

`MTP-PUR-17` in `docs/qa/2026-08-01-money-test-plan.md:637` specifies
`L1 = 30.000 × 100/150 = 20.000`, `L2 = 10.000`, `L1 landed unit cost = 12.000000`.

### Why it matters

- The **sum invariant still holds** (`19.999 + 10.001 == 30.000`), so this is not a
  money-creation bug — it is a **mis-attribution** bug.
- `landed_unit_cost` feeds the perpetual WAC via `recordPurchase()`, so the drift is
  capitalised into inventory value per product and then into COGS.
- The residue always lands on the LAST positive line (the "absorber"), so it is
  systematic, not random: one product is perpetually over-costed and another
  under-costed on every multi-line PO whose value split is not exactly representable.
- `MTP-PUR-18` (three equal lines, `10.000` pool → `3.333 / 3.333 / 3.334`) is
  unaffected and passes, which is why the largest-remainder logic has looked correct.
  `MTP-PUR-20` (two equal lines) is also exact. Only non-exactly-representable ratios
  drift — which is the common case in real purchasing.

### Suggested fix

Compute each share in ONE step instead of two, so nothing is truncated before the
multiplication:

```php
$share = bcformatStrict(bcdiv(bcmul($formattedTotal, $base, $workingScale), $subtotal, $workingScale), $scale);
```

`30.000 × 100.0000000 / 150.0000000 = 20.0000000 → 20.000`. Keep the absorber for the
genuine remainder. This is a one-line change with an existing unit-test surface
(`ProportionalMoneyAllocator` is a `Shared/Domain` class).

**When fixed:** `MTP-PUR-17` goes red; update it to `20.000 / 10.000 / 12.000000 /
12.000000`. Check whether any other consumer of `ProportionalMoneyAllocator` has a
baseline that encodes the drift.

---

## #2 — P1: `is_bonus_line` is dropped at the supplier-invoice HTTP boundary, making bonus receipts un-invoiceable

**Where:**
- `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:110-139`
  — `rules()` declares `source_line_id`, `product_id`, `variant_id`, `quantity`,
  `unit_price`, `vat_rate`, `batch.*` … but **no `lines.*.is_bonus_line`**.
- `apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:215,228`
  — passes `$request->validated()` to the service.
- `apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:115`
  — `'isBonusLine' => (bool) ($lineInput['is_bonus_line'] ?? $lineInput['isBonusLine'] ?? false)`.

Laravel's `validated()` returns only declared keys, so `is_bonus_line` can never be
`true` on the documented creation path.

**Tripwire:** `apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts` — `MTP-PUR-16`.

### Verified live

PO line qty `10` paid + `2` free, fully received (`free_quantity_received 2.0000`).
Supplier invoice posted with two lines against that PO line: `10 @ 10.000` and
`2 @ 10.000` with `is_bonus_line: true`.

```
document_lines (supplier invoice): line 1 qty 10.0000 is_bonus_line = f
                                   line 2 qty  2.0000 is_bonus_line = f   <-- flag dropped
match.per_line: [{matchable "10.0000"}, {matchable "10.0000"}]   <-- both against the PAID window
match_status: quantity_variance
POST /supplier-invoices/{id}/post -> 422
```

### Why it matters

- The whole bonus arm of `SupplierInvoiceMatcher::buildQtyGroupStatuses()`
  (`SupplierInvoiceMatcher.php` ~L295-445) and `ReceiptPlanner::freeMatchableQty()` is
  **unreachable from the API**. That is a large slice of dead code that looks tested.
- A bonus receipt **cannot be invoiced at all**: the free units are counted against the
  paid matchable window, producing `quantity_variance`, which is a HARD block at post
  under *both* `match_enforcement` modes. In a TN pharmacy where bonus/free goods are
  routine (`procurement.bonus_quantity_countries = ['TN']`, and the whole
  `PurchaseBonusGate` exists for this), this blocks a normal supplier-invoice flow.
- `MTP-PUR-14` (WAC blend over paid+free = `8.333333`) passes, so the RECEIPT side is
  correct. Only the invoicing side is broken.

### Suggested fix

Add to `CreateSupplierInvoiceRequest::rules()`:

```php
'lines.*.is_bonus_line' => ['nullable', 'boolean'],
```

(and gate it on `PurchaseBonusGate` the same way `CreateDocumentRequest.php:118-131`
does for purchase orders, so a non-allowlisted country gets `prohibited`).

**When fixed:** `MTP-PUR-16` goes red; rewrite it to the plan's intended assertion —
bonus line matched against `free_matchable_qty` only, no price check, `match_status`
`matched`, post succeeds.

---

## #3 — P1: an RFQ-awarded purchase order carries NO tax rate, so its VAT is silently zero

**Where:**
- `apps/api/app/Modules/Procurement/Presentation/Requests/CreatePurchaseQuoteRequestRequest.php`
  and `UpdatePurchaseQuoteRequestRequest.php` — neither declares a `tax_rate` field, so
  an RFQ line can never carry one.
- `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/PurchaseQuoteRequestToPurchaseOrderConverter.php`
  — copies the untaxed line straight through.

**Tripwire:** `apps/web/e2e/money-campaign/purchasing-rfq.spec.ts` — `MTP-RFQ-06`.

### Verified live

RFQ quoted at `10 × 12.500`, awarded via `POST /purchase-quote-requests/{id}/convert-to-po`:

```
PO line: quantity 10.0000  unit_price 12.500  tax_rate NULL  line_total 117.500/125.000
PO (draft):     subtotal 125.000  tax_amount 0.000  total 125.000
PO (confirmed): subtotal 125.000  tax_amount 0.000  total 125.000
```

A hand-authored PO with the same line at `tax_rate 19.00` gives `tax_amount 23.750`,
`total 148.750` (`MTP-PUR-01`). Confirm is where PO taxes are calculated and snapshotted
(`PurchaseOrderService::confirmAndAllocateCosts()`), and it cannot invent a rate that
was never captured.

### Why it matters

- The committed payable is understated by the full VAT (~19% on this tenant).
- Three-way matching then compares an **untaxed** PO against a **taxed** supplier
  invoice.
- `LandedCostService::allocateCostsAndTaxes()` computes the non-recoverable-tax
  allocation from `$taxResult`; with no rate there is nothing to capitalise, so a
  non-VAT-registered company would under-cost its inventory.
- Contrast: the **replenishment** converter DOES stamp `19.00`
  (`MTP-REP-03` asserts it), so this is an RFQ-path-specific gap, not a platform-wide
  design choice.

### Suggested fix

Capture a `tax_rate` on RFQ lines (default from the product/company tax configuration)
and carry it through the converter; or have the converter resolve the default rate when
the RFQ line has none.

**When fixed:** `MTP-RFQ-06` goes red; update it to `tax_amount 23.750` / `total
148.750`.

---

## Recorded behaviours (NOT defects — pinned so a future change is deliberate)

| # | Behaviour | Pinned by |
|---|---|---|
| R-1 | Partner balance is **GL-derived**; an AR/AP opening writes no journal entry (by design — the preview's own note says "GL balance should be handled via Accounting Opening"), so the balance reads `0.000` while `/partners/{id}/open-invoices` correctly shows the outstanding legacy items. Even an explicit `balance/refresh` does not change it. | `MTP-OPB-02`, `MTP-OPB-03` |
| R-2 | An **unbalanced** GL (ACCOUNTING) opening is NOT refused — the residual is plugged to *Opening Balance Equity* by exactly the imbalance, and the posted entry is balanced. | `MTP-OPB-04` |
| R-3 | Partner balances are **credit-signed** for suppliers (`-148.750` for a payable). | `MTP-PUR-28` |
| R-4 | Landed costs are allocated at PO **confirm** and re-allocated at **receipt**; a cost added after confirm leaves `allocated_costs` at `0.000000` until something re-allocates. | `w4-support.ts` `poAndReceive()`, `MTP-PUR-17/19/20` |
| R-5 | Opening-balance batches: at most ONE **unlocked** batch per type per company; DRAFT is deletable, VALIDATED is not (it must be LOCKED). Posting does NOT lock. | `MTP-OPB-06`, `clearOpeningBatchSlot()` |
| R-6 | `requires_count_2` defaults to **true**; a single-counter inventory count never reaches `pending_review` (and never produces a report) unless it is created with `requires_count_2: false`. | `inventory-counting.spec.ts` `startCounting()` |
| R-7 | RFQ group **reopen** is refused while the awarded PO is still live (`has_live_purchase_order`), not merely "was awarded once". The recovery is to retire the PO first. | `MTP-RFQ-05` |
| R-8 | `POST /partners` leaves `code` NULL unless supplied; the AR/AP opening importer keys on `partner_code`, so an API-created partner is un-importable without an explicit code. | `opening-balances-types.spec.ts` `createCodedPartner()` |
| R-9 | Documented gaps, re-confirmed: the movements ledger, the entry/exit-note read model, and the expiry write-off page carry **no money columns**. The write-off VALUE is only reachable from the mutation response (`unit_cost` / `total_cost`) or the movement row. | `MTP-INV-08`, `MTP-INV-21`, `MTP-INV-27` |
