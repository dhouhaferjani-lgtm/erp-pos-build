# 1. Verdict

**REVISE.** The same-product WAC/GR-IR core is directionally good: the real matcher computes `quantity_received - quantity_invoiced` in paid units, GR-IR skips zero-amount movements, and sequential `recordPurchase()` calls do not introduce a second effective-cost rounding step beyond the existing 6 dp WAC persistence. But the spec is not ready to implement as written. Its highest-risk assumption is fiscal/document-shape: it assumes the supplier invoice bills only paid quantity and treats the supplier's VAT treatment as external, while actual Tunisian invoice obligations require designation, HT price, VAT rates, and VAT amounts on the invoice, and real suppliers may show free units as 100%-discount/remise lines. That invoice shape currently breaks `SupplierInvoiceMatcher`. The spec also under-specifies free-only receipts, different-product bonuses, supplier credit notes/returns, PO revision after partial receipt, batch movement shape, and module-key plumbing.

# 2. Ranked findings

## P0-1: Supplier invoice shape is the unsafe assumption; a 100%-discount/free line breaks 3-way matching

**Description.** The spec assumes the supplier invoice bills paid units only and says the supplier's VAT treatment is "the supplier's problem." That is too weak for an expert-comptable-clean purchase system. If a Tunisian supplier prints the free units on the invoice as a zero-value/100%-discount "remise en nature" line, the current matcher sees those units as invoice quantity and hard-fails.

**Evidence.**

- Spec quote: "The supplier's own VAT treatment of gratuities (Tunisia practice: free goods ride the commercial invoice with zero line value) is the supplier's problem; we record what the invoice states." (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:191`)
- Spec quote: "Supplier invoice line: 20 @ 5.000 -> `matchableQty = 20 - 0 = 20` ... Status green with zero matcher changes." (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:228-230`)
- Code: `SupplierInvoiceMatcher::matchableQty()` is strictly `quantity_received - quantity_invoiced` (`apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:141-149`), and `buildQtyGroupStatuses()` sums all supplier-invoice line quantities per `source_line_id` before comparing against matchable (`apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:263-330`).
- Code: unlinked supplier-invoice lines are a hard exception (`apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:269-273`, `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:182-190`).
- Project/external fiscal grounding: the project already cites Tunisian Ministry of Finance invoice obligations in prior review notes (`docs/superpowers/reviews/2026-05-13-pos-fiscal-event-engine-codex-review-round3.md:68-70`). The Ministry page says VAT taxpayers must use numbered invoices and mention goods/services, HT prices, VAT rates, and VAT amounts (https://www.finances.gov.tn/fr/node/75, lines 106-114; https://www.finances.gov.tn/fr/node/952, lines 106-114).
- External fiscal knowledge, not verified against project docs: in VAT practice, supplier-granted commercial discounts normally reduce the taxable base when shown on invoice, while gifts/free samples can have different self-supply rules. I did not find a project doc proving that Tunisian parapharmacy supplier gratuities may be omitted from the invoice quantity or always appear only as paid quantity.

**Impact.** A real invoice with lines `20 @ 5.000` plus `1 @ 5.000, 100% discount`, or a single printed quantity of `21` with a discount, makes the aggregate invoiced quantity `21.0000` against matchable `20.0000`, causing `QuantityVariance`. If the free line is left unlinked to avoid over-clear, posting is blocked as an exception. This is a P0 because the feature can fail on the exact legal/commercial document the accountant enters.

**Recommended fix.** Add a mandatory fiscal/invoice-shape section before implementation:

- Validate Tunisian supplier invoice practice with an accountant and sample supplier invoices.
- Support at least two invoice representation modes:
  1. paid-only invoice lines, current no-matcher-change path;
  2. free/100%-discount invoice lines marked as non-matchable bonus quantity, excluded from `aggregateInvoicedQtyPerPoLine()` and from price checks while still stored/printed/audited.
- Do not state "supplier's problem" in the spec. State the accepted fiscal evidence and the exact import/manual-entry behavior for invoices that show free lines.

## P0-2: Same-product `free_quantity` cannot model different-product bonuses, which are common enough to design for

**Description.** The schema attaches `free_quantity` to one paid line and defines total physical quantity as `quantity + free_quantity` of the same `product_id`. That cannot model "buy 20 of X, get 2 of Y" without abusing the paid line or adding a zero-value document line, an alternative the spec rejects.

**Evidence.**

- Spec quote: "`quantity` is the paid quantity... Total physical = `quantity + free_quantity`." (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:62`)
- Spec quote: "Zero-price document sub-line" is rejected because it "pollutes the document layer" and creates matcher issues (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:39`).
- Code: `DocumentLine` has a single `product_id` and `variant_id` per line (`apps/api/app/Modules/Document/Domain/DocumentLine.php:19-31`, `apps/api/app/Modules/Document/Domain/DocumentLine.php:72-86`).
- Code: receipt processing resolves exactly the line's `product_id` before calling `recordPurchase()` (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:129-147`, `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:167-176`).

**Impact.** The representation is vertically incomplete if Tunisian parapharmacy suppliers grant cross-SKU bonuses, samples, or substitution-style deals. It also blocks reporting by awarded product and cannot preserve batch/expiry for the awarded SKU.

**Recommended fix.** Extend the spec with a bonus-child line model instead of overloading same-line `free_quantity` as the only representation. Keep same-product `free_quantity` as the fast path, but add a gated `bonus_source_line_id`/`bonus_kind` zero-value physical line that is explicitly non-matchable for AP and visible for receiving/batch/reporting.

## P1-1: Free-only partial receipt is specified, but the current receipt loop skips lines with zero paid receipt

**Description.** The spec says paid and free receipts are independent and even includes a "partial free-only delivery" test. Current `GoodsReceiptService` exits the line before product lookup, batch validation, WAC, event emission, and counters when paid `qtyToReceive <= 0`.

**Evidence.**

- Spec quote: "Paid and free receipts are independent -- the supplier may ship the gratuite in a later delivery." (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:129`)
- Spec quote: backend test 4 requires "partial free-only delivery updates only `free_quantity_received`." (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:261`)
- Code: `qtyToReceive` defaults to zero and the loop `continue`s immediately when it is `<= 0` (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:109-115`).
- Code: the WAC movement and `GoodsReceived` event happen only after that paid-quantity guard (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:164-191`).

**Impact.** The proposed request shape cannot work for free-only receipts without restructuring the service. This is not "zero changes except call site"; it changes line eligibility, `hasReceivedItems`, batch validation, and status calculation.

**Recommended fix.** Rewrite section 6 to define the new loop condition as `(paidQtyToReceive > 0 || freeQtyToReceive > 0)`, run product/batch validation for either path, and set `hasReceivedItems` for either paid or free movements. Add a test where `received_quantities[line_id] = "0.0000"` and `free_quantities[line_id] > 0`.

## P1-2: Supplier credit notes and returns cannot be deferred if bonus stock can be returned

**Description.** The spec defers supplier credit-note and purchase-return interplay, but the code already has a supplier credit-note posting path with a goods-return quantity ledger. That ledger is in paid PO-line units only. Returning free units physically but receiving a zero-value credit note has no representation.

**Evidence.**

- Spec quote: Phase 2 defers "supplier credit-note & purchase-return interplay for bonus stock" (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:281`).
- Spec quote: open question 4 says bonus returns are only to be confirmed later (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:294-296`).
- Code: supplier credit notes can be `GoodsReturn`, and that reason decrements `quantity_invoiced` (`apps/api/app/Modules/Procurement/Domain/Enums/SupplierCreditNoteReason.php:13-20`, `apps/api/app/Modules/Procurement/Domain/Enums/SupplierCreditNoteReason.php:30-35`).
- Code: `SupplierCreditNotePostingService::guardAndDecrementGoodsReturn()` decrements only `quantity_invoiced` and rejects if it would go below zero (`apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:373-410`).
- Code: the service bounds cumulative credited HT against the invoice subtotal (`apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:333-370`).
- ADR: negative stock is hard-blocked and no back-valuation engine exists (`docs/adr/2026-06-30-negative-stock-hard-block.md:35-49`).

**Impact.** A supplier return of one free unit should reduce physical stock and batch stock but should not reopen paid `quantity_invoiced` or create HT. The current credit-note service cannot express that. Deferring it means the feature is incomplete for normal dispute/return workflows and can push operators to manual stock adjustments outside AP audit trails.

**Recommended fix.** Add a v1 rule for supplier returns of bonus stock:

- zero-value supplier credit note / supplier return line with `returned_free_quantity`;
- stock issue at current diluted WAC, respecting negative-stock/batch availability;
- no decrement to `quantity_invoiced`;
- explicit GL treatment signed off by accounting (inventory credit vs variance/expense).

## P1-3: PO revision after partial receipt is dangerous because confirmed POs are editable and line updates replace all lines

**Description.** The spec does not define how changing `free_quantity` works after partial receipt. Current purchase order updates allow confirmed documents to be edited and replace all lines when `lines` is supplied. That can erase line IDs and receipt counters unless guarded.

**Evidence.**

- Spec quote: receiving counters are additive: `quantity_received += paidQty`; `free_quantity_received += freeQty` (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:134`).
- Spec quote: migration adds counters but no revision invariant (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:247-249`).
- Code: `DocumentStatus::isEditable()` returns true for both `Draft` and `Confirmed` (`apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php:19-24`), and `Document::isEditable()` delegates to that (`apps/api/app/Modules/Document/Domain/Document.php:481-483`).
- Code: `PurchaseOrderController::update()` permits editable documents (`apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:303-309`) and deletes/recreates all lines when lines are provided (`apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:336-340`).

**Impact.** After partial receipt, changing a bonus quantity, paid quantity, or line set can orphan supplier invoice links, reset counters on new line IDs, or make `quantity_received/free_quantity_received` exceed revised ordered quantities. This is a data-integrity gap independent of the new feature, but bonus counters make it worse.

**Recommended fix.** Add explicit revision rules:

- block line replacement on a PO after any paid or free receipt;
- allow only quantity increases through a controlled revision object, or require cancel/recreate;
- validate `free_quantity >= free_quantity_received` and `quantity >= quantity_received`;
- preserve line IDs for accounting/matching continuity.

## P1-4: Module key plumbing is underspecified and currently impossible without enum/config drift fixes

**Description.** The spec says add `PurchaseBonus` to config and use two-layer gating, but the current canonical module enum does not contain that case, and parapharmacy's compatible extras differ from the spec's quoted lines.

**Evidence.**

- Spec quote: "Module key: new `PurchaseBonus` module key. Add to parapharmacy `default_modules`..." (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:200`)
- Gating doc: module names must match a `ModuleName` case exactly (`docs/architecture/vertical-module-gating.md:141-144`), and the source of truth is `config/verticals.php` plus enums/services (`docs/architecture/vertical-module-gating.md:93-103`).
- Code: `ModuleName` has no `PurchaseBonus` case; it ends with `Merchandising` (`apps/api/app/Enums/ModuleName.php:14-39`).
- Code: current parapharmacy `compatible_extras` is `['Loyalty', 'Ecommerce', 'CompositeItems']`, and defaults already include `BatchExpiry`, `Parapharmacy`, and `Merchandising` (`apps/api/config/verticals.php:335-360`), not the exact set cited by the spec.
- Code: backend middleware alias exists as `module` (`apps/api/bootstrap/app.php:45-53`), and frontend `hasModule()` reads `all_enabled_modules` from `/company/config` (`apps/web/src/contexts/CompanyConfigContext.tsx:64-75`).

**Impact.** Implementers can add config strings that fail drift guards or frontend/backend checks if they do not update `ModuleName`, tests, CompanyConfig, and vertical overrides together. The spec's country gate also needs a backend authority helper; frontend `hasModule('PurchaseBonus')` alone cannot include country eligibility.

**Recommended fix.** Add a "gating implementation checklist" to the spec: add `ModuleName::PurchaseBonus`, update vertical config and module drift tests, add a backend `PurchaseBonusGate`/policy that checks module and company country, expose `purchase_bonus_enabled` in company config, and use `hasModule && purchase_bonus_enabled` on the UI.

## P1-5: Total-mode recurring decimals introduce a small cost-basis mismatch that the spec should quantify

**Description.** The spec says total-mode materializes `landed_unit_cost` at 6 dp to avoid drift. That mostly protects GR-IR at 3 dp, but it still means the WAC movement value can be a few millionths below the authoritative `line_total` when the total is not divisible by paid quantity.

**Evidence.**

- Spec quote: total mode stores `line_total` verbatim and materializes `landed_unit_cost` at cost scale 6 (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:106-110`).
- Code: `LandedCostService::landedUnitCost()` divides at working scale and persists 6 dp (`apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:395-419`).
- Code: `recordPurchase()` formats unit cost at working scale, computes `quantity * cost`, then persists movement totals and WAC at 6 dp (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:220-248`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:256-274`).
- Code: GR-IR rounds the movement amount half-up at the posting boundary (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1071-1079`), and supplier invoice clearing rounds `accruedHt` half-up too (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1202-1214`).

**Impact.** For `line_total = 23.333`, paid quantity `7`, `landed_unit_cost = 3.333285`, the WAC movement value is `23.332995` while GR-IR posts `23.333`. The mismatch is below 0.001 TND and clears at GL scale, but the spec currently overclaims exactness. Stock valuation reports that multiply `qty * product.cost_price` at 6 dp can show tiny sub-millime differences before display rounding.

**Recommended fix.** Amend the precision section to state the exact invariant: GL clears at currency scale; WAC/movement cost is accurate to 6 dp and may differ from `line_total` by less than paid quantity * 1e-6 plus WAC truncation. Add the recurring-decimal test from section 3 below.

## P2-1: Batch/expiry handling is underspecified for two movements and FEFO traceability

**Description.** The spec says free units share paid delivery batch data, but the actual batch service creates a separate batch movement per aggregate movement and does not touch aggregate stock itself. With two WAC movements, the spec must define whether batch stock receives one combined quantity or two movement-linked records.

**Evidence.**

- Spec quote: "Batch tracking: free units share the paid delivery's `batchData`" (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:135`).
- Code: goods receipt creates one WAC movement, then one `receiveBatchStock()` linked to that movement (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:193-214`).
- Code: `BatchStockService::receiveBatchStock()` creates a `BatchMovement` and explicitly does not touch aggregate stock (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:169-183`).
- ADR: parapharmacy launch SKUs are batch/expiry tracked and negative stock/batch-less sales are disallowed (`docs/adr/2026-06-30-negative-stock-hard-block.md:10-13`, `docs/adr/2026-06-30-negative-stock-hard-block.md:28-33`).

**Impact.** If the implementation records only paid batch quantity, FEFO availability will be short by the free units. If it records a single combined batch movement tied only to the paid movement, audit cannot reconcile free movement stock to batch movement. If it records two batch movements, the UI/reporting must tolerate two movements for one line.

**Recommended fix.** Specify two batch movements, one linked to the free stock movement and one linked to the paid movement, both pointing at the same batch when batch data is shared. Add a batch-stock assertion that aggregate batch quantity equals paid + free.

## P2-2: Reporting is below the owner's bar for vertical differentiation

**Description.** The spec says purchase analytics are a Phase-2 nicety, but the owner bar explicitly asks to outperform generic ERPs via vertical customization. A purchasing manager will expect bonus savings, effective cost after bonus, and supplier bonus performance immediately.

**Evidence.**

- Spec quote: the PO form shows "Coût unitaire effectif" and "Économie gratuité" (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:80-89`).
- Spec quote: "Purchase analytics: PO detail and receiving screens show `free_quantity` / `free_quantity_received`; a 'gratuités reçues' measure is a Phase-2 nicety." (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:240`)
- Code: WAC updates `product.cost_price` and `last_purchase_cost`, but there is no persisted savings/supplier-bonus metric in the proposed schema (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:284-292`).

**Impact.** The implementation would be mechanically correct but not strongly vertical. Buyers cannot answer "which supplier actually gave us the best net cost?" or "how much free stock did we receive/miss this month?" without manual spreadsheet work.

**Recommended fix.** Move buyer-facing reporting into Phase 1: per-line effective unit cost, bonus savings amount, bonus received/missing, supplier/month bonus value, and same-vs-different-product bonus counts. Persisting computed facts is optional; the spec must define the report queries and acceptance tests.

# 3. Worked numeric cases

The actual scale is TND currency scale 3 (`apps/api/app/Shared/Domain/CurrencyScale.php:20-29`), WAC cost scale 6 (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:32-43`), and WAC working scale `max(scale + 4, COST_SCALE + 1) = 7` (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:63-75`). `CurrencyScale::bcformat()` truncates/normalizes with `bcadd(..., scale)` (`apps/api/app/Shared/Domain/CurrencyScale.php:85-109`).

## Case A: 20 paid + 1 free @ 5.000

Fresh product, free-first as the spec requires.

| Step | company qty before | current cost | qty in | unit cost at working scale | current value | new qty | new value | persisted WAC |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Free | 0.0000 | 0.0000000 | 1.0000 | 0.0000000 | 0.0000000 | 1.0000 | 0.0000000 | 0.000000 |
| Paid | 1.0000 | 0.0000000 | 20.0000 | 5.0000000 | 0.0000000 | 21.0000 | 100.0000000 | `bcdiv(100.0000000, 21.0000, 7) = 4.7619047 -> 4.761904` |

Movement total cost: free `0.000000`, paid `100.000000`. Product WAC * quantity at 7 dp is `21.0000 * 4.761904 = 99.9999840`. That is the normal 6 dp WAC truncation residue, not extra drift from the two sequential calls. GR-IR posts the paid movement as `bcround(5.000000 * 20.0000, 3) = 100.000` and skips the free movement because amount is `0.000` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1071-1079`).

## Case B: 7 paid + 3 free @ 10.000 / 3

There are two meaningful variants because the current document model cannot store an exact recurring unit price in `unit_price` scale 3.

### B1: Ideal cost input carried to WAC as `bcdiv("10.000", "3", 7) -> 3.3333333`, stored at cost scale 6 as `3.333333`

| Step | company qty before | current cost | qty in | unit cost at working scale | current value | new qty | new value | persisted WAC |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Free | 0.0000 | 0.0000000 | 3.0000 | 0.0000000 | 0.0000000 | 3.0000 | 0.0000000 | 0.000000 |
| Paid | 3.0000 | 0.0000000 | 7.0000 | 3.3333330 | 0.0000000 | 10.0000 | 23.3333310 | `bcdiv(23.3333310, 10.0000, 7) = 2.3333331 -> 2.333333` |

No sequential-call drift beyond cost-scale truncation. The ideal mathematical paid value is `23.333333...`; the cost-scale input makes the movement value `23.333331`, and WAC * quantity is `23.3333300`.

### B2: Current total-mode document path with authoritative `line_total = 23.333`

`landed_unit_cost = bcdiv(23.333, 7.0000, 7) = 3.3332857 -> 3.333285` at cost scale 6.

| Step | company qty before | current cost | qty in | unit cost at working scale | current value | new qty | new value | persisted WAC |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Free | 0.0000 | 0.0000000 | 3.0000 | 0.0000000 | 0.0000000 | 3.0000 | 0.0000000 | 0.000000 |
| Paid | 3.0000 | 0.0000000 | 7.0000 | 3.3332850 | 0.0000000 | 10.0000 | 23.3329950 | `bcdiv(23.3329950, 10.0000, 7) = 2.3332995 -> 2.333299` |

GR-IR still posts/clears at `23.333` because both receipt and supplier-invoice clearing round half-up to currency scale (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1071-1079`, `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1202-1214`). But the WAC subledger carries `23.332995` movement value and `23.3329900` as `10 * persisted WAC`. This should be documented as accepted sub-millime precision behavior, not described as exact.

# 4. Industry/vertical-gap comparison

| System | Free-goods representation | VAT treatment | Reporting: effective cost / supplier savings |
|---|---|---|---|
| Odoo | Sales promotions support a "Free Product" reward with quantity and product; vendor pricelists model vendor, minimum quantity, and unit price, not a purchase bonus quantity field in the standard purchase pricelist docs. Sources: Odoo discount/loyalty docs, reward type "Free Product" (https://www.odoo.com/documentation/18.0/applications/sales/sales/products_prices/loyalty_discount.html, lines 1976-1985); Odoo vendor pricelist common fields (https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/purchase/products/pricelist.html, lines 1944-1987). | Tax follows invoice/order lines and fiscal positions; the cited docs do not prove a Tunisia purchase-gratuity flow. | Standard docs focus on promotions/pricelists, not parapharmacy supplier bonus savings. This spec is stronger if it adds supplier bonus analytics; as written, reporting is mostly Phase 2. |
| SAP Business One | Public source located for this review only confirms SAP B1 has purchasing/AP and inventory modules, not a verified official free-goods purchase representation. In practice, SAP B1 implementations commonly use zero-price/discount lines or add-ons for bonus schemes, but that is external industry knowledge not verified against official SAP docs in this pass. Source for module scope only: https://en.wikipedia.org/wiki/SAP_Business_One. | Not verified from official SAP B1 docs in this pass. | Generic ERP reporting depends on configuration/add-ons. The spec can outperform only if it makes bonus savings/effective cost first-class. |
| Microsoft Dynamics 365 Business Central | Official docs model purchase price/discount agreements, line discounts, invoice discounts, and best-price calculation based on vendor/item/minimum quantity; no first-class purchase bonus quantity in the cited page. Source: https://learn.microsoft.com/en-us/dynamics365/business-central/purchasing-how-record-purchase-price-discount-payment-agreements, lines 35-43 and 124-140. | Discounts may be posted separately or subtracted depending on setup (same source, lines 97-108). | Strong on price/discount agreements; no cited free-goods savings report. This spec is stronger for parapharmacy if it adds explicit bonus KPIs. |
| This spec | Same-product bonus is `free_quantity` on the paid line; WAC diluted by separate zero-cost receipt movement. Different-product bonus is not covered. | VAT blind to `free_quantity`; assumes paid-only invoice shape and treats supplier gratuity VAT treatment as external (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:187-192`). | Shows effective cost and savings inline, but postpones aggregate purchase analytics (`docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md:236-241`). |

# 5. Concrete spec changes required before implementation

1. Replace the §9 sentence "the supplier's problem" with an accountant-approved Tunisia invoice-shape matrix. Include paid-only, same-line discount, separate 100%-discount free line, and different-product free line.

2. Add a non-matchable bonus invoice-line concept or equivalent matcher rule for supplier invoices that explicitly show free units. Define how it affects `aggregateInvoicedQtyPerPoLine()`, price checks, VAT, PDF/import, and supplier credit notes.

3. Extend the data model to support different-product bonuses. Keep `free_quantity` for same-product bonuses, but add a bonus child-line model (`bonus_source_line_id`, `bonus_match_policy`, zero value) for product Y awarded from product X.

4. Rewrite §6 receiving pseudocode so a line is processed when either paid or free quantity is received. Update `hasReceivedItems`, batch validation, WAC movement ordering, and receipt status rules accordingly.

5. Specify batch behavior as two batch movements when there are two stock movements, both tied to the same batch if the physical lot is shared.

6. Add a v1 supplier return/credit-note design for bonus stock. Define zero-value free-unit returns, paid-unit returns, mixed returns, GL treatment, and negative-stock/batch guards.

7. Add PO revision invariants after receipt: no line replacement after any receipt; ordered paid/free quantities cannot be reduced below received counters; preserve line IDs.

8. Correct the gating section: add `ModuleName::PurchaseBonus`, update `config/verticals.php`, drift tests, CompanyConfig payload (`purchase_bonus_enabled`), backend gate helper, and frontend gate (`hasModule && purchase_bonus_enabled`).

9. Amend the precision section with the recurring-decimal case above. State that GL is exact at currency scale, while WAC movement/product valuation is held at cost scale 6 with possible sub-millime residue.

10. Move purchase-manager reporting into Phase 1: effective unit cost after bonus, free quantity ordered/received/missing, savings by supplier/product/month, and same-product vs different-product bonuses.

11. Add explicit landed-cost tests for different-product bonus lines once that model exists. Same-product landed-cost dilution through the paid line is sound, but cross-product allocation needs its own rule.

12. Add acceptance fixtures from real Tunisian parapharmacy supplier invoices before coding the matcher/import behavior. Without those, the spec is optimizing for an assumed invoice, not the market.
