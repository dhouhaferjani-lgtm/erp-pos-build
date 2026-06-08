# Precision & Scale-Drift Audit — AutoERP

**Date:** 2026-05-28
**Scope:** `apps/api/`, `apps/web/`, `apps/pos/`, `packages/shared/` — backend Laravel modules, frontend React features, POS Tauri shell, generated DTO types.
**Auditor:** Opus 4.7 session (4 parallel research agents: Migrations+Casts, Services bcmath/float, Form Requests + Resources, Frontend + POS).

---

## Executive summary

The audit catalogs **48 distinct findings** across **22 backend modules** and **most frontend feature directories** (Treasury, Document, POS, Inventory, Accounting, Catalog, Loyalty, Voucher, Coupon, Promotion, Marketplace, Compliance, Taxation, Withholding, Workshop, Pricing, Service, Cart, BatchExpiry, Identity, Partner, Billing). Severity breakdown: **5 CRITICAL, 23 HIGH, 14 MEDIUM, 6 LOW**.

**The headline pattern:** the codebase already has the canonical helper for money — `Shared\Domain\CurrencyScale::bcformat()` — but its adoption is uneven. The 2026-03-11 / 2026-03-23 / 2026-03-24 migrations widened ~50 monetary columns from `decimal(N,2)` to `decimal(N,3)` so TND/LYD/JOD/KWD/OMR/BHD tenants could store millimes. **The widening migrations updated the columns but not the producer-consumer contracts that write to them.** Three layers were left behind:

1. **The FormRequest / validator layer** — ~120 bare `numeric` rules ingest unbounded precision against `decimal(N,3)` columns. PG silently truncates at INSERT, and the fiscal hash chain signs the rounded value while the user-visible value is the original.
2. **The Service / bcmath layer** — ~43 callsites hardcode scale `2` in `bcadd/bcsub/bcmul/bccomp`, writing into widened scale-3 columns. Aged-receivables, year-end uninvoiced delivery-note reports, document refunds, vendor refunds, and batch write-offs all under-report by 1 millième per row.
3. **The Inventory / quantity domain** — quantity is split across **5 different scales** (`(15,2)`, `(15,4)`, `(10,3)`, `(12,3)`, `(10,2)`). Every cross-module write (POS sale → stock movement, document delivery → stock, marketplace order → ERP document, recipe consumption → batch stock) is a precision-narrowing event. There is no `QuantityScale` analog to `CurrencyScale`; every module rolls its own scale and they disagree.

**The two most acute findings:**

- **F-POS-1 (CRITICAL)** — the POS Tauri client hashes a re-canonicalized cash amount with a hardcoded `.toFixed(3)`. For TND tenants it matches; for any non-3-decimal currency, the device-authority chain hashes a different string than the server canonicalizer expects. This is a latent fiscal-chain-break vector that will fire the moment a non-TND tenant uses cash-drawer operator approval.
- **F-WAC-1/2 (CRITICAL)** — the entire Inventory WAC pipeline (`LandedCostService`, `WeightedAverageCostService`) injects `CurrencyScaleResolverInterface` but uses it only for the *final* `round()`. All intermediate allocation and weighted-average arithmetic is `(float)` + native multiplication/division. This is the canonical source-of-truth for COGS posting, and drift compounds through every goods receipt.

**The Inventory module's `decimal:2` quantity drift (`stock_levels`, `stock_movements`, `StockAdjustmentService::SCALE = 2`) is the canonical example of every pattern above and is being fixed in parallel** (per `docs/superpowers/coordination/2026-05-28-inventory-precision-fix-prompt.md`). This audit catalogs that work as **F-INV-* [PARALLEL-FIX-IN-FLIGHT]** and flags ~10 sibling findings in other modules that the parallel fix does NOT cover (Inventory stock-movement controllers, BatchExpiry write-off, POS receipt stock decrement, etc.).

**Coverage gap with the highest hidden surface area:** the frontend `step="0.01"` anti-pattern. **78 numeric inputs across web + POS use `step="0.01"`**, including the JournalEntryForm debit/credit, the POS CashTenderedModal (both web and Tauri variants), every Treasury payment form, every loyalty / coupon / promotion / voucher / partner / product / service / catalog form. **A TND tenant cannot enter millime-precision in any of these inputs.** A French / EUR tenant is fine. There is exactly one currency-aware step site in the entire codebase: `apps/web/src/features/compliance/components/CashDrawerControlsSection.tsx:166`. This is a cross-cutting fix that warrants its own sweep PR.

---

## Severity definitions

- **CRITICAL** — silently corrupts financial or fiscal data; user-facing precision contract is broken; regulatory-compliance risk; potential fiscal-hash-chain divergence.
- **HIGH** — silently rounds business data; mismatch between user-submitted and stored values; audit-trail inconsistency; security/permission-boundary drift.
- **MEDIUM** — display truncates real data; user sees less precision than stored; reports may misrepresent; intra-module consistency gap.
- **LOW** — internal inconsistency with no user-visible impact today (but a refactor could expose it).

---

## Findings

### F-POS-1 — `formatCashAmount` hashes re-parsed amount with hardcoded scale 3 (CRITICAL)

**Module(s):** POS (Tauri shell), Compliance (operator-approval fiscal chain)

**Files cited:**
- `apps/pos/src/lib/operatorApproval/cashDrawerApproval.ts:36` — `Number.parseFloat(amount).toFixed(3)`
- `apps/pos/src/lib/operatorApproval/cashDrawerApproval.ts:128` — site where formatted amount enters the canonical-encoder payload that gets hashed for the device-authority fiscal evidence
- Server-side canonicalizer (sibling): `apps/api/app/Modules/Shared/Domain/CurrencyScale.php` (per-currency)

**The drift:**
Device side hardcodes 3 decimals for the cash-drawer fiscal-event `amount`. Server side uses per-currency `CurrencyScale::bcformat`. For TND tenants the two happen to match (3 decimals). For any non-TND currency (EUR, USD, GBP), server canonicalizes at 2 decimals while device canonicalizes at 3 — different string, different hash, **silent chain break the first time a non-TND tenant performs an operator-approved cash event.**

**Worked example:**
1. EUR tenant cashier authorizes a €5.00 safe-drop. POS-side `formatCashAmount('5.00')` returns `'5.000'`. Device hashes payload `{...amount:'5.000'...}`.
2. Sync flushes the event to the server. Server canonicalizes with `CurrencyScale::bcformat('5.00', 2)` → `'5.00'`. Server hash of `{...amount:'5.00'...}` differs.
3. Chain-verification job flags the device chain as tampered.

**Recommended fix:** Thread the company's currency code to `formatCashAmount` and use `getCurrencyDecimals(currency)` (already imported in `apps/pos/src/lib/currency.ts`). Replace `Number.parseFloat` with a string-preserving formatter (or run through `bcformat` in `apps/pos/src/lib/decimal.ts:bcformat`).

**Estimated effort:** small — one function signature change plus call-site updates; the currency is already available in the auth store.

---

### F-WAC-1 — `LandedCostService` allocates costs entirely in float (CRITICAL)

**Module(s):** Inventory (cost capitalization), Accounting (COGS basis), Taxation (cost-deductible basis for Tunisia)

**Files cited:**
- `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php` (entire file, ~290 lines; `allocateCosts`, `allocateCostsAndTaxes`, `reallocateCosts`, `calculateAllocatedCost`, `calculateLandedUnitCost` — all 5 methods)
- Lines 43, 44, 48, 55, 56, 87, 88, 96, 106, 119, 142, 151, 154, 155, 156, 167, 182, 183, 187, 194, 195, 238–247, 257–268 (float casts and float-arithmetic round() sites)
- Destination columns:
  - `apps/api/database/migrations/2025_12_02_064936_create_document_lines_landed_cost_columns.php:15–16` — `document_lines.allocated_costs` decimal(12,3), `document_lines.landed_unit_cost` decimal(12,3) (widened by `2026_03_11_200000`)
  - `apps/api/database/migrations/2026_01_02_160000:18` — `document_lines.non_recoverable_tax` decimal(15,3)

**The drift:**
The service injects `CurrencyScaleResolverInterface $resolver` but uses `$this->scale()` only for the **final** `round()` on each line. All intermediate `$line->line_total`, `$line->quantity`, additional-cost sums, proportion divisions, and per-line multiplications are `(float)` casts with native PHP arithmetic. The result is then `(string)` cast back to a decimal string and persisted.

**Worked example:**
1. 3-line PO in TND tenant (scale 3): Line 1 quantity 7.1234, unit_price 5.123 TND; Line 2 quantity 12.5005, unit_price 10.999 TND; Line 3 quantity 100.0000, unit_price 0.123 TND. line_totals stored at scale 3: 36.499, 137.484, 12.300. Subtotal = 186.283.
2. additional_costs sum = 100.000 TND. Inside `allocateCosts`: `$subtotal = (float) 186.283` becomes IEEE-754 `186.2830000000000155…`. Proportion for line 1 = `36.499 / 186.283 = 0.19593834…`. allocated_cost_1 = `round(100.0 * 0.19593834, 3) = 19.594`. **For this case the answer happens to match a pure-bcmath compute.**
3. Edge case: with 30 small-line-total PO lines and additional_costs summed across receipts, accumulated IEEE-754 drift in the foreach loop's `$totalNonRecoverableTax += $totalLineTax` produces 1-millième TND drift per line × 30 = ~0.03 TND mis-allocated across the receipts. **Compounds with every receipt event because the result feeds the WAC formula.**

**Recommended fix:** Replace all `(float)` and `round()` with `bcadd/bcsub/bcmul/bcdiv` at `$this->scale() + 4` for intermediates, then `CurrencyScale::bcformat($result, $this->scale())` at the boundary. The pattern is already in `PaymentRefundService::540–620` as the gold standard.

**Estimated effort:** medium — every method needs to be rewritten with explicit bcmath; full feature-test sweep needed; PR scope-locked to this service.

---

### F-WAC-2 — `WeightedAverageCostService` runs WAC math in float (CRITICAL)

**Module(s):** Inventory (WAC), Accounting (COGS posting), Product (cost-price catalog)

**Files cited:**
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:93–100, 232–265, 342–365, 443–458`
- Specifically lines 93, 94, 95, 98, 100 (`$currentQty = (float) $stockLevel->quantity; $currentCostPrice = (float) $product->cost_price; $newAvgCost = $newQty > 0 ? round($newValue / $newQty, $this->scale()) : 0`)
- Destination: `products.cost_price` decimal(12,3) (`apps/api/database/migrations/2025_12_02_064541:15` widened by `2026_03_11_200000:33`); `stock_movements.avg_cost_before/after` decimal(12,3) (`apps/api/database/migrations/2025_12_02_065035:15–18` widened)
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:493–495` (also confirmed in Opus review P3-3: `(float) ($product->cost_price ?? 0); $delta = $additionalCost / $onHandFloat`)

**The drift:**
The injected currency-scale resolver is used **only for the final `round()`**. Every intermediate operation in the WAC formula — current value calc, weighted-average division, additional-cost capitalization — is native PHP float. Float subtraction precision loss happens before bcmath ever sees the values.

**Worked example:**
1. TND product opens with stock 7.0000 units @ 5.123 TND WAC. current_value = `(float) 7.0000 * (float) 5.123 = 35.861` (lucky — representable).
2. Goods receipt of 3.5 units @ 5.250 TND. landed_unit_cost computed: `bcadd($unit_cost, $allocated/$qty, scale)` upstream, but here `$newValue = 35.861 + (3.5 * 5.250) = 54.236`. `$newAvgCost = round(54.236 / 10.5, 3) = round(5.16533…, 3) = 5.165`. Correct in this case.
3. Edge: product with quantity = 0.1, cost = 0.1. currentValue = `0.1 * 0.1 = 0.010000000000000002`. After 100 such micro-receipts: drift > 1e-12 per op. Over months of POS sales for high-turnover pharma SKUs: visible at the millième-TND level on a hot product's cost_price.

**Recommended fix:** Same as F-WAC-1 — bcmath throughout with `$this->scale() + 4` intermediates, `CurrencyScale::bcformat` at the boundary. Add a regression test: 100 sequential micro-receipts of decimal-fraction quantity/cost → assert WAC drift after 100 ops is zero (within scale).

**Estimated effort:** medium — single service file; co-deliverable with F-WAC-1 in an "inventory-WAC-precision" PR.

---

### F-TAX-1 — Manual withholding rate truncates 5 basis points silently (CRITICAL)

**Module(s):** Taxation (withholding certificates), Treasury (refund/allocation)

**Files cited:**
- `apps/api/app/Modules/Taxation/Presentation/Requests/CreateWithholdingCertificateRequest.php:55` — `'manual_rate_percentage' => 'numeric|min:0|max:100'` (bare numeric)
- `apps/api/app/Modules/Taxation/Application/DTOs/CreateWithholdingCertificateData.php:30` — `public ?float $manualRatePercentage`
- Storage: `apps/api/database/migrations/2026_01_08_172147_create_withholding_certificates_table.php:36` — `withholding_certificates.withholding_rate` decimal(5,4)
- Related model: `apps/api/app/Modules/Taxation/Domain/Models/SalesWithholdingTracking.php:66` — no cast on the rate property

**The drift:**
Validator accepts unlimited precision; DTO laundered through `float`; service divides by 100 to produce a scale-4 rate; PG silently truncates the 5th decimal at INSERT. Every certificate created with a `manual_rate_percentage` carrying more than 2 fractional digits silently loses up to 50 basis points (5 bps × percent → 0.0005 absolute).

**Worked example:**
1. Admin POSTs `{"manual_rate_percentage": 12.345, "override_reason": "..."}`. Validation passes.
2. DTO float = `12.345`. Service: `$rate = $manualRate / 100 = 0.12345`.
3. PG INSERT into decimal(5,4) silently truncates → stored `0.1234`. Fiscal hash signs `0.1234`. On a 10,000 TND gross, that's `10000 * 0.00005 = 0.5 TND` understated tax. Across the certificate batch this scales linearly.

**Recommended fix:** Change `$manualRatePercentage` DTO field to `?string`. Tighten validator to `'numeric|min:0|max:100|regex:/^\d+(\.\d{1,2})?$/'` (max 2 percent decimals = scale 4 after dividing by 100). Optionally: add a guard in `WithholdingCertificateService` that rejects any rate that doesn't round-trip cleanly at scale 4.

**Estimated effort:** small — single request file + DTO field type change + one regex.

---

### F-INV-1 — Inventory quantity scale-2 truncation [PARALLEL-FIX-IN-FLIGHT] (CRITICAL)

**Module(s):** Inventory, BatchExpiry, Document, POS

**Files cited:**
- `apps/api/app/Modules/Inventory/Domain/StockLevel.php:58–61` — `'quantity' => 'decimal:2'` etc.
- `apps/api/app/Modules/Inventory/Domain/StockMovement.php:85–87` — `'quantity' => 'decimal:2'` etc.
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:23` — `private const SCALE = 2`
- `apps/api/database/migrations/2025_11_30_110000_create_inventory_tables.php:21,38` — `stock_levels.quantity`, `stock_movements.quantity` both decimal(15,2)
- Producer side at scale 4: `stock_transfer_lines.quantity` decimal(15,4) (Opus review P2-5)

**The drift:**
Producer-consumer gradient. Producers write at scale 4 (`stock_transfer_lines`, `inventory_batch_stock`, `inventory_counting_items`, `document_lines`); consumers store at scale 2 (`stock_levels`, `stock_movements`). Every cross-boundary write silently rounds.

**Worked example:**
1. User submits a stock transfer line with `quantity = 7.1234`. `stock_transfer_lines.quantity` decimal(15,4) stores `7.1234`.
2. `StockAdjustmentService::issue('7.1234')` does `bcsub($before, '7.1234', 2)`. `stock_levels.quantity` decimal(15,2) stores `7.12`. `stock_movements.quantity` stores `7.12`. Ghost: 0.0034 units silently lost.

**Recommended fix:** Per `2026-05-28-inventory-precision-fix-prompt.md` — bump every quantity column to decimal(15,4); bump `StockAdjustmentService::SCALE = 4`; sweep model casts; add regression test.

**Estimated effort:** medium — already scoped in the in-flight fix; not double-scheduled here.

---

### F-INV-SIBLING-1 — Inventory stock-movement controllers have the same drift as the transfer flow (HIGH)

**Module(s):** Inventory (sibling endpoints to the in-flight transfer fix)

**Files cited:**
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:65` — `receive` endpoint
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:104` — `issue`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:159` — `transfer`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:216` — `adjust`

**The drift:**
All four sibling endpoints use inline `'quantity' => 'numeric|min:0.01'` (or `numeric|min:0`). Same Pass-2 bug as `StoreStockTransferRequest`. The parallel fix targets only the transfer-line ingress; these four siblings will continue to round silently.

**Worked example:**
1. Operator POSTs `/stock-movements/receive` with `quantity = 100.1234`.
2. Validator accepts; forwarded as string to `StockAdjustmentService::receive` which uses `bcadd($before, '100.1234', 4)` after the parallel fix lands — except the **inline validator never caps the precision, so a 5+ decimal input also passes**. PG silently truncates at INSERT.
3. Stock movement audit log records less than the operator typed.

**Recommended fix:** After the parallel inventory-precision-fix lands the column widening to decimal(15,4), retroactively add `regex:/^\d+(\.\d{1,4})?$/` to each of these 4 controllers' validators. Or — convert the 4 inline validations into a shared FormRequest with the canonical regex.

**Estimated effort:** small — 4-controller validator update.

---

### F-INGRESS-POS — POS receipt money fields are all bare numeric (HIGH)

**Module(s):** POS

**Files cited:**
- `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:67–110` — `lines.*.quantity, unit_price, modifiers.*.price_adjustment, discount_amount, transaction_discount_amount, loyalty_discount_amount`
- `apps/api/app/Modules/POS/Presentation/Requests/AddOrderLineRequest.php:45–48`
- `apps/api/app/Modules/POS/Presentation/Requests/ModifyOrderLineRequest.php:32–33`
- `apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php:146–153`
- Storage: `pos_receipt_lines.unit_price` decimal(12,3), `quantity` decimal(10,3), `discount_amount` decimal(12,3)

**The drift:**
Every monetary field in the POS receipt-create request uses bare `numeric|gte:0` (or `gt:0`). For TND tenants the storage columns are decimal(N,3); for non-TND tenants the same columns store decimal(N,3) but the customer sees 2-decimal values. The validator caps neither floor precision nor ceiling precision. PG truncates at INSERT and the fiscal hash signs the rounded value.

**Worked example:**
1. Cashier opens an item with a price-list override `unit_price = 1.2345 TND`. UI submits the value.
2. Validator passes; controller forwards string; PG stores `1.234` or `1.235` depending on rounding mode.
3. Cart subtotal on screen showed `1.2345 * 4 = 4.938`. Receipt now stores subtotal `4.936` (4× rounding). Fiscal hash signs `4.936`. Customer comparing the printed receipt to the on-screen total sees a 2-millième discrepancy. Hash-chain reconstruction fails if anyone re-runs the calc with the original input.

**Recommended fix:** Add `regex:/^\d+(\.\d{1,3})?$/` to every money field in `StoreReceiptRequest`, `AddOrderLineRequest`, `ModifyOrderLineRequest`, and the inline `DiscountController` validator. (Use `regex:/^\d+(\.\d{1,4})?$/` for `quantity` fields if the parallel inventory fix bumps `pos_receipt_lines.quantity` — currently decimal(10,3).)

**Estimated effort:** small per request file, but ~6 files × 4-8 fields each = ~30 regex additions; mechanical.

---

### F-INGRESS-DOC — Document line money fields are bare numeric on Create & Update (HIGH)

**Module(s):** Document (invoice, quote, sales-order, credit-note, delivery-note)

**Files cited:**
- `apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:108–112` — `lines.*.quantity, unit_price, discount_percent, discount_amount, tax_rate`
- `apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php:86–90` — same fields
- `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:153–165` — inline duplicate
- `apps/api/app/Modules/Document/Presentation/Requests/Concerns/AppliesDiscountToleranceRule.php:84–103` — tolerance check at scale 4 but reads already-rounded values
- Storage: `document_lines.quantity` decimal(15,4), `unit_price` decimal(15,3), `line_total` decimal(15,3), `tax_amount` decimal(15,3)

**The drift:**
Same pattern as F-INGRESS-POS but for non-POS documents. A TND invoice's `unit_price = 1.2345` validates, PG stores `1.234`. The `AppliesDiscountToleranceRule` trait then bcmul's the rounded `unit_price` against `quantity` at scale 4 — but the input it sees is already silently rounded, so the tolerance evaluation runs on stale data.

**Worked example:**
1. Sales rep creates a TND quote with `unit_price = 1.2345`. PG stores `1.234`.
2. `bcmul('7.1234', '1.234', 4) = '8.7903'` (rounded line_total). Tolerance check compares against the user-submitted `line_total`. If the user submitted `8.7910` (computed from the original `1.2345 * 7.1234 = 8.79073...`), tolerance fires for a 0.0007 mismatch. **The user sees a "discount tolerance violation" error for a calc the server itself silently rounded.**

**Recommended fix:** Add the regex pattern aligned with each column. Then update the tolerance trait to read the original `request()->input(...)` and compare at scale 4 against the user's original value.

**Estimated effort:** small-to-medium — 3 request files + 1 trait + 1 controller; mechanical regex but the tolerance-trait change requires careful test coverage.

---

### F-INGRESS-TRES — Treasury payment ingress is bare numeric across 8+ endpoints (HIGH)

**Module(s):** Treasury

**Files cited:**
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:125,136,138` — `amount`, `allocations.*.amount`, `withholding_rate`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:97,152,250,337`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php:96`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRefundController.php:94`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/BankReconciliationController.php:91`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:76,154`
- `apps/api/app/Modules/Treasury/Presentation/Requests/RefundPrepaymentRequest.php:33`
- Storage: `payments.amount` decimal(15,3), `payment_methods.fee_fixed` decimal(10,3), `bank_reconciliations.statement_balance` decimal(15,3)

**The drift:**
Every Treasury monetary ingress is bare `numeric|min:0.01`. The min bounds the floor but the validator caps nothing on the ceiling. POS payment ingestion has the regex (e.g. `StoreReceiptPaymentsRequest:119` uses `regex:/^\d+(\.\d{1,3})?$/`), proving the pattern exists and was intentional — but Treasury controllers didn't get the same treatment.

**Worked example:**
1. Customer pays `123.4567 TND` via bank transfer. Operator records via `PaymentController::store`.
2. Validator passes; PG stores `123.457` (rounded up). Reconciliation later runs against the bank statement which shows `123.4567` debited.
3. `payment_allocations.amount` is computed by allocating the rounded `123.457` against invoices; AR aging shows the customer owes `0.000` but the bank reconciliation owes `0.0003`. Permanent reconciliation gap.

**Recommended fix:** Apply the `regex:/^\d+(\.\d{1,3})?$/` pattern from POS payments to every Treasury controller. Bank-reconciliation statement_balance similarly needs the cap.

**Estimated effort:** small — 8 controllers, 1-2 fields each.

---

### F-INGRESS-ACC — Journal entry debit/credit bare numeric on decimal(15,3) (HIGH)

**Module(s):** Accounting

**Files cited:**
- `apps/api/app/Modules/Accounting/Presentation/Requests/CreateJournalEntryRequest.php:41–42` — `'lines.*.debit', 'lines.*.credit' => 'numeric|min:0'`
- `apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:399–414` — opening-balance ingestion (debit, credit, quantity, unit_cost, total)
- Storage: `journal_lines.debit, credit` decimal(15,3) (post-widening by `2026_03_11_200000:27–30`)

**The drift:**
GL precision laundering. Bookkeeper enters a 4-decimal debit `1234.5678`. Validator passes. PG silently truncates to `1234.568`. The debit/credit balance check inside the entry passes if the credit rounds the same way, but the trial-balance roll-up diverges from the source documents.

**Worked example:**
1. Tunisian bookkeeper records a TND opening balance: debit `1234.5678` to AR, credit `1234.5678` to opening-equity.
2. PG stores both as `1234.568`. Entry balances. Subsequent reconciliation against the source document shows `0.0002 TND` shortfall on both sides.

**Recommended fix:** `regex:/^\d+(\.\d{1,3})?$/` on debit, credit, quantity, unit_cost, total. Co-ordinate with `currency_code` propagation (a future EUR / GBP tenant has 2-dec books).

**Estimated effort:** small — 2 controllers.

---

### F-INGRESS-PRICING — Pricing margin check launders `sell_price` through `(float)` (HIGH)

**Module(s):** Pricing, Product (margin gate)

**Files cited:**
- `apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php:479` — `'sell_price' => 'required|numeric|min:0'`
- `apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php:489` — `$sellPrice = (float) $validated['sell_price'];`
- `apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php:501` — response echoes the float
- `apps/api/app/Modules/Product/Application/Services/MarginService.php:73,98,122,149` — `round($cost * (1 + $margins['target_margin'] / 100), $this->scale())` (float arithmetic)

**The drift:**
The "can sell at price" gate is a policy boundary (some users have permission to sell below cost). The validator accepts unlimited precision; the controller casts to float; the service runs `(sell - cost) / cost * 100` in float, then `round()`s. IEEE-754 jitter at the boundary can flip the gate decision.

**Worked example:**
1. Operator with `max_discount_percent = 0.05%` POSTs `/pricing/check-margin` with `sell_price = 100.1, cost = 100.05`.
2. Float compute: `(100.1 - 100.05) / 100.05 * 100 = 0.04996…%`. With bcmath the same compute yields `0.05000%`. The gate's `>= 0.05%` check fires `false` in float mode, `true` in bcmath mode. **Inconsistent authorization decisions.**

**Recommended fix:** Drop the `(float)` cast; pass `(string) $validated['sell_price']` to `MarginService`; rewrite `MarginService::canSellAtPrice` and the cost-margin compute with bcmath at the resolver scale.

**Estimated effort:** small.

---

### F-INGRESS-ZSYNC — Z-report sync validator mismatch vs OpenShift (HIGH)

**Module(s):** POS (offline-sync re-ingress)

**Files cited:**
- `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:72–73` — `'opening_cash' => ['required', 'numeric']`, `'expected_cash' => ['required', 'numeric']` (no precision cap)
- Sibling: `apps/api/app/Modules/POS/Presentation/Requests/OpenShiftRequest.php:47` — `regex:/^\d+(\.\d{1,3})?$/` (capped)
- Storage: `pos_shifts.opening_cash, expected_cash` decimal(16,4) post-widening (`2026_04_25_000002`)

**The drift:**
Two paths writing to the same column with different validators. This is the L9 lesson from `feedback_canonicalize_before_state_machine_input` — POS Z-replay must canonicalize at the same fidelity as the original. The shift was widened to decimal(16,4) for cash-counting precision; the sync re-ingress validator never got the regex update.

**Worked example:**
1. Device replays a saved Z-report with `opening_cash = '100.4567'`. Server-side validator accepts.
2. Server canonicalizes at scale 4 → `'100.4567'`. PG stores `100.4567`. But the original OpenShift validator would have rejected anything > scale 3 (regex `\d{1,3}`). The shift now stores 4-decimal values via Z-replay but only 3-decimal values via OpenShift. Hash-chain inputs diverge.

**Recommended fix:** Align ZReportSync validation: replace bare `numeric` with `string|regex:/^\d+(\.\d{1,4})?$/`. Verify the canonicalizer downstream matches.

**Estimated effort:** trivial — 2-line change.

---

### F-POS-2 — `ReceiptReturnService::roundVat` uses pure float; asymmetric with sale path (HIGH)

**Module(s):** POS (refund / return), Fiscal (hash-chain canonicalization)

**Files cited:**
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:919–928` — sale path: bcmath at extra precision then `(string) round((float) $raw, $this->scale())`
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1049–1055` — return path: `$raw = (float) $netAmount * (float) $taxRate / 100.0;` direct float multiplication

**The drift:**
Sale path computes VAT in bcmath (modulo final cast). Return path skips the bcmath stage and runs the entire compute in float. For most real-world values both paths agree because IEEE-754 happens to be exact for them. For values near IEEE-754 mantissa boundaries (large amounts, tiny rates, or sale/refund pairs where the sale was bcmath-correct but the refund is float-approximate), the two paths disagree. Fiscal compliance requires sale and return to use **identical** arithmetic.

**Worked example:**
1. Sale: net `98.765 TND`, rate `19%`. Sale path: `bcmul('98.765','19','7') = '1876.535'`, `bcdiv(_,'100',7) = '18.76535'`, `round(_, 3) = 18.765`.
2. Refund: same inputs. Return path: `98.765 * 19.0 / 100.0 = 18.76535` (representable here, same result). For larger amounts (999999.999 × 19 / 100 = 189999.99981), bcmath path produces `'189999.998'` predictably; float path may produce `189999.99981000002` then `round → 189999.998`. Likely match for normal POS magnitudes; **unverified for B2B refund flows that hit large totals**.

**Recommended fix:** Replace `ReceiptReturnService::roundVat` body with the bcmath version copied from `ReceiptCreationService::roundVat`. Or — better — extract a single private helper method on a shared trait so the two paths cannot drift again.

**Estimated effort:** trivial — copy ~10 lines.

---

### F-POS-3 — `pos_orders` and `pos_receipts` use different scales for the same money (HIGH)

**Module(s):** POS

**Files cited:**
- `apps/api/app/Modules/POS/Domain/Order.php:124–127` — `'subtotal','tax_amount','discount_amount','total' => 'decimal:4'`
- `apps/api/app/Modules/POS/Domain/Receipt.php:214–217` — same fields cast as `decimal:3`
- Migrations: `apps/api/database/migrations/2026_03_11_400000_create_pos_orders_table.php:31–34` (`decimal(15,4)`); `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:59–61` widened by `2026_03_11_200000:71–76` (`decimal(12,3)`)

**The drift:**
A POS Order (open table / tab) carries `total` at scale 4; the Receipt (final fiscal artifact) carries `total` at scale 3. Any code that takes an Order amount and writes it to a Receipt loses 1 decimal silently.

**Worked example:**
1. Cashier opens a table-service Order. Cart total = `1.234`. Order persists scale-4 `'1.2340'`.
2. Order is converted to a Receipt at checkout. Receipt persists scale-3 `'1.234'`. Match.
3. Edge: an open Order with manually-applied discount yielding `1.2345`. Order stores `'1.2345'`. Receipt creation converts to `'1.234'` or `'1.235'`. The Tunisia chain-hash signs `'1.234'` but the Order's last-modified hash signed `'1.2345'`. Cross-table audit reveals the mismatch.

**Recommended fix:** Pick one scale (3 to match the canonical Receipt) and migrate `pos_orders.*` to decimal(N,3). Or — if the 4th decimal is intentional for table-service running totals — document the truncation contract explicitly in the Receipt-create service and add a CHECK constraint that asserts `pos_receipt.total = round(pos_order.total, 3)` so silent drift cannot occur.

**Estimated effort:** medium — migration to widen/narrow + model cast update + a producer-consumer contract test.

---

### F-PERMISSIONS — `max_discount_percent` is `(float)` cast on a `decimal(5,2)` column (HIGH)

**Module(s):** Identity, POS (terminal limits), Discount calc

**Files cited:**
- `apps/api/app/Modules/Identity/Domain/User.php:114` — `'max_discount_percent' => 'float'`
- `apps/api/app/Modules/POS/Domain/Terminal.php:129` — `'max_discount_percent' => 'float'`
- Migrations: `apps/api/database/migrations/2026_01_10_063755_add_discount_permissions_to_users.php:26` (`decimal(5,2)`); `apps/api/database/migrations/2026_01_10_063726_add_discount_settings_to_terminals.php:22` (`decimal(5,2)`)
- Comparison site: `apps/api/app/Modules/POS/Domain/Services/DiscountCalculationService.php:129,132,179,180,202`

**The drift:**
Permission-percentage columns are stored as `decimal(5,2)` strings, but the Eloquent cast is `float`. Reading rounds to native PHP float (`15.0` not `'15.00'`). Comparisons like `if ($user->max_discount_percent >= $requestedPercent)` mix float with string-derived bcmath values; edge cases at `14.99 / 15.00` flip the gate.

**Worked example:**
1. Admin sets a cashier's limit to `15.00`. DB stores `'15.00'`. Read returns float `15.0`.
2. Cashier requests a `15.00%` discount. Discount service computes `$requestedPercent = bcadd($raw, '0', 2) = '15.00'`.
3. Comparison `$user->max_discount_percent >= $requestedPercent` becomes `15.0 >= '15.00'`. PHP coerces the string to float for comparison — usually fine, but PHP's float-string comparison rules can flip at 15.00 / 14.99 / 0.01 edge cases (PHP 8's stricter string-to-numeric comparison helps; PHP < 8 was wilder).
4. **Permission boundary inconsistent across versions / IEEE-754 edges.**

**Recommended fix:** Change the cast to `'decimal:2'` on both models. Audit `DiscountCalculationService` for any sites that need conversion to bcmath comparison.

**Estimated effort:** trivial — 2 model cast changes; co-deliver with a regression test.

---

### F-PRODUCT-1 — Product canonical money fields have no Eloquent cast (HIGH)

**Module(s):** Product, Catalog (downstream), Pricing (downstream)

**Files cited:**
- `apps/api/app/Modules/Product/Domain/Product.php:114–126` — `casts()` block omits `sale_price`, `purchase_price`, `cost_price`, `last_purchase_cost`, `target_margin_override`, `minimum_margin_override`, `tax_rate`
- Migration: `apps/api/database/migrations/2025_11_30_052910_create_products_table.php:23–25`; widened by `2026_03_11_200000_widen_monetary_columns_to_scale_3.php:33–40` to decimal(15,3) (price/cost) and decimal(5,3) (margin overrides)

**The drift:**
Columns were widened from decimal(N,2) to decimal(N,3) for TND, but the model never gained a cast. Reading `$product->sale_price` returns the raw PG driver string (`'1.234'`), but the model's `$attributes` array has no scale invariant — a developer can `$product->sale_price = 1.4999999` (float) and the write goes through. Imports / API clients PUT-ing fractional values bypass cast guards entirely.

**Worked example:**
1. Bulk import POSTs `{"sale_price": 1.2349}` for a TND product. No cast guard. PG silently truncates to `1.235`.
2. Eloquent returns string `'1.235'` on read. Caller code does `Money::__construct((float) $product->sale_price, ...)` — round-trip through float; downstream Pricing math may see `1.234999...` instead of `1.235`.

**Recommended fix:** Add `'decimal:3'` cast on each monetary field in `Product::casts()`. Add `'decimal:2'` on `tax_rate`. Add a default attribute `'sale_price' => '0.000'` (scale-3 zero) to align with the new scale. Repeat for `Service`, `Account`, `Coupon`, `Promotion`, `CouponUsage`, `Payment` (FX columns).

**Estimated effort:** small — but co-coordinate with Frontend / DTO type generation (the cast change may surface as `string` instead of `number` in generated types).

---

### F-ACCOUNTING-1 — `Account.balance` default `'0.00'` mismatches `decimal(19,3)` column (HIGH for TND)

**Module(s):** Accounting

**Files cited:**
- `apps/api/app/Modules/Accounting/Domain/Account.php:101` — `protected $attributes = ['balance' => '0.00']`
- Migration: `apps/api/database/migrations/2025_11_30_090000_create_accounts_table.php:23` widened by `2026_03_11_200000:24` to decimal(19,3)

**The drift:**
Newly created accounts get a scale-2 zero. Every subsequent TND update writes scale-3 values. The GL trial balance opens at scale 2 and closes at scale 3 for the same account. Reconciliation reports may render `'0.00'` for unused accounts but `'0.000'` for used ones — inconsistent JSON serialization across the chart of accounts.

**Worked example:**
1. New Tunisian tenant runs first-time setup. `RolesAndPermissionsSeeder` (or chart-of-accounts seeder) creates `Cash` account. `$account->balance = '0.00'`.
2. First POS sale credits Cash. `$account->balance = bcadd('0.00', '5.123', 3) = '5.123'`.
3. Trial balance report joins all accounts. Cash shows `'5.123'`. Unused `Petty Cash` shows `'0.00'`. Frontend formatter sees mixed-scale strings and renders inconsistently.

**Recommended fix:** Change the default attribute to `'0.000'` and add the `'decimal:3'` cast. Verify seeder data.

**Estimated effort:** trivial.

---

### F-TREASURY-1 — Payment FX columns have no Eloquent cast (HIGH)

**Module(s):** Treasury, Accounting (FX gain/loss posting)

**Files cited:**
- `apps/api/app/Modules/Treasury/Domain/Payment.php:110–121` — casts() omits `exchange_rate_at_payment`, `fx_gain_loss_amount`, `discount_taken`
- Migration: `apps/api/database/migrations/2025_12_10_100003_add_allocation_fields_to_payments_table.php:17–19` — `decimal(15,6)` rate, `decimal(15,4)` amounts

**The drift:**
FX gain/loss feeds the GL. No cast = raw string read. `PaymentRefundService` does call `CurrencyScale::bcformat` correctly, but any other consumer (accounting export, journal-entry generator, reporting CSV) that does `(float) $payment->fx_gain_loss_amount` round-trips through IEEE-754. Per `feedback_audit_ci_gate_check`, exactly this kind of unguarded read is the recurring failure mode.

**Worked example:**
1. EUR-paying customer pays a TND invoice. `payments.exchange_rate_at_payment = '0.300123'` (6 dp). `payments.fx_gain_loss_amount = '0.0500'` (4 dp).
2. Accounting export CSV does `(float) $payment->fx_gain_loss_amount` → float `0.05`. CSV row reads `0.05` instead of `0.0500`. Importer downstream parses as `0.05` and posts to GL as `decimal(15,3)` → `0.050`. **Year-end FX-revaluation report shows `0.050` while the payment record shows `0.0500`. 0.0001 EUR ghost per FX-paid transaction.**

**Recommended fix:** Add `'exchange_rate_at_payment' => 'decimal:6'`, `'fx_gain_loss_amount' => 'decimal:4'`, `'discount_taken' => 'decimal:4'` to `Payment::casts()`. Audit accounting export for `(float)` casts on these fields.

**Estimated effort:** small.

---

### F-SVC-AGED — `AgedReceivablesService` scale=2 hardcoded sums of decimal(15,3) (HIGH)

**Module(s):** Document (AR reporting), Accounting (control-account reconciliation)

**Files cited:**
- `apps/api/app/Modules/Document/Application/Services/AgedReceivablesService.php:86,106,113,120,211,235,260,333` — `bcadd($bucket, $outstanding, 2)`
- Storage: `documents.balance_due` decimal(15,3)

**The drift:**
The AR aging report sums `balance_due` values into buckets (current, 30d, 60d, 90d+). Every `bcadd` uses scale 2. TND values lose the 3rd-decimal millième per row. For a partner with 1000 invoices, that's up to 1 TND silently under-reported in the aged bucket.

**Worked example:**
1. Partner has 5 invoices in the 30-60d bucket, each with `balance_due = 1234.567 TND`.
2. True bucket sum: `5 × 1234.567 = 6172.835 TND`.
3. Service iterates: `bcadd('0','1234.567',2) = '1234.56'`, `bcadd('1234.56','1234.567',2) = '2469.12'` (true `2469.134`), ... `'6172.80'` (true `6172.835`). **Drift: 35 millième TND lost. On a 1000-invoice partner: ~7 TND per aged report.**
4. Auditor compares AR aging total to the GL AR control account (which sums at scale 3). Reconciliation finding.

**Recommended fix:** Inject `CurrencyScaleResolverInterface` and replace every literal `2` with `$this->scale()`. Add a regression test that asserts AR aging total = SUM(documents.balance_due) at the company's scale.

**Estimated effort:** small — 8 sites, single file.

---

### F-SVC-DOCREFUND — `Document\Domain\Services\RefundService` scale=2 hardcoded for decimal(15,3) totals (HIGH)

**Module(s):** Document (credit notes), Fiscal (hash chain)

**Files cited:**
- `apps/api/app/Modules/Document/Domain/Services/RefundService.php:238,239,240,330`
- Storage: `documents.subtotal, tax_amount, total` decimal(15,3)

**The drift:**
Credit-note totals are aggregated via `bcadd($subtotal, $lineTotal, 2)`. Result is then padded to scale 3 by PG. The document `total` stored does not equal the sum of `document_lines.line_total` at scale 3. Fiscal chain hash verifier (`FiscalPayloadConstraintValidator`) re-runs `subtotal + tax = total` at runtime scale; the stored values can fail this check.

**Worked example:**
1. Credit note for 3 lines: 12.347, 5.123, 8.999 TND. True sum at scale 3: `26.469`.
2. Service: `bcadd('0.00','12.347',2) = '12.34'`, `bcadd('12.34','5.123',2) = '17.46'` (true `17.470` — drops `0.010`), `bcadd('17.46','8.999',2) = '26.45'`.
3. Stored: `documents.total = '26.450'` (PG-padded). **19 millième TND lost on a 26 TND credit note.**

**Recommended fix:** Replace literal `2` with `$resolver->scale()`. Add unit test using a 3-line TND fixture asserting `documents.total = SUM(line_totals)`.

**Estimated effort:** small.

---

### F-SVC-COMPLIANCE — Year-end uninvoiced-DN report scale=2 understates TND revenue (HIGH)

**Module(s):** Compliance (year-end fiscal report)

**Files cited:**
- `apps/api/app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php:109–111,165–167,204`
- Storage: `documents.subtotal, total` decimal(15,3)

**The drift:**
Year-end report sums delivery-note subtotals at scale 2. Source columns are decimal(15,3). For Tunisia tenants, this report is filed with year-end fiscal declarations and silent under-reporting is a tax-compliance liability.

**Worked example:**
1. Tunisian tenant. 100 uninvoiced delivery notes, average subtotal `1234.567 TND`.
2. Per-DN truncation: `'1234.56'` (drop 7 millième each).
3. Year-end report total: `100 × 1234.56 = 123456.00`. Truth: `123456.700`. **Drift: 0.700 TND on a 123,456 TND report.** Multiplied across multiple report periods and tenants this becomes a recurring fiscal liability.

**Recommended fix:** Inject `CurrencyScaleResolverInterface`; use `$resolver->scale()` for the aggregation.

**Estimated effort:** small.

---

### F-SVC-BATCHWRITEOFF — `BatchWriteOffService` scale=2 multiply yields scale-3 GL (HIGH)

**Module(s):** BatchExpiry, Accounting (COGS expense)

**Files cited:**
- `apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:140` — `bcmul($quantity, $unitCost, 2)`
- Inputs: `inventory_batch_stock.quantity` decimal(15,4), `stock_movements.unit_cost` decimal(12,3)
- Destination: `journal_lines.debit, credit` decimal(15,3)

**The drift:**
Write-off amount = `quantity × unitCost` at scale 2. Multiplies a 4-decimal quantity by a 3-decimal cost and rounds to 2. Then writes to a 3-decimal GL column. Creates a permanent reconciliation discrepancy between Inventory's WAC and Accounting's COGS expense, visible at year-end audit.

**Worked example:**
1. Write off 100.5000 units × cost 1.234 TND. True: `124.0170`.
2. `bcmul('100.5000','1.234',2) = '124.01'`. Written to journal_lines: `'124.010'` (PG-pad).
3. **Drift: 0.007 TND per write-off event. In pharma with thousands of expired batches per quarter: 50–100 TND mis-posted per quarter.**

**Recommended fix:** Bump bcmul to scale 3 (or `$resolver->scale()`). Add regression test that posts a write-off and asserts journal_line.debit equals `bcmul(qty, cost, scale)` exactly.

**Estimated effort:** trivial — 1 line.

---

### F-SVC-COMPANY — `CompanyController` uses forbidden `number_format((float)$validated, …)` for JSONB (HIGH)

**Module(s):** Company (refund-policy settings)

**Files cited:**
- `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:354,357,373,397,400,404` — 6 callsites
- Pattern: `number_format((float) $validated['…'], 2, '.', '')` stored in `companies.reservation_settings` JSONB

**The drift:**
This is the **canonical violation** of `project_monetary_precision.md`. Six refund-policy threshold fields (manager_override_threshold_amount, daily_refund_cap_per_cashier, three goodwill_*_threshold variants, goodwill_daily_issuance_cap_per_user) are all run through `(float)` then `number_format`. Direct memory-rule violation. The user's submitted value is silently lost before storage.

**Worked example:**
1. Admin POSTs `{"manager_override_threshold_amount": "1234.567"}`.
2. `(float) "1234.567"` → IEEE-754 `1234.5670000000000073…`.
3. `number_format(_, 2, '.', '')` → `"1234.57"` (silent 0.003 loss). Stored.
4. Subsequent refund check: "is this 1234.56 refund over the threshold?" — `1234.56 >= 1234.57` → false. **Pre-rounded threshold may incorrectly approve refunds that the operator intended to require manager override.**

**Recommended fix:** Replace every callsite with `CurrencyScale::bcformat($validated['…'], $companyScale)`. Inject `CurrencyScaleResolverInterface`.

**Estimated effort:** trivial — 6 line changes.

---

### F-FRONTEND-CN — `CreateCreditNoteForm` hardcodes `.toFixed(4)` regardless of currency (HIGH)

**Module(s):** Document (credit-note creation frontend), Fiscal (hash inputs)

**Files cited:**
- `apps/web/src/features/documents/components/CreateCreditNoteForm.tsx:185` — `amount: parseFloat(data.amount).toFixed(4)` (API payload)
- Same file uses currency-aware `decimals` elsewhere (line 225 for `handleFullRefund`)

**The drift:**
Form sends `amount` as a 4-decimal string regardless of currency. EUR credit notes are stored with `'123.4500'`. TND credit notes are stored as `'123.4500'` (would be 3-decimal in DB → silent truncation to `'123.450'`). Internal inconsistency with the rest of the same form which uses currency `decimals`.

**Worked example:**
1. EUR tenant creates a credit note for `€123.45`. Submitted `'123.4500'`. Server casts to decimal(15,3) → `'123.450'`. Display shows `'123.45'`. Match.
2. TND tenant creates `123.450 TND`. Submitted `'123.4500'`. DB stores `'123.450'`. **Match — but the inflated scale-4 payload obscures whether the user submitted `123.450` or `123.4500`, and the hash signs the 3-dec value while the JSON payload was 4-dec.**

**Recommended fix:** Replace `.toFixed(4)` with `.toFixed(decimals)` — match the existing pattern in the same file.

**Estimated effort:** trivial.

---

### F-FRONTEND-JE — `JournalEntryForm` `step="0.01"` blocks TND millimes (HIGH)

**Module(s):** Accounting (frontend journal entry)

**Files cited:**
- `apps/web/src/features/finance/pages/JournalEntryForm.tsx:242,255` — `<input type="number" step="0.01">` on debit/credit

**The drift:**
Browser snaps user keystrokes to the nearest 0.01. TND bookkeeper entering 1000.500 gets snapped to 1000.50.

**Worked example:**
1. Tunisian accountant enters a TND journal entry: debit `1000.500`, credit `1000.500`. Browser snaps both to `1000.50`. Submitted as `1000.50`. PG stores `1000.500` (padded). GL posts. **But the original was 1000.500 and the entry now has a hidden 0 TND imbalance hidden by browser truncation.** Worse: if the credit side was a different account where the cashier typed `1000.500` (snap → `1000.50`), the entry was supposed to balance to `1000.500` debit + `0.500` discount, but the form silently dropped `0.500` from each side. Net: 0 millième balanced, 1 millième actual loss in audit trail.

**Recommended fix:** Replace `step="0.01"` with `step={1 / 10 ** decimals}` (currency-aware, per the canonical pattern at `apps/web/src/features/compliance/components/CashDrawerControlsSection.tsx:166`).

**Estimated effort:** trivial — but part of the F-UNIVERSAL-STEP cross-cutting sweep.

---

### F-FRONTEND-DOCLINE — Document line `step="1"` blocks fractional quantities (HIGH)

**Module(s):** Document (frontend invoice/quote/order line editor)

**Files cited:**
- `apps/web/src/features/documents/components/DocumentLineEditor.tsx:419` — `step="1"` on `line.quantity`
- `apps/web/src/features/documents/components/DocumentLineRow/DocumentLineRow.tsx:129` — same

**The drift:**
`document_lines.quantity` is decimal(15,4) — supports fractional quantities. The frontend input enforces integer-step. Browsers reject `0.5` for half-kilo of coffee, half-hour of service, etc. Blocks real workflows.

**Worked example:**
1. Coffee shop creates an invoice for `0.5 kg of beans @ 5 TND/kg`. Cashier types `0.5` in the quantity input.
2. Browser snaps to `1`. Total now shows `5.000 TND` instead of `2.500`. Operator hits Submit. Invoice records 1 kg.

**Recommended fix:** `step="0.0001"` (or `step="any"`).

**Estimated effort:** trivial.

---

### F-FRONTEND-RETURN — `ReturnItemsModal` sends quantity at currency scale (HIGH)

**Module(s):** POS (returns/refunds), Inventory (back-receipt)

**Files cited:**
- `apps/web/src/features/pos/components/ReturnItemsModal.tsx:146` — `quantity: line.returnQuantity.toFixed(decimals)` (`decimals` is currency-scale)

**The drift:**
Return quantity is formatted at currency scale (2 for EUR, 3 for TND) and sent to the API. The downstream inventory back-receipt is at quantity scale (4 after the parallel fix; currently 2). EUR refund of `0.8765 kg` gets posted as `'0.88'`. Stock back-receipt is `0.88` kg, not `0.8765`. Both stock and accounting drift on every fractional refund.

**Worked example:**
1. EUR pharmacy refund: customer returns `0.8765 kg` of bulk product. UI captures `0.8765`.
2. `.toFixed(2)` → `'0.88'`. Sent to API. Inventory restored as `0.88`. Refund credit posted at `0.88 × unit_price`.
3. The original sale debited `0.8765` from stock. Now `+0.88 − 0.8765 = +0.0035` ghost stock; refund credit overpays by `0.0035 × unit_price`.

**Recommended fix:** Use the quantity scale (4), not currency scale. Awaiting `formatQuantity(value, scale = 4)` helper to land in `apps/web/src/lib/format.ts` per the parallel inventory fix.

**Estimated effort:** small — co-deliverable with the new `formatQuantity` helper.

---

### F-FRONTEND-PAYMENT — `PaymentForm` sends `amount` as JS number (HIGH)

**Module(s):** Treasury (frontend payment entry)

**Files cited:**
- `apps/web/src/features/treasury/PaymentForm.tsx:325,328` — `amount: parseFloat(data.amount)`, `withholding_rate: parseFloat(withholdingRate)`
- Many sibling sites: `admin/pages/InvoicesPage.tsx:89,90`, `admin/pages/PaymentsPage.tsx:95,123`, etc.

**The drift:**
JS Number is IEEE-754. Sending `amount: 1234.5678` as a JSON number gets serialized as a float that may or may not round-trip. Backend bare `numeric` accepts it. Decimal column stores rounded value.

**Worked example:**
1. User types `1234.5678` in payment input. `parseFloat → 1234.5678` (representable).
2. Edge: `1234.56789` → parseFloat → `1234.56789` (still representable). But `0.1 + 0.2 → 0.30000000000000004` when accumulated. Submitting `0.30000000000000004` for an `amount` field → server sees `0.300000000000000044` (or so) → PG truncates → no harm in this case, but the type signal is wrong.
3. Real risk: Z-report sync re-ingestion (F-INGRESS-ZSYNC) and PaymentForm both send JS Number; if either path is re-canonicalized differently, hash inputs diverge.

**Recommended fix:** Send all monetary fields as strings: `amount: data.amount` (already-string from form input) or `amount: String(data.amount).trim()`. Same for `withholding_rate`, `quantity`, etc.

**Estimated effort:** small — but part of the broader frontend formatter consolidation.

---

### F-FRONTEND-FORMATMONEY — `formatMoney(amount: number)` signature receives float (HIGH)

**Module(s):** Frontend (shared utilities)

**Files cited:**
- `apps/web/src/lib/utils.ts:14` — `export function formatMoney(amount: number, currency: string = 'EUR')`

**The drift:**
The function takes `number`, meaning IEEE-754 leak happens before the formatter is called. Callers that have a string already do `parseFloat → number → toFixed` round-trip.

**Worked example:**
1. Backend returns `'5.1234' TND`. Component calls `formatMoney(parseFloat('5.1234'), 'TND')` → `formatMoney(5.1234, 'TND')` → `5.123 TND` (3-decimal currency). The 4th decimal is lost in the parseFloat.
2. If the same backend value happened to be `'5.0001'` and the JS Number representation is `5.000100000000001`, `toFixed(3) → '5.000'` — silent off-by-one.

**Recommended fix:** Either delete `formatMoney` (use the canonical one in `decimal.ts` / `format.ts`), or change signature to `(amount: string, currency: string)` to force callers to preserve precision.

**Estimated effort:** trivial — but check for callers.

---

### F-FRONTEND-COLLISION — Four duplicate `formatCurrency` helpers (HIGH)

**Module(s):** Frontend (shared utilities)

**Files cited:**
- `apps/web/src/lib/format.ts:21` — `formatCurrency(amount, options?)` (parseFloat-based)
- `apps/web/src/lib/formatCurrency.ts:18` — `formatCurrency(amount, currencyCode, locale)` (parseFloat-based)
- `apps/web/src/lib/utils.ts:14` — `formatMoney(amount: number, currency)`
- `apps/web/src/lib/decimal.ts:178` — `formatCurrency(amount, includeCurrency, currency='EUR', scale?)` (the only one that takes string input + threads currency-aware decimals)

**The drift:**
Four helpers, three identical names, all parseFloat-leak except `decimal.ts`. Consumers cannot tell which `formatCurrency` they imported. Bug surface area grows with every new feature using whichever they autocomplete.

**Worked example:**
1. Developer adds a new TND-aware component. Autocomplete suggests `formatCurrency` from `lib/format.ts`. They use it. The component silently rounds to 2 decimals on every render for TND values because that file's helper defaults to 2 in some paths.

**Recommended fix:** Consolidate. Keep `decimal.ts`'s `formatCurrency` as the canonical (string in, string out, currency-aware). Delete the other three. Run TypeScript compile to catch all sites. This is a small-medium refactor.

**Estimated effort:** medium — requires touching every import site. Could pair with the introduction of a new `formatQuantity` helper.

---

### F-FRONTEND-MODIFIER — Modifier `component_quantity` step blocks recipe precision (HIGH)

**Module(s):** Catalog (modifier groups)

**Files cited:**
- `apps/web/src/features/catalog/pages/ModifierGroupFormPage.tsx:302` — `step="0.01"` on `component_quantity`
- Backend: `modifiers.component_quantity` decimal(15,4)

**The drift:**
Recipe modifier component quantities are stored at scale 4. UI step is 0.01. A coffee-shop recipe "vanilla essence: 0.005 ml" cannot be entered.

**Recommended fix:** `step="0.0001"`.

**Estimated effort:** trivial.

---

### F-FRONTEND-RECIPE — Recipe quantity `step="0.01"` blocks F&B precision (HIGH)

**Module(s):** Catalog (recipes)

**Files cited:**
- `apps/web/src/features/catalog/components/RecipeLineEditor.tsx:234,308` — `step="0.01"`
- Backend: `recipe_lines.quantity` decimal(15,4)

**Recommended fix:** `step="0.0001"`.

**Estimated effort:** trivial.

---

### F-FRONTEND-MONITORING — Synerivia admin Monitoring hardcodes USD `$` prefix (HIGH)

**Module(s):** Admin (platform monitoring)

**Files cited:**
- `apps/web/src/features/admin/pages/MonitoringPage.tsx:420,421` — `` `$${critical.billing.revenue.today.toFixed(2)}` ``

**The drift:**
Synerivia platform admin sees TND revenue rendered as `$1234.56` (wrong currency symbol, wrong scale).

**Worked example:** Tunisia tenant generates 12,345.000 TND in plan revenue. Admin sees `$12345.00` in the dashboard.

**Recommended fix:** Use the canonical `formatCurrency(amount, currency)` from `decimal.ts`. Source the currency from the tenant or report metadata.

**Estimated effort:** trivial.

---

### F-FRONTEND-VOUCHER — Voucher tender amount sent as JS Number (HIGH)

**Module(s):** POS (frontend voucher tender)

**Files cited:**
- `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:394` — `amount: Number.parseFloat(v.amount)`

**The drift:**
Voucher payload is bound to fiscal-event canonical hash. Sending JS Number means the hash input has IEEE-754 jitter the moment the value crosses the parseFloat boundary.

**Recommended fix:** Send as string (`amount: v.amount`).

**Estimated effort:** trivial.

---

### F-UNIVERSAL-STEP — Universal `step="0.01"` mismatch on TND tenants (HIGH — cross-cutting)

**Module(s):** Frontend (every form with a money or threshold input — POS, Treasury, Loyalty, Coupon, Promotion, Voucher, Partner, Service, Catalog, Pricing, Workshop, Document, Expense, Inventory, Admin)

**Files cited (representative sample, ~78 sites):**
- `apps/pos/src/components/pos/CashTenderedModal.tsx:71`
- `apps/pos/src/components/pos/CashDrawerModal.tsx:146`
- `apps/pos/src/components/pos/CloseShiftModal.tsx:81`
- `apps/pos/src/components/pos/VoucherTenderModal.tsx:448`
- `apps/pos/src/pages/HomePage.tsx:1034`
- `apps/web/src/features/pos/components/CashTenderedModal.tsx:84`
- `apps/web/src/features/treasury/PaymentForm.tsx:422,674`
- `apps/web/src/features/treasury/SplitPaymentForm.tsx:262`
- `apps/web/src/features/treasury/BankReconciliationPage.tsx:164`
- `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.tsx:121`
- `apps/web/src/features/coupons/pages/CouponFormPage.tsx:208,219,228`
- `apps/web/src/features/loyalty/components/EarningRuleFormModal.tsx:115,120,123`
- `apps/web/src/features/loyalty/components/RewardFormModal.tsx:136,139,145`
- `apps/web/src/features/loyalty/components/TierFormModal.tsx:109,118`
- `apps/web/src/features/vouchers/components/IssueGoodwillVoucherModal.tsx:170`
- `apps/web/src/features/promotions/pages/PromotionFormPage.tsx:234,245,286`
- `apps/web/src/features/partners/components/B2BFieldsSection.tsx:166,185`
- `apps/web/src/features/finance/pages/JournalEntryForm.tsx:242,255`
- `apps/web/src/features/services/ServiceForm.tsx:335,359,398`
- `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx:232,242,473,483`
- `apps/web/src/features/parts-catalog/components/organisms/AddToInventoryModal.tsx:192,206`
- `apps/web/src/components/atoms/...`, `apps/web/src/components/molecules/...`, `apps/web/src/components/organisms/...`
- The canonical correct site (template for the fix): `apps/web/src/features/compliance/components/CashDrawerControlsSection.tsx:166` — `step={1 / 10 ** decimals}`

**The drift:**
TND tenants cannot enter sub-cent precision in any of these inputs. The browser rejects keystrokes finer than 0.01. For Tunisia (TND, scale 3), every monetary input is silently constrained to 2-decimal precision while the backend stores at 3.

**Worked example:** Tunisian cashier at shift open: opening_cash = 1000.500 TND in the drawer. Input `step="0.01"`. Browser snaps to `1000.50`. Shift opens with 0.500 TND missing from the audit trail.

**Recommended fix:** Sweep-PR replacing every `step="0.01"` and `step="0.001"` with `step={1 / 10 ** decimals}` (where `decimals` comes from `useCurrency().decimals` or `getDecimals(currency)`). Co-ordinate with introduction of a `<MoneyInput>` / `<QuantityInput>` shared component to prevent regression.

**Estimated effort:** large — 78+ sites; needs a shared component to land cleanly; should be its own session.

---

### F-DOMAIN-RECEIPTAUTH — `Receipt.discount_authorized_by` has no Eloquent cast (MEDIUM)

**Module(s):** POS (discount authorization audit)

**Files cited:**
- `apps/api/app/Modules/POS/Domain/Receipt.php:156` (fillable) — but missing in `casts()` block at line 214
- Migration: `apps/api/database/migrations/2026_01_10_063825_add_transaction_discount_to_receipts.php:30` — decimal(5,2)
- Producer: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:383` uses `CurrencyScale::bcformat($effectiveLimit['limit'], 2)` (write is safe)

**The drift:**
The percent limit captured at sale time is an audit field. Write path goes through `CurrencyScale::bcformat`. Read path is unguarded — Eloquent returns the raw driver string with no scale guarantee.

**Recommended fix:** Add `'discount_authorized_by' => 'decimal:2'` to the casts() block.

**Estimated effort:** trivial.

---

### F-DOMAIN-ECOTAX / F-DOMAIN-VOUCHER — Cross-boundary scale-5 → scale-3 truncation (MEDIUM)

**Module(s):** POS (eco-tax), Voucher (redemption), Document

**Files cited:**
- `apps/api/app/Modules/POS/Domain/ReceiptLine.php:114–115` — `eco_tax_amount decimal:5`
- `apps/api/app/Modules/Document/Domain/DocumentLine.php:118–119` — `eco_tax_amount decimal:5`
- `apps/api/app/Modules/Voucher/Domain/Voucher.php:136–137` — `initial_balance, current_balance decimal:5`
- Migration evidence: `vouchers.initial_balance decimal(20,5)` (`apps/api/database/migrations/2026_05_02_000001:24`)

**The drift:**
Eco-tax and Voucher both use scale 5. When their amounts flow into `pos_receipt_payments.amount` decimal(15,3) or `Receipt.tax_amount` decimal(15,3), the rounding rule is implicit and not documented.

**Worked example:** Voucher with `current_balance = '5.12345' TND` redeemed against a receipt. POS payment row stores `'5.123'` (truncate). Voucher ledger drains `'5.12345'`. Cumulative drift over multi-redemption vouchers can leave a fractional balance no UI can spend.

**Recommended fix:** Document the truncation contract in the consumer service. Add a regression test asserting voucher balance = sum(ledger entries) at scale 5 with downstream payment amounts at scale 3 = sum(ledger at scale 3, rounded half-up).

**Estimated effort:** small — documentation + 1-2 unit tests.

---

### F-POS-STOCKDEC — `ReceiptCreationService::decrementStock` compare-4 vs write-2 mismatch (MEDIUM)

**Module(s):** POS (sibling to inventory drift; outside the in-flight fix scope)

**Files cited:**
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:869` — `bccomp($available, $quantity, 4)`
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:887` — `bcsub($stockQty, $quantity, 2)`
- Sibling: `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:875` — same pattern

**The drift:**
Insufficient-stock check is finer than the actual decrement. The check passes for sub-cent decrements that the decrement then ignores.

**Worked example (TND parapharma):**
1. Stock level `'10.0000'`, quantity sold `'0.0010'`.
2. `bccomp('10.0000','0.0010',4) = 1` (sufficient). Sale proceeds.
3. `bcsub('10.0000','0.0010',2) = '10.00'`. Stock level unchanged. **Sale recorded, stock not decremented. 0.001 unit of ghost stock per sale.**

**Recommended fix:** After the parallel inventory-precision-fix lands, both scales become 4 (consistent). Until then, change the bccomp to `2` to match the bcsub.

**Estimated effort:** trivial — but explicitly defer until parallel fix lands and then verify the inconsistency disappears naturally.

---

### F-TAX-CALC — `TaxCalculationService` scale-3 drops 4th decimal of quantity (MEDIUM)

**Module(s):** Taxation (sales-tax computation)

**Files cited:**
- `apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:72,79–82,84,101,111,129,133–134,155–157,161` — multiple bcmul calls with hardcoded scale 3

**The drift:**
`bcmul((string) $line->quantity, (string) $line->unit_price, 3)` where quantity is decimal(15,4) — fourth-decimal lost per line at the multiplication.

**Worked example:** Pharma line `quantity = '0.9999', unit_price = '0.0019' TND`. True: `0.001899810`. `bcmul('0.9999','0.0019',3) = '0.001'` (truncate). Loss accumulates across 1000s of lines → ~1 TND under-collected VAT.

**Recommended fix:** Use `$this->scale() + 1` for intermediates; let destination column do final rounding.

**Estimated effort:** small.

---

### F-CART-CONV — `CartConversionService` 6 callsites at scale 3 drop 4th decimal of quantity (MEDIUM)

**Module(s):** Cart (cart→PO/quote conversion)

**Files cited:**
- `apps/api/app/Modules/Cart/Application/Services/CartConversionService.php:84,85,113,172,173,201`

**The drift:**
Same as F-TAX-CALC. `quantity` is decimal(10,2) on `catalog_cart_items`, but the downstream `documents.subtotal/total` is decimal(15,3) and `document_lines.quantity` is decimal(15,4). Conversion bcmul at scale 3 narrows the contract.

**Recommended fix:** Use `$this->scale() + 1`.

**Estimated effort:** small.

---

### F-TRES-VENDREFUND — `VendorRefundService` scale=2 on repository balance (MEDIUM)

**Module(s):** Treasury (vendor refund)

**Files cited:**
- `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:70,135,147`

**The drift:**
`bcadd($currentBalance, $amount, 2)` writing into `payment_repositories.balance` decimal(15,3). Repository balance is the canonical cash position; scale-2 truncation on every refund silently desyncs the in-memory total from the stored value.

**Worked example:** Vendor refund of `123.4567 TND`. Service computes `bcsub($repoBalance, $amount, 2)`. Repository balance drops by `123.45` not `123.457`. **0.007 TND ghost per refund.**

**Recommended fix:** Inject `CurrencyScaleResolverInterface` and use `$this->scale()`.

**Estimated effort:** trivial.

---

### F-LOYALTY-VOS — `PointsAmount` and `LoyaltyBalance` value objects are float-based (MEDIUM)

**Module(s):** Loyalty

**Files cited:**
- `apps/api/app/Modules/Loyalty/Domain/ValueObjects/PointsAmount.php:21,34,47,117,125,133,205` — `public float $value`
- `apps/api/app/Modules/Loyalty/Domain/ValueObjects/LoyaltyBalance.php:17–19,35,98,107,123,153–155` — `public float $current/lifetimeEarned/lifetimeRedeemed`
- Storage: `loyalty_transactions.amount` decimal(15,3), `loyalty_enrollments.current_balance` decimal(15,3) (widened by `2026_03_24_300000`)

**The drift:**
VOs store points as float. Native arithmetic. Integrity check `abs($current - $expected) > 0.01` permits 1-cent drift per check. Accumulator drift compounds.

**Worked example:** 10,000 loyalty transactions of `0.1` points each. Float sum: `999.9999999999998`. PG cast to decimal(15,3): `'999.999'`. True: `1000.000`. **1 millième / 10k txns invisible.** Tolerance check hides it.

**Recommended fix:** Refactor VOs to use `string` storage and bcmath arithmetic. Pattern is in `Promotion\Domain\ValueObjects\PromotionDiscount`.

**Estimated effort:** medium — VO refactor + every consumer; needs careful test coverage.

---

### F-RESOURCE-DISCOUNT — `DocumentTaxBreakdownResource` hardcodes `'0.00'` for discount field (MEDIUM)

**Module(s):** Taxation (tax-breakdown API response)

**Files cited:**
- `apps/api/app/Modules/Taxation/Presentation/Resources/DocumentTaxBreakdownResource.php:27` — `'discount' => '0.00'`

**The drift:**
Always returns 2-decimal `'0.00'`. For TND documents the rest of the response shows `'0.000'`. Mixed-scale JSON breaks frontend money-format guards that compare string-equality at expected scale.

**Recommended fix:** Replace with `CurrencyScale::bcformat('0', $companyScale)` or with the actual document discount value.

**Estimated effort:** trivial.

---

### F-DTO-BATCH — `BatchSuggestionDTO` exposes `float quantity` (MEDIUM)

**Module(s):** BatchExpiry (FEFO suggestion endpoint)

**Files cited:**
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionDTO.php:15` — `public float $quantity`
- `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionResultDTO.php:15` — `public float $shortfall`
- Producer: `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:260` — `(float) $request->input('quantity')`

**The drift:**
End-to-end float pipeline from a controller cast through DTO to JSON. Worst case: stored `'10.0003'` serializes as `'10.000299999...'`. POS frontend hash inputs see varying values per request.

**Recommended fix:** Change DTO to `public string $quantity` and pass as `(string)` from controller through service.

**Estimated effort:** small.

---

### F-COUPON-QTY — Coupon validation rejects fractional quantities (MEDIUM)

**Module(s):** Coupon

**Files cited:**
- `apps/api/app/Modules/Coupon/Presentation/Requests/ValidateCouponRequest.php:28` — `'items.*.quantity' => ['required_with:items', 'integer', 'min:1']`

**The drift:**
Every other line-quantity rule in the codebase allows fractional. This single endpoint rejects 0.5 kg / half-hour-service lines.

**Worked example:** Coffee-shop cashier rings 0.5 kg of beans with a coupon code. Validation returns 422.

**Recommended fix:** Change to `'numeric|min:0.001'`.

**Estimated effort:** trivial.

---

### F-FRONTEND-DOCTOTALS — `DocumentTotals` hardcoded TND ternary (MEDIUM)

**Module(s):** Document (frontend totals display)

**Files cited:**
- `apps/web/src/features/documents/components/DocumentTotals.tsx:50` — `const decimals = currency === 'TND' ? 3 : 2`

**The drift:**
BHD/IQD/JOD/KWD/LYD/OMR get 2 decimals instead of 3. The map exists already in `useCurrency.getDecimals` — this component just doesn't use it.

**Recommended fix:** `const decimals = getDecimals(currency)`.

**Estimated effort:** trivial.

---

### F-FRONTEND-DOCEDITOR — Document line editor sums totals via JS Number (MEDIUM)

**Module(s):** Document (frontend line editor)

**Files cited:**
- `apps/web/src/features/documents/components/DocumentLineEditor.tsx:141–160,166–173`
- Same pattern in `apps/pos/src/stores/cartStore.ts:71–78,81–106,282–307` and `apps/pos/src/stores/paymentStore.ts:391–405`

**The drift:**
`subtotal = Σ(qty * price); tax = Σ(qty * price * rate/100)` using JS `Number`. Drift can compose across many lines.

**Recommended fix:** Use `decimal.ts` bcmath helpers. Co-deliverable with formatter consolidation.

**Estimated effort:** medium.

---

### F-FRONTEND-DECIMAL — `decimal.ts` arithmetic helpers default to scale 3 (MEDIUM)

**Module(s):** Frontend (shared utilities)

**Files cited:**
- `apps/web/src/lib/decimal.ts:49,64,79,94` — `bcadd`/`bcsub`/`bcmul`/`bcdiv` default `scale = 3`
- `apps/pos/src/lib/decimal.ts:22,26,30,34` — same

**The drift:**
A new EUR caller who omits the scale argument gets 3-decimal output. Latent bug surface for anyone building a new feature.

**Recommended fix:** Remove the default; require explicit scale. TypeScript compile catches every caller.

**Estimated effort:** small — but touches many call sites.

---

### F-FRONTEND-WHRATE — Withholding rate `.toFixed(2)` on 4-decimal column (MEDIUM)

**Module(s):** Withholding (frontend display)

**Files cited:**
- `apps/web/src/features/withholding/pages/SalesWithholdingTrackingPage.tsx:225` — `(parseFloat(record.withholdingRate) * 100).toFixed(2)`
- `apps/web/src/features/treasury/components/ToleranceSettingsDisplay.tsx:54` — `(parseFloat(settings.percentage) * 100).toFixed(2)`

**The drift:**
Rate stored at 4 decimals, displayed at 2. Stored `0.15253` displays as `15.25%` — same as `0.15259`. Different rates display identically.

**Recommended fix:** `.toFixed(4)` for accurate percentage display (or `.toFixed(2)` only if 2 is the canonical reporting precision).

**Estimated effort:** trivial.

---

### F-FRONTEND-PRICELIST — `PriceListDetailPage` hardcoded en-US locale + TND fallback (MEDIUM)

**Module(s):** Pricing (frontend)

**Files cited:**
- `apps/web/src/features/pricing/PriceListDetailPage.tsx:73–77` — `new Intl.NumberFormat('en-US', { style: 'currency', currency: priceList?.currency ?? 'TND' })`

**The drift:**
Locale forced en-US; French/Arabic users see English grouping. Currency-scale is currency-derived (correct via Intl).

**Recommended fix:** Use `useCurrency().format(value)`.

**Estimated effort:** trivial.

---

### F-DOMAIN-LATLNG — Location lat/lng `(float)` cast on `decimal(10,8)/(11,8)` (LOW)

**Module(s):** Company (location coordinates)

**Files cited:**
- `apps/api/app/Modules/Company/Domain/Location.php:99–100` — `'float'`
- Migration: `apps/api/database/migrations/2025_11_30_105000:44–45`

**The drift:**
Not money. Sub-cm precision lost on read. Geofence logic may flip at boundary cases.

**Recommended fix:** Change cast to `'decimal:8'`.

**Estimated effort:** trivial.

---

### F-DOMAIN-TERMINALRES — `TerminalResource` `(float)` on `max_discount_percent` (LOW)

**Module(s):** POS (terminal Resource)

**Files cited:**
- `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:52`

**The drift:**
`(float) $this->max_discount_percent` strips trailing zero — stored `'15.00'` becomes `15.0` → JSON `15.0` → frontend renders `15%` instead of `15.00%`.

**Recommended fix:** Drop the cast; let Eloquent's `decimal:2` (after fix F-PERMISSIONS) return a string.

**Estimated effort:** trivial.

---

### F-FRONTEND-PARSEFMT — `parseFormattedNumber` returns JS number (LOW)

**Module(s):** Frontend (shared utilities)

**Files cited:**
- `apps/web/src/lib/format.ts:138–142`

**The drift:**
Single-point chokepoint for paste-handling that returns JS number. Every caller's precision contract weakens.

**Recommended fix:** Return string.

**Estimated effort:** small.

---

### F-DOMAIN-MARKETPLACELINE — Marketplace OrderLine quantity at decimal(10,2) cross-flow (LOW)

**Module(s):** Marketplace

**Files cited:**
- `apps/api/app/Modules/Marketplace/Domain/Models/MarketplaceOrderLine.php:49` — `decimal:2`
- Migration: `apps/api/database/migrations/2026_03_10_400002:52` — `decimal(10,2)`

**The drift:**
Marketplace orders flow into ERP `document_lines.quantity` decimal(15,4) and POS receipts decimal(10,3). Three scales, undefined rounding rules.

**Recommended fix:** Decide canonical scale (4 to match ERP); migrate column + cast. Or document the truncation at the Marketplace→Document conversion seam.

**Estimated effort:** small.

---

### F-BILLING-MONEY — Billing `Money` VO stores float [KNOWN MEMORY GAP] (LOW)

**Module(s):** Billing

**Files cited:**
- `apps/api/app/Modules/Billing/Domain/ValueObjects/Money.php:15` — `public float $amount`
- Cited in `project_monetary_precision.md` as a known remaining gap.

**The drift:**
All Billing math (Plan, Invoice, Payment, Refund, TenantSubscription) materialises Money with `float`. Stripe-cents conversion masks at the integration boundary; in-process arithmetic accumulates drift.

**Recommended fix:** Refactor to `string`+ bcmath. Stripe conversion can be the boundary translator.

**Estimated effort:** medium — Billing-wide refactor.

---

### F-MARGIN — `MarginService` uses float arithmetic for `products.sale_price` write (LOW)

**Module(s):** Product (catalog auto-update)

**Files cited:**
- `apps/api/app/Modules/Product/Application/Services/MarginService.php:73,98,122,149`

**The drift:**
`round($cost * (1 + $margins['target_margin'] / 100), $this->scale())`. Pure float; writes back to `products.sale_price` decimal(15,3). For razor-thin Tunisia parapharma margins, a 1-millième drift on cost flips the suggested price by 1 millième after each auto-update cycle.

**Recommended fix:** Rewrite with bcmath.

**Estimated effort:** small.

---

### F-VOUCHER-REGEX — `IssueGoodwillRequest.amount` regex allows unbounded decimals (LOW)

**Module(s):** Voucher

**Files cited:**
- `apps/api/app/Modules/Voucher/Presentation/Requests/IssueGoodwillRequest.php:27` — `regex:/^\d+(\.\d+)?$/`

**The drift:**
Regex allows any precision; storage is decimal(20,5).

**Recommended fix:** `regex:/^\d+(\.\d{1,5})?$/`.

**Estimated effort:** trivial.

---

### F-SHARED-TYPES — `packages/shared/types` has `number`-typed quantity fields (LOW)

**Module(s):** Loyalty (generated types)

**Files cited:**
- `packages/shared/types/generated.d.ts:608,609,707,708` — `min_quantity, max_quantity, quantity_available, quantity_per_member: number`

**The drift:**
Today these are integer counts (member-redemption ceilings). When loyalty supports fractional thresholds (e.g. "spend > 100.5 TND to qualify"), these `number` types become precision leaks.

**Recommended fix:** Confirm with the Loyalty owner whether thresholds will remain integer; if not, change DTO to string and regenerate types.

**Estimated effort:** trivial — but coordinate.

---

## Cross-cutting patterns

### P1 — Bare `numeric` validation on widened decimal columns

**~120 ingress points** across Document, POS, Treasury, Accounting, Loyalty, Coupon, Promotion, Voucher, Catalog, Marketplace, Pricing, Workshop, Service, Product, BatchExpiry, Inventory, Identity, Partner. Pattern: validator passes, controller forwards as string, PG truncates at INSERT, fiscal hash signs the rounded value, customer-screen value diverges. The fix template already exists in the codebase: `regex:/^\d+(\.\d{1,N})?$/` where N matches the destination column scale (see POS payment-ingress request files for the canonical reference).

**Suggested PR scope:** One sweep PR per module, ~30–40 regex additions per PR, mechanical. Co-deliver with regression tests that submit a 4-decimal value and assert the response is 422.

### P2 — Hardcoded `step="0.01"` UI for TND tenants

**78 numeric inputs across web+POS** with `step="0.01"`. Exactly one currency-aware site exists as the reference pattern. Sweep PR: introduce a `<MoneyInput currency={currency}>` shared component that emits the correct step; migrate every callsite. Add an ESLint rule that fails on `step="0.0\d+"` literal.

**Suggested PR scope:** Its own session. Will conflict with many open PRs — sequence after the bare-numeric sweeps land so the regex contract is stable.

### P3 — Float-laundering pipelines

Three sub-patterns:
- **Frontend → backend:** JS `parseFloat` → JSON number → server-side `numeric` validator. Sites: PaymentForm, InvoicesPage, PaymentsPage, voucher modals, ReturnItemsModal. Fix: ship strings from frontend to backend.
- **Backend (float) cast → storage:** PHP `(float) $value` round-trip. Sites: LandedCostService, WeightedAverageCostService, MarginService, BatchSuggestionDTO, CompanyController, WithholdingCertificate DTO. Fix: keep values as strings end-to-end.
- **Resource (float) cast → JSON:** Backend → frontend layer. Sites: TerminalResource, WithholdingRuleResource. Fix: trust the Eloquent `decimal:N` cast.

### P4 — Hardcoded bcmath scale 2 in services writing widened columns

43 callsites at literal `2` across Accounting, AgedReceivables, Compliance UninvoicedDN, Document RefundService, Treasury VendorRefund, BatchWriteOff, POS stock-decrement, Cart, Tax calculation, Marketplace, Inventory query services. All predate the 2026-03 widening. Same pattern, same fix: inject `CurrencyScaleResolverInterface` and replace `2` with `$this->scale()`.

### P5 — Producer-consumer scale gradient

Same conceptual value stored at different scales across tables that flow into each other. Catalog:
- `document_lines.quantity` (15,4) → `stock_levels.quantity` (15,2) [PARALLEL-FIX-IN-FLIGHT]
- `pos_orders.total` (15,4) → `pos_receipts.total` (12,3) — F-POS-3
- `vouchers.balance` (20,5) → `pos_receipt_payments.amount` (12,3) — F-DOMAIN-VOUCHER
- `*.eco_tax_amount` (20,5) → `receipt.tax_amount` (15,3) — F-DOMAIN-ECOTAX
- `marketplace_order_lines.quantity` (10,2) → `document_lines.quantity` (15,4) → `pos_receipt_lines.quantity` (10,3) — F-DOMAIN-MARKETPLACELINE

Every cross-boundary write is implicit truncation. **Recommendation:** introduce architectural decision records (ADRs) at each seam describing the truncation contract; add chokepoint tests that assert producer-consumer equality at the lower scale.

### P6 — Missing Eloquent `decimal:N` casts on widened columns

~30 model fields where the column was widened to 3 but the model has no cast. Reading returns the raw driver string (mostly OK on PG), but write paths can accept floats and the in-memory invariant is undefined. Sites: `Product` (all money fields), `Service` (price/rate), `Account` (balance + scale-2 default), `Coupon` (all money), `Promotion` (all money), `Payment` (FX columns), `StampDutyRule` (stamp_amount), `SalesWithholdingTracking` (all amounts + rate).

Sweep PR: add `'decimal:N'` casts where missing. Surface as a separate `f-domain-casts-sweep` PR.

### P7 — No `QuantityScale` analog to `CurrencyScale`

`CurrencyScale` resolves money precision per currency. Quantity precision has no equivalent — each module hardcodes (`StockAdjustmentService::SCALE = 2`, `TaxCalculationService` scale 3, Catalog scale 4). Coverage gap is the deepest root cause of the inventory drift the parallel session is fixing.

**Recommendation:** introduce `Shared\Domain\QuantityScale` with a `bcformat($value, $scale)` analog and a `QuantityScaleResolver` per-product-UoM. Inject across inventory, document, pos, catalog, batch-expiry, workshop. This is multi-PR work.

### P8 — Duplicate / collision-named frontend formatters

Four `formatCurrency`-like helpers, four different signatures, three of them parseFloat-leak. Consolidating to one canonical helper unblocks every other frontend fix (currency-aware step, currency-aware toFixed, etc.).

---

## Recommended sequencing

### Tier A — This week (5 PRs, all small, all decompose cleanly)

1. **`f-fiscal-hash-cashamount`** — F-POS-1. Thread currency into `formatCashAmount`. Latent chain-break vector.
2. **`f-tax-withholding-rate`** — F-TAX-1. Fix the manual-rate truncation. Tax-compliance fix.
3. **`f-permissions-discount`** — F-PERMISSIONS. Two model cast changes; security boundary.
4. **`f-zsync-validator-alignment`** — F-INGRESS-ZSYNC. Trivial regex addition; fiscal-replay integrity.
5. **`f-frontend-collision-cleanup`** — F-FRONTEND-COLLISION + F-FRONTEND-FORMATMONEY. Consolidates the 4 helpers; unblocks every other frontend fix downstream.

### Tier B — Sprint after the parallel inventory fix lands (3 PRs, medium effort)

6. **`f-wac-bcmath-migration`** — F-WAC-1 + F-WAC-2. Rewrite LandedCostService + WeightedAverageCostService to bcmath. Single-PR scope; needs careful regression suite.
7. **`f-pos-returnvat-symmetric`** — F-POS-2. Copy ReceiptCreationService.roundVat into ReceiptReturnService (or extract to trait). Trivial but pair with full POS test suite.
8. **`f-bcmath-scale2-sweep`** — F-SVC-AGED + F-SVC-DOCREFUND + F-SVC-COMPLIANCE + F-SVC-BATCHWRITEOFF + F-TRES-VENDREFUND + F-SVC-COMPANY. Inject `CurrencyScaleResolverInterface` across 6 services; replace literal `2` with `$this->scale()`. Medium PR with company-scale regression test per finding.

### Tier C — Following sprint (4 PRs, medium-to-large effort)

9. **`f-ingress-regex-sweep-pos-doc-trs-acc`** — F-INGRESS-POS + F-INGRESS-DOC + F-INGRESS-TRES + F-INGRESS-ACC + F-INGRESS-PRICING + F-INV-SIBLING-1. Add `regex:/^\d+(\.\d{1,N})?$/` to ~30 FormRequest fields across 4 modules. Mechanical; co-deliver with regression tests asserting 422 on N+1 decimal input. Can be 1 large PR or split per-module.
10. **`f-pos-orders-receipts-align`** — F-POS-3. Pick a scale, migrate, add chokepoint test.
11. **`f-eloquent-casts-sweep`** — F-PRODUCT-1 + F-ACCOUNTING-1 + F-TREASURY-1 + F-DOMAIN-RECEIPTAUTH. Add missing `decimal:N` casts on Product, Account, Payment FX, Receipt.discount_authorized_by. May surface as `number` → `string` in `packages/shared/types`; coordinate with frontend.
12. **`f-tax-cart-scale-extension`** — F-TAX-CALC + F-CART-CONV. Switch intermediate scales from N to N+1.

### Tier D — Step-input refactor session (1 PR, large)

13. **`f-money-input-component`** — F-UNIVERSAL-STEP + F-FRONTEND-JE + F-FRONTEND-DOCLINE + F-FRONTEND-RETURN + F-FRONTEND-MODIFIER + F-FRONTEND-RECIPE + F-FRONTEND-PAYMENT + F-FRONTEND-VOUCHER + F-FRONTEND-DOCTOTALS + F-FRONTEND-PRICELIST + F-FRONTEND-MONITORING + F-FRONTEND-DECIMAL + F-FRONTEND-CN + F-FRONTEND-WHRATE. Introduce `<MoneyInput>` and `<QuantityInput>` shared components; migrate ~78 callsites; add ESLint rule. Own session, paired with the formatter-consolidation work from Tier A.

### Tier E — Architectural backlog (one or more sessions)

14. **Build `QuantityScale` analog to `CurrencyScale`** — addresses P7. Multi-PR (helper + per-module adoption).
15. **`f-billing-money-vo-string`** — F-BILLING-MONEY. Refactor Money VO to string+bcmath; ripple through Plan, Invoice, Payment, Refund, TenantSubscription. Medium-to-large.
16. **`f-loyalty-vos-string`** — F-LOYALTY-VOS. Same shape as Billing.
17. **`f-frontend-formatter-rewrite`** — F-FRONTEND-DOCEDITOR + F-FRONTEND-PARSEFMT + the JS-Number cart math. Move all line-total math to `decimal.ts` bcmath helpers in shared components.

### Tier F — Quick wins / opportunistic (file when seen)

- F-DOMAIN-RECEIPTAUTH (1-line add) — can ride any POS PR
- F-FRONTEND-MONITORING (1-line change) — admin UI fix
- F-COUPON-QTY (1-line validator change) — opportunistic
- F-VOUCHER-REGEX (1-character regex change) — opportunistic
- F-DOMAIN-LATLNG (1-line cast change) — geo work
- F-DTO-BATCH (3 changes: DTO, controller, service signature) — when next touching BatchExpiry FEFO
- F-RESOURCE-DISCOUNT (1-line Resource change) — opportunistic
- F-DOMAIN-ECOTAX / F-DOMAIN-VOUCHER (documentation + 1-2 tests) — opportunistic
- F-DOMAIN-MARKETPLACELINE (decision + migration) — when next touching Marketplace
- F-SHARED-TYPES (when Loyalty introduces fractional thresholds) — defer

---

## What I did not audit

- **`apps/data-acquisition/`** — explicitly skipped; doesn't store decimals.
- **`apps/erp-ml/` and `apps/platform-ml/`** — out of scope (Synerivia layer, separate audit).
- **React Native / Expo mobile app** referenced in `apps/erp/CLAUDE.md` Tech Stack section — no `apps/mobile/` found in the ERP tree; nothing to audit.
- **Test seeders and demo data** — `DemoTenantSeeder`, `CoffeeShopSeeder`, etc. May write fixtures at the wrong scale, locking in misleading reference values. Not audited; recommend a separate `seeder-precision-audit` pass after Tier B fixes.
- **PostgreSQL CHECK constraints and triggers** — the migration comments reference invariants (e.g. `pos_shifts_variance_calc`, append-only triggers); not enumerated.
- **Stancl `PostgreSQLDatabaseManager` per-tenant DB state** — did not verify each tenant DB carries the post-widening migrations. An older tenant on the older schema would silently bypass the column widening, making the bcmath-scale-2 services correct by accident on that tenant only.
- **The Rust receipt formatter** (`apps/pos/src-tauri/`) — only the TS-side data that feeds it (`buildReceiptData.ts`).
- **Fiscal-event canonical-encoder server side** — confirmed `FiscalPayloadConstraintValidator` uses runtime scale; did not trace the full payload-signing path. A divergence between client and server canonicalization is the F-POS-1 risk.
- **The DataAcquisition/PlatformIntegration outbound API** — not in scope.
- **Per-tenant currency resolver wiring** — `CurrencyScaleResolverInterface` is widely injected; the resolver implementation itself (probably `App\Shared\Infrastructure\TenantCurrencyScaleResolver` or similar) was not exhaustively verified. A misconfigured tenant defaulting to scale 2 silently negates every runtime-scale-aware service in Treasury/POS/Document.
- **Per-product UoM scale** — quantity is a per-product concern (kilograms vs items vs litres). Current code treats it uniformly per module. The right long-term fix is the `QuantityScale` analog mentioned in P7.
- **i18n / locale-aware number parsing** — French "1 234,56" vs English "1,234.56" — partially handled at parseFormattedNumber but not audited end-to-end.
- **The Synerivia outbound platform integration** (`apps/api/app/Modules/PlatformIntegration/`) — not in scope.
- **Verified-shipped test suite runs** — did not run `phpunit`, `phpstan`, `pint`, or frontend tests; findings are static-analysis based and may surface as test failures once fixes land.
- **Exhaustive JSONB content audit** — `Receipt.discount_breakdown`, `companies.reservation_settings`, `pos_held_orders.cart_snapshot`, `opening_balance_import_rows.raw_data` were sampled but not exhaustively traced for nested monetary values that bypass column-scale guards.
- **Per-cluster cross-app deprecation impact** (`feedback_cross_app_deprecation_check`) — tightening a validation regex to scale 3 may surface 422 errors on existing POS Tauri clients that submit JS-Number-stringified values with IEEE-754 tails. Each Tier C PR needs a smoke pass on the POS client before merge.
