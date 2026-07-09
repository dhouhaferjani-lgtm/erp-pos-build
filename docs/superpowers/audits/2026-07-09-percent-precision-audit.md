# Percent / Float Precision Audit — full inventory (2026-07-09)

**Trigger:** VAT rendered as `19.0000%` on the product form. Owner asked for (1) a full inventory of every location the problem occurs, (2) a proper fix.
**Method:** 4 parallel read-only auditors — backend (Sonnet), web (Sonnet), POS device (Haiku), repo-wide mechanical net (Haiku). All findings code-verified with `file:line`. Raw net output also at `scratchpad/precision_audit.txt`.

## The contract (CLAUDE.md rule 19 / `docs/architecture/precision-contract.md`)
Money = currency-scaled decimal (TND=3 / EUR=2), quantity = `decimal(N,4)`, **percents = fixed 2 decimal places** — percents are NOT currency/quantity-scaled. Everything is bcmath / `Big.js` **strings**; a float must never touch money, quantity, or a percent.

## ⚠️ Rev 2 corrections (Codex pre-dispatch review — `2026-07-09-precision-plan-codex-review.md`)
- **`eco_tax_rate` (DocumentLine + ReceiptLine) is a FRACTION, not a percent-value** — KEEP `decimal(N,4)`; do NOT narrow (rows below corrected).
- **POS receipt/Z-report `tax_rate` feed the fiscal HASH** (`ReceiptHashService.php:117`, `V3ReceiptHashComputer.php:104`, `zReportHashService.ts:119`) — NOT presentation-only; changes require golden fixture parity, gated last.
- **`services.base_price` is polymorphic (percent OR money)** — add to the Coupon/Promotion polymorphic set (was missed).
- **Every percent-column narrowing needs a tenant preflight** (`rate != round(rate,2)`); validators/writers first. **`formatPercentage` (`format.ts:186`) is NOT a safe formatter** (parseFloat + fixed 2dp) — build a string-safe one.

## ⚠️ Classification rule (READ FIRST — do not blind-fix)
A flagged column is one of three kinds; the fix differs:
- **Percent-VALUE** (stores `19.00`, `30.00`) → must be `decimal(N,2)` + cast `decimal:2`. Most tax_rate / margin / percentage_rate columns.
- **Fraction** (stores `0.0500` to mean 5%) → `decimal(N,4)` is CORRECT (4 fractional digits = 2dp of percent). Do NOT reduce scale. The bug on these is the **float display** (`getRateAsPercentage(): float`), not the column. Confirmed case: `withholding_rate` (code does `bcmul(rate,'100',2)`).
- **Money/amount** → currency scale (3), not a percent concern.
Verify each column's semantic before changing its scale. **DB scale changes run under `tenants:migrate` over existing tenant data — assess data-safety per column.**

---

## ROOT FIX 1 — Backend percent columns/casts stored at >2dp
**Root cause (cascading):** the two "widen monetary columns to scale 3/residual" migrations swept up *percent* columns, which then became the cited "precedent" for new tables created at 3–4dp. One corrective pass closes most of these.

| file:line | field | DB (migration) | kind | fix |
|---|---|---|---|---|
| `Taxation/Domain/Entities/TaxConfiguration.php:60` | `percentage_rate` cast `decimal:4` | `decimal(5,2)` (`2025_12_30_100000…:23`) | percent-value | **cast → `decimal:2`** (col already 2dp) — THE SEED BUG; reaches `TaxConfigurationResource:27` |
| `Service/Domain/Service.php:102` | `tax_rate` `decimal:3` | `decimal(6,3)` (`2026_05_29_100000…:46`) | percent-value | cast `decimal:2` + revert col `decimal(5,2)` |
| `Workshop/Bundle/Domain/ServiceBundle.php:91` | `tax_rate` `decimal:3` | `decimal(6,3)` (`2026_04_19_110001…:27`) | percent-value | cast `decimal:2` + col `decimal(5,2)` |
| `Workshop/WorkOrder/Domain/WorkOrderLine.php:129` | `tax_rate` `decimal:3` | `decimal(6,3)` (`2026_04_19_130002…:53`) | percent-value | cast `decimal:2` + col `decimal(5,2)` |
| `Product/Domain/Product.php:166,167` | `target_margin_override`, `minimum_margin_override` `decimal:3` | `decimal(5,3)` (`2026_03_11_200000…:38-39`) | percent-value | cast `decimal:2` + col `decimal(5,2)` — inconsistent with the CORRECT twin `Category.php:94-95` (`decimal:2`) |
| `Document/Domain/DocumentLine.php:164` | `eco_tax_rate` `decimal:4` | `decimal(8,4)` (`2026_05_01_000005…:28`) | **FRACTION (Codex-confirmed, `DocumentLine.php:54`)** | **KEEP `decimal:4` — do NOT narrow** |
| `POS/Domain/ReceiptLine.php:125` | `eco_tax_rate` `decimal:4` | `decimal(8,4)` (`2026_05_01_000004…:28`) | **FRACTION + FISCAL** (`ReceiptLine.php:48`) | KEEP scale; POS receipt tax feeds the hash → presentation only + fixture parity |
| `Taxation/…/WithholdingCertificate.php:100` | `withholding_rate` `decimal:4` | `decimal(5,4)` (`2026_01_08_172147…:36`) | **FRACTION** | KEEP col scale; fix the float display (Root Fix 3) |
| `Taxation/…/WithholdingTaxRule.php:66` | `rate` `decimal:4` | `decimal(5,4)` (`2026_01_08_172123…:32`) | **FRACTION** | KEEP scale; display fix only |
| `Taxation/…/SalesWithholdingTracking` | `withholding_rate` (no cast) | `decimal(5,4)` (`2026_01_09_111456…:28`) | **FRACTION** | KEEP scale; format at display |
| `Coupon/Domain/Entities/Coupon.php:106` | `discount_value` `decimal:4` | `decimal(12,4)` (`2026_03_02_200003…:26`) | **POLYMORPHIC** percent OR money | architectural: split into `discount_percent decimal(5,2)` / `discount_amount decimal(N,3)` by `discount_type` — scale patch alone can't fix |
| `Promotion/Domain/Entities/Promotion.php:114` | `discount_value` `decimal:4` | `decimal(12,4)` (`2026_03_02_200000…:31`) | **POLYMORPHIC** | same as Coupon |

## ROOT FIX 2 — Backend: percent/money returned as PHP `float` (type-contract break reaching the API)
The math is often correct bcmath internally, but the public method `(float)`-casts before returning → float on the wire, trailing zeros dropped.
- `Product/…/MarginService.php:186,199,275,306,321,331` — `getSuggestedPrice()`/`calculateMargin()`/`getMarginLevel()` return native `float`. → return **strings**.
- `Pricing/…/PricingController.php:526,528,534,536` — serializes those floats directly (`LineEntryController:145` already does it correctly for `suggested_price` — copy that).
- `POS/…/DiscountController.php:111,127,131,133,224,227` — `(float)` on `decimal:2` discount limits, serialized raw. → keep strings.
- `Taxation/…/WithholdingCertificate.php:229-232` `getRateAsPercentage(): float` → reaches `WithholdingCertificateResource:49`. Fix to `: string` — the CORRECT twin is `WithholdingTaxRule.php:151-153` (returns string, has an anti-float-cast comment). Same bug in `Taxation/…/ValueObjects/WithholdingCalculation.php:116-118,154` → `WithholdingCalculationData.ratePercentage: float` → `WithholdingPreviewController:63`.
- `Taxation/…/CertificatePDFService.php:93` — float `.'%'` on a **legal PDF certificate** drops `5.00`→`5`. `TEJExportService.php:124` feeds the float into bcformat.
- `Billing/…/InvoiceService.php:47-50,357-381` — the **one live money-float arithmetic path** (`$taxAmount = $price * ($taxRate/100)` → written to `decimal:3` columns). Sibling `createManualInvoice():118+` is already bcmath with a precision-drift comment — copy it.
- `Document/…/FacturXService.php:249-253` — `(float)` on basis/tax/rate at a **legal e-invoice boundary** (verify the XML builder accepts strings; round-and-document if float-only).
- Lower severity (reporting/status, output already ~2dp): `Inventory/…/GoodsReceiptService.php:831-833`, `Accounting/…/SalesReportService.php:150,192`, `Loyalty/…/RewardRedemptionService.php:105-163` (currently dead code — fix or delete before wiring).

## ROOT FIX 3 — Web: no canonical percent formatter is used
`lib/format.ts:181` `formatPercentage(value, decimals=2)` **exists, is correct, and has ZERO callers.** Two duplicated local `formatPercent` helpers exist at 1dp (`components/organisms/ProductPricingCard/ProductPricingCard.tsx:47`, `components/organisms/LandedCostBreakdown/LandedCostBreakdown.tsx:41`). ~26 sites echo the raw string, ~14 use `toFixed(1/4)` — inconsistent everywhere.
- **Highest leverage:** adopt one canonical `formatPercent` (2dp, trim trailing zeros) and apply at all raw-display sites; delete the local dupes.
- **Raw-display (`{rate}%` no trim), 26 sites** incl.: `components/atoms/TaxConfigurationSelect/TaxConfigurationSelect.tsx:23` (SEED) + its unflagged twin `hooks/useTaxConfigName.ts:9`; `settings/TaxSettingsPage.tsx:414`; `inventory/components/pricing/PricingIntelligencePanel.tsx:147,151,166`; `purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:516`; `withholding/*` (4 sites, some typed `number` — DTO leaks a float); `admin/…/InvoicesPage.tsx:344`; `documents/components/{DocumentTotals:103,DocumentLines:118,DocumentLineEditor:697,724}`; `treasury/PaymentMethodsPage.tsx:90,93`; `pos/…/ProductInfoModal:301`, `pos/…/ZReportDetailPage:293`, `pos/…/CartLineItem:201`; `coupons/…/CouponListPage:183`; `promotions/…/PromotionListPage:170`; `catalog/…/RecipeLineEditor:392`, `catalog/…/CompositeItemFormPage:275`; **field-reuse bug** `services/{ServiceDetailPage:262,270;ServiceListPage:298,360}` (money-typed `base_price` rendered with `%`).
- **`toFixed(1/4)` on percents, 14 sites** incl.: `inventory/components/pricing/{PriceInputWithMargin:143,149,155; ProductPricingCard:130(1dp)/178(2dp — inconsistent same file); MarginIndicator:96}`, `components/molecules/MarginIndicator:62`, `documents/…/costing/LandedCostBreakdown:115`, `catalog/…/CompositeItemFormPage:38-43` (Big.js but hardcoded 1dp while citing rule 19), `withholding/…/SalesWithholdingTrackingPage:105` + `treasury/…/ToleranceSettingsDisplay:56` + `withholding/…/WithholdingPreviewModal:62` (all `toFixed(4)`).

## ROOT FIX 4 — parseFloat / float math on percents & money (web + POS)
- **Web money-math bug (worst):** `inventory/components/pricing/ProductPricingCard.tsx:34-43` runs the whole margin calc in raw floats (`((listPrice-costPrice)/costPrice)*100`) — sibling `ProductHero.tsx:55-60` does it in Big.js. `WithholdingPreviewModal.tsx:60-74` computes withholding amount in float; `documents/CreateCreditNotePage.tsx:595` computes a live tax-inclusive total in float; `DocumentLineEditor.tsx:731` `Number(taxRate)||0`.
- **POS (fiscal), 18 findings:** `tax_rate: number`/`parseFloat` in report + Z-report types (`lib/offline/{types.ts:54,endOfDayPreview.ts:304-306,zReportService.ts:811}`, `api/reportApi.ts:449-451`) — loses 2dp/trailing zeros on fiscal reports; discount-input `parseFloat` in 3 modals (`LineDiscountModal:113`, `components/pos/DiscountModal:53`, `organisms/DiscountModal:112`); money as float `changeDue` (`receiptService.ts:252,575`); `ModifierSelectionModal.tsx:88,94,167` float money math; `CashDrawerModal:38`, `TransactionCart:372`, `VoucherTenderModal:280`.

## ROOT FIX 5 — Money-scale drift in pre-2026-03-24 migrations (secondary, money not percent)
`price_list_items:18` (`decimal(12,2)`→3), `pos_shifts:36` opening/expected_cash (→3), `pos_cash_drawer_operations:33` amount (→3), `workshop_work_order_lines:48` quantity (`decimal(12,3)`→4). No `float()`/`double()` columns anywhere (good).

---

## Correct reference implementations (copy these patterns)
`Pricing/…/DiscountPolicyService.php` (`PERCENT_SCALE=2`, `bcround($pct,2)`); `Taxation/…/WithholdingTaxRule::getRateAsPercentage(): string`; `Billing/…/InvoiceService::createManualInvoice()`; `Product/Domain/Category.php:94-95`; web `products/editor/…/ProductHero.tsx` (Big.js margin).

## Fiscal-sensitive changes (require `fiscal-pos-reviewer` gate)
POS receipt `eco_tax_rate`, POS report/Z-report `tax_rate`, `FacturXService`, withholding certificate PDF/TEJ export. Do NOT alter canonical fiscal bytes / hash-chained payloads — format at the presentation boundary only for those.
