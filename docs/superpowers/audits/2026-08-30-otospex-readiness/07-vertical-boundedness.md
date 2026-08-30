# Vertical boundedness of the 2026-08-30 Otospex readiness findings

**Date:** 2026-08-30 · **Question:** are the audit's findings bounded to the Otospex automotive vertical (Workshop/garage lanes), or do they also affect IziPOS (generic retail, e.g. the TN parapharmacy tenant #1)?
**Method:** read-only code verification in `apps/erp` and `apps/platform`. Seven parallel Opus agents plus direct orchestrator reads. No file was modified; no test suite was run. Every claim below carries `file:line`. Paths are relative to `apps/erp/apps/api` unless prefixed otherwise.

**Headline:** the audit's framing — "these are garage-tail defects" — is **half right and dangerously half wrong**. A2 (reservations), A4/A6 (PDFs) and all of B1 are genuinely Otospex-bounded. But **A1 (gross-in-net `line_total`) and A3 (no goods lane) each have a second, independent instance on the shared POS account-charge lane**, which is reachable by the TN parapharmacy tenant and is *less* protected than the Workshop one — unticketed, untested, and not even stamped, so the affected population is not findable after the fact. The sourcing/RFQ gaps are not vertical-scoped at all, and the security findings are platform-wide.

---

## 1. The mechanism that makes boundedness real (or not)

Two structural facts decide every verdict below.

**Workshop/Vehicle are hard-gated.** `Vehicle`, `Workshop` and `PlatformIntegration` appear **only** inside the six otospex vertical blocks of `config/verticals.php` — mechanic `:21-33`, body_shop `:202,204`, parts_retailer `:232`, car_glass `:261,263`, tire_shop `:291`, service_station `:325`. Parapharmacy (`:344-361`) has none of them, in neither `default_modules` nor `compatible_extras` (`:340`). Every Workshop/Vehicle/Scheduling authenticated route group carries the matching middleware — `Workshop/WorkOrder/Presentation/routes.php:26`, `Workshop/Bundle/Presentation/routes.php:14`, `Workshop/Technician/Presentation/routes.php:29`, `Scheduling/Presentation/routes.php:52` (all `module:Workshop`), `Vehicle/Presentation/routes.php:22` (`module:Vehicle`) — and `RequireModule.php:62-64` `abort(403, "Module '{$module}' is not enabled for this business type")`. **An IziPOS tenant cannot execute a single line of Workshop code.**

**But the shared engine is mostly ungated.** `ModuleName.php:16-39` has 24 cases; `Procurement`, `PurchaseHub`, `Marketplace` and `Cart` are **not among them**, so those surfaces *cannot* be module-gated and are not. `Procurement/Presentation/routes.php:27-32` has no `module:` middleware, and its own header comment `:20-24` says so deliberately, relying on per-route `can:` instead. Anything written into the unified `documents` table by a shared lane inherits every shared reader — GL, VAT declaration, PDF — regardless of vertical.

So the correct question is never "is the module Otospex-only?" but **"is there a second writer of the same wrong value on a shared lane?"** For A1 and A3, there is.

---

## 2. Verdict table

| # | Issue | Classification | Key evidence | Affects tenant #1 (parapharmacy)? | Affects Otospex garage? |
|---|---|---|---|---|---|
| 1 | **A1 gross-in-net `line_total`** | **SHARED-ENGINE** (two independent writers) | Workshop: `Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:218`. **POS: `Document/Application/Services/POSAccountChargeDraftService.php:92`**. Column is NET by contract: `Document/Domain/DocumentLine.php:258-263` | **YES** — via POS account-charge (credit) sales | YES — every WO-generated quote + invoice |
| 1b | A1 blast radius through shared readers | SHARED-ENGINE | `Accounting/.../AccountingService.php:649,811,1941`; `Taxation/.../PostedLineTaxSnapshotBuilder.php:160-161,180`; `resources/views/documents/components/line_items.blade.php:68`; `Document/Application/Services/ProformaGrossAmountResolver.php:76` | YES (readers are shared) | YES |
| 2 | **A2 reservation leak** | **BOUNDED (Otospex-only)** | `Workshop/.../WorkOrderTransitionService.php:116,118`; `Workshop/.../InventoryReservationAdapter.php:60` (`expiresAt: null`); sweeper blind via `Inventory/Domain/StockReservation.php:114` | **NO** — POS/transfers never reserve | YES — unbounded, permanent |
| 2b | Marketplace `cancelOrder` mis-keyed release | SHARED-ENGINE (minor) | writes `sourceId: $cartItemId` at `Marketplace/.../MarketplaceOrderService.php:72`, releases by `$order->id` at `:195` | Yes but bounded by 24h expiry | Yes, same |
| 3 | **A3 WO parts never leave stock** | **SHARED-ENGINE** (second instance) | Workshop: `DocumentGenerationAdapter.php:107-125`, `Document/Domain/Enums/PostingContext.php:16-23,60`. **POS: `POSAccountChargeDraftService.php:80` (`product_id => null`) + `POS/Application/Projections/AccountChargeReceiptProjection.php` (zero stock refs) + `PosCoreReceiptProjection.php:216-219` (`SALE_RECEIPT` only)** | **YES** — via POS account-charge | YES |
| 3b | Delivery note silent skip on unresolvable location | SHARED-ENGINE (minor) | `Document/Domain/Services/DeliveryNoteService.php:311-313` (`continue`) | Yes | Yes |
| 4 | **A4 invoice PDF plate / A6 no job card** | **BOUNDED (Otospex-only)** | writer `WriteDocumentVehicleContextForWorkOrderInvoice.php:72-80` (`license_plate`/`brand`) vs blade `components/totals.blade.php:15,18` (`make`/`registration_number`); triple-gated at `totals.blade.php:11`, `Document/Application/Services/DocumentPdfService.php:199`, `:167-171` | **NO** — no vehicle context row ⇒ block skipped entirely | YES |
| 5 | **B1 Otospex delivery path** (build, campaign, vehicle import, B4–B8) | **BOUNDED (Otospex-only)** | `apps/web/src/contexts/ProductConfigContext.tsx:59-74`; `apps/web/Dockerfile:53`; `docker-compose.staging.yml:269`; `apps/web/e2e/campaign/journey.ts:325`; `Import/Domain/Enums/ImportType.php:9-43` | **NO** (one cosmetic exception, row 5b) | YES — blocks garage delivery entirely |
| 5b | Nine FE workshop routes lack `ModuleGuard` | SHARED-ENGINE (cosmetic) | `apps/web/src/routes/index.tsx:1644,1654,1664,1678,1688,1698,2835,2845,2855`; `RequirePermission.tsx:48-63`; admin holds all perms `RolesAndPermissionsSeeder.php:545` | Cosmetic only — backend 403s | n/a |
| 6a | **Platform `TenantOrderController` IDOR** | **PLATFORM-WIDE** | `apps/platform/.../PurchaseHub/Presentation/Controllers/TenantOrderController.php:70,81,89` unscoped; `:32,49` tenant from `partner->metadata['tenant_id']` never written | Yes in principle — **not reachable today** (no ERP FE, key unset) | Same |
| 6b | Expired API keys still authenticate | PLATFORM-WIDE (**latent**) | `apps/platform/.../Middleware/AuthenticateApiKey.php:30-33`; `ApiKey::isValid()` `:96` and `ApiKeyService::validate()` `:71` have zero production callers | Latent — nothing sets `expires_at` | Same |
| 6c | PurchaseHub unmetered/unlogged (platform) + ungated (ERP) | **PLATFORM-WIDE / SHARED-ENGINE** | platform `PurchaseHub/Presentation/routes.php:16` = `[AuthenticateApiKey]` only vs `Automotive/Presentation/routes.php:14`; **ERP `PurchaseHub/Presentation/routes.php:12-21` — no `module:`, no `can:`, no `authorize()` in either controller** | **YES** — any authenticated user, any role | YES |
| 6d | "SMS Alerts" sold against a mock transport | PLATFORM-WIDE (commercial) | `Billing/Domain/PlanLimits.php:117`, true at `:310,367,422,477`; `Scheduling/.../DispatchAppointmentReminder.php:94-104` fabricates `mock-…`, marks `Sent` | Entitlement yes; runtime no (Scheduling is Workshop-gated) | Both |
| 7 | **Sourcing / RFQ gaps** (manual send, winner-takes-all) | **SHARED-ENGINE** | `Procurement/Presentation/routes.php:27-32` (no module gate, comment `:20-24`); `PurchaseQuoteRequestAwardService.php:59-84`; `PurchaseQuoteRequestService.php:112-136`; `comparisonLogic.ts:64-85` | **YES** — fully reachable, identical gaps | YES |
| 8 | Public storefront booking un-module-gated | SHARED-ENGINE (minor, new) | `Scheduling/Presentation/routes.php:37-49` — `['api']` + throttle + captcha only, no `module:` | Yes, low severity | Yes |

---

## 3. Per-issue detail

### Issue 1 — A1 gross-in-net `line_total`: **SHARED-ENGINE, not bounded**

**The column contract is NET.** There is no `line_subtotal` and no `line_total_incl_tax` column on `document_lines` — the migration is `database/migrations/tenant/2025_11_30_080001_create_document_lines_table.php:24` (`decimal('line_total', 15, 2)`, widened to scale 3 at `2026_03_11_200000_widen_monetary_columns_to_scale_3.php:62`) and carries no semantic comment. The semantics live in code: `Document/Domain/DocumentLine.php:258-263` docblocks `computeLineTotal()` as *"Canonical NET line total … before tax"*, and the body never adds tax.

**Writer 1 — Workshop (Otospex-only).** `Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:218` writes `'line_total' => $wol->line_total_incl_tax`. The source is provably gross: `Workshop/WorkOrder/Application/Services/WorkOrderLineService.php:271-284` computes `$excl = gross − discount`, `$taxAmount = excl × rate/100`, `$incl = bcadd(...)`. **The correct field is `line_total_excl_tax` (`:282`).** `mapLine` feeds both `generateQuote` (`:60`) and `generateInvoice` (`:76`), and the invoice is posted into the fiscal hash chain at `:125`.

**Writer 2 — POS account charge (SHARED, izipos-reachable).** `Document/Application/Services/POSAccountChargeDraftService.php:92`:

```php
'line_total' => bcadd($lineSubtotal, $lineVat, $command->currencyScale),
```

`$lineSubtotal` and `$lineVat` (`:75-76`) are read from the canonical fiscal payload keys `line_subtotal` / `line_vat`, which are net and VAT respectively — proven at `Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2861-2862`, which sums exactly those keys into `sum_net` / `sum_vat`. So this writes **net + VAT = gross** into the net column. This is the same defect class as the Workshop one, on a lane with **no module gate** (`POS/routes.php:42`; the projection's `requiresModule()` returns `null` at `AccountChargeReceiptProjection.php:38-41`), reached from the device at `apps/pos/src/stores/paymentStore.ts:871,898`. Aggravating detail: the drift-detection mirror at `POSAccountChargeDraftService.php:223` encodes the same wrong contract, so a *corrected* row would be flagged as the mismatch.

**Writer 3 — SUSPECT, unresolved.** `Document/Domain/Services/RefundService.php:1046` writes `$item['line_total'] ?? $item['total'] ?? '0.00'`. The only route in is `RefundController.php:291-318`, whose validation `:295-309` accepts `subtotal` and `total` but **not** `line_total` — so a conforming client falls through to `$item['total']` (gross). No frontend caller of `credit-partial` was found. **UNVERIFIED** — needs either a client trace or a request-contract fix.

**Every other production writer is NET (correct).** Verified by reading: the four document controllers via `computeLineTotal` (`QuoteController.php:306,435`; `InvoiceController.php:383,522`; `SalesOrderController.php:287,414`; `PurchaseOrderController.php:468,610`), `ReturnNoteService.php:221`, `DraftPersistenceService.php:440,790`, `CreditNoteService.php:777,1070,1220`, `DraftPurchaseOrderService.php:199`, `Procurement/Application/PurchaseQuoteRequestService.php:220`, `CreateSupplierInvoiceService.php:185`, `StandaloneReceiptService.php:288`, `Cart/.../CartConversionService.php:148,238`, `Marketplace/.../MarketplaceOrderService.php:294,345`. Conversion services carry the source value verbatim (`CopiesDocumentData.php:142`, `DeliveryNoteToInvoiceConverter.php:325`, `SalesOrderToDeliveryNoteConverter.php:397,425`) — correct in themselves, but they **propagate writer 1's corruption** down the quote→order→invoice chain.

Three lower-severity net-side defects found in passing: `DeliveryNoteController.php:504`, `ReturnNoteController.php:339`, `DraftPersistenceService.php:440,790` and `SalesOrderToDeliveryNoteConverter.php:375` / `DeliveryNoteFromDocumentFactory.php:149` compute `bcmul(qty, unit_price)` and **drop the line discount**.

**Readers are all shared — this is the blast radius.**

| Reader | file:line | What it does |
|---|---|---|
| GL revenue leg | `Accounting/.../AccountingService.php:649` | credits the revenue account with `line_total` ⇒ VAT booked into revenue |
| GL credit-note reversal | `AccountingService.php:811` | same value as debit |
| **GL VAT leg** | `AccountingService.php:1941` | `line_total × rate/100` ⇒ **VAT-on-VAT** |
| GL residual guard | `AccountingService.php:351-352,383,390-391` | a gross `line_total` drives the residual negative ⇒ `GlResidualRefusal::NegativeResidual` — posting **refuses** rather than silently posting wrong |
| **VAT declaration base** | `Taxation/.../PostedLineTaxSnapshotBuilder.php:160-161,180` | per-rate declaration base; docblock `:145` states "base = Σ `line_total`" |
| Printed line column | `resources/views/documents/components/line_items.blade.php:68` | customer-facing Amount column, printed beside a separate tax column |
| Proforma gross | `ProformaGrossAmountResolver.php:76` | `$net = line_total` then adds tax ⇒ net+VAT+VAT |
| Landed cost / inventory unit cost | `Inventory/.../LandedCostService.php:289`; `Api/DocumentAdditionalCostController.php:122,138` | pro-rata weights feeding inventory unit cost |
| Header recompute on conversion | `CopiesDocumentData.php:309-317,329-336` | writes `documents.subtotal/tax_amount/total/balance_due` |

**Why it stays silent:** `Document/Domain/Services/DocumentTotalsCalculator.php:43` and `Taxation/.../TaxCalculationService.php:363` both **recompute** via `$line->calculateTotal()` instead of reading the column, so the header total is right while `Σ line_total` diverges — confirming the `[06]` correction in the synthesis. Note the corollary: header net ≠ Σ line_total is itself a cheap detector nobody runs.

**Latent fourth divergence (new).** `apps/web/src/features/documents/components/DocumentLineEditor.tsx:142-151` — the editor's in-memory `calculateLineTotal()` returns `bcadd(discountedSubtotal, calculateLineTax(...))`, i.e. **gross**, submitted via `linePayload.ts:106`. Harmless today only because the write controllers recompute and ignore the client value (`InvoiceController.php:353-360,488-495`) — **except `PurchaseOrderController.php:102`, which trusts the client `line_total` in `PriceEntryMode::Total`.** Whether the PO editor can reach that branch is **UNVERIFIED** and worth one grep before the A1 fix lands.

**Stale comment to delete while fixing:** `LandedCostService.php:191` says *"This tax is already in line_total"* — false; `:204` recomputes the subtotal separately.

> **Verdict:** the *Workshop* writer is Otospex-bounded, but **A1 as a defect class is SHARED-ENGINE**. The POS account-charge writer hits tenant #1 directly, and all readers are shared.

### Issue 2 — A2 reservation leak: **BOUNDED (Otospex-only)**

Confirmed at `Workshop/.../WorkOrderTransitionService.php:115-119`: `Approved => reserveFor` (`:116`), `Cancelled => releaseFor` (`:118`), and **no arm for `Invoiced` or `Completed`**. `WorkOrderStatus.php:34-46` names two terminal states — `Closed` and `Cancelled` — and only one releases.

Three facts make it unrecoverable rather than merely delayed:
1. `Workshop/.../InventoryReservationAdapter.php:60` hardcodes `expiresAt: null` (enum default `ReservationSource.php:36`, whose comment wrongly asserts "closure releases them explicitly"). `StockReservation.php:111-116` `scopeExpired` requires `whereNotNull('expires_at')`, so the 15-minute sweeper (`ExpireStockReservationsCommand`, scheduled `routes/console.php:167-173`) is **structurally blind** to them.
2. No listener covers the other terminal state — `EventServiceProvider.php:130-140` registers three `WorkOrderCompletedV2`/`WorkOrderClosed` listeners, none touching inventory.
3. The stock is never issued either (issue 3), so one WO corrupts `stock_levels` in **both** directions.

**Other lanes are clean:**
- **POS never reserves.** Zero `StockReservationService`/`StockReservation::` hits in `app/Modules/POS` or `app/Modules/Fiscal`; the POS files matching "Reservation" reference `Company/Domain/ValueObjects/ReservationSettings`, an unrelated *refund-policy* VO.
- **Transfers never reserve.** `ReservationSource::TransferPending` has zero production usages; `StockTransferService.php:879,984` only *reads* `reserved_quantity`.
- **B2B sales orders reserve and release correctly** on all three exits — cancel `DocumentPostingService.php:462`, revert `:568`, fulfil `DeliveryNoteService.php:239` — plus a 7-day `expires_at` safety net.
- Replenishment, Procurement, PurchaseHub, Counting: no reservations at all.

**One shared minor defect:** `Marketplace/.../MarketplaceOrderService.php` writes reservations keyed `sourceId: $cartItemId` and `company_id: seller` (`:66-72`) but `cancelOrder` releases by `$order->id` and buyer company (`:195`), so it releases **zero rows silently**. Bounded by the 24h expiry, and reachable by an IziPOS tenant (`Marketplace/Presentation/routes.php:54,89` and `Cart/Presentation/routes.php:11` carry no `module:` gate).

**Blast radius if it did occur:** `StockLevel.php:104-107` subtracts `reserved` from on-hand, and under `PosStockPolicy::Block` a POS sale **throws** (`ReceiptCreationService.php:958-975`) — a leaked reservation makes an in-stock item unsellable at the till. Also note `recalculateAllReserved` (`StockReservationService.php:683`) would **not** repair it: it recomputes from the still-active rows and faithfully reproduces the wrong number.

> **Verdict: BOUNDED.** Tenant #1 cannot create a work-order reservation. Fix stays inside Workshop.

### Issue 3 — A3 no goods/consumption lane: **SHARED-ENGINE, second instance found**

The Workshop gap is admitted in code at `DocumentGenerationAdapter.php:107-125` — *"the Workshop module has no stock-issuance lane at all (a WO part moves no stock and produces no delivery note anywhere)"* — and corroborated at `Document/Domain/Enums/PostingContext.php:16-23` and `Accounting/Presentation/Console/CheckCogsCoverageCommand.php:153-164`, where `scanMissingWorkOrderMovementLines()` is a **named no-op**. `PostingContext` has exactly two cases (`:48` `Standard`, `:60` `WorkOrderGeneratedInvoice`) and only the latter bypasses, via `claimsPreDeliveryExemption()` (`:68-71`) plus `DocumentPostingService.php:951-955` (which also requires `work_order_id != null`). **That part is properly contained, typed, and audit-stamped.**

**The shared lanes mostly do decrement correctly** — delivery-note confirm (`DeliveryNoteService.php:150,297,316`), goods receipt (`GoodsReceiptService.php:619`), return note (`ReturnNoteService.php:635,760`), POS sale (`PosCoreReceiptProjection.php:1762,2280,2356`), POS return/scrap, transfers, adjustments, counting, opening balances, batch write-offs. Sales-side stock is strictly delivery-note-driven; invoice posting never moves stock.

**But there is a third way past the gate, and a shared lane falls through it.** `DeliveryComplianceGate.php:75` returns `compliant()` when `hasPhysicalLines()` is false, and `hasPhysicalLines()` (`:400`) delegates to `PhysicalLinePredicate::forLine()`, which requires `product_id != null` (`Inventory/Domain/PhysicalLinePredicate.php:36-43`). **The POS account-charge lane writes `'product_id' => null` (`POSAccountChargeDraftService.php:80`)** — verified directly — so its invoice is invisible to the gate.

The full chain for `FiscalEventType::ACCOUNT_CHARGE`:
1. The device authors it from the live cart — real goods leaving the shop (`apps/pos/src/stores/paymentStore.ts:871,898`; payload carries `line_items[]` with `product_id`, `sku`, `quantity`).
2. It is **not** a `SALE_RECEIPT`, and the only POS stock writer handles `SALE_RECEIPT` only — `PosCoreReceiptProjection.php:216-219` (verified directly).
3. Both `ACCOUNT_CHARGE` projectors write no stock: `POS/Application/Projections/AccountChargeReceiptProjection.php` (zero stock references, verified directly) and `Document/Application/Projections/DocumentAccountChargeFactureBridge.php`.
4. It creates a real `DocumentType::Invoice` (`POSAccountChargeDraftService.php:53`) whose lines have `product_id = null`.
5. ⇒ posts with **no delivery requirement, no exemption claimed, and no stock movement**.

Net: a POS credit sale recognises revenue and a receivable, prints a fiscal receipt, creates an invoice — and inventory never moves. Same money-tail shape as the Workshop gap, on a shared lane, and **worse-governed**: unlike Workshop it is neither ticketed, tested, nor stamped, so `delivery_requirement_exempted` stays `false` and the affected population is **not findable by query**. It also compounds A1 writer 2 — the *same* documents carry both the wrong `line_total` and the missing movement.

Two secondary shared skips: `DeliveryNoteService.php:311-313` silently `continue`s a physical line when the location is unresolvable (detected only nightly by `CheckCogsCoverageCommand` `:358-361`); and credit notes post with no goods gate at all, since `DocumentPostingService.php:104` scopes the gate to `DocumentType::Invoice` — arguably correct under document-per-action, flagged as **design asymmetry, not a proven defect**.

> **Verdict: SHARED-ENGINE.** The Workshop instance is bounded and governed; the POS account-charge instance is shared, ungoverned, and reaches tenant #1.

### Issue 4 — A4 invoice PDF plate / A6 no job card: **BOUNDED (Otospex-only)**

The templates are shared — `resources/views/documents/` holds 8 type templates and 7 components, all vertical-agnostic, and `documents/country/` does not exist (`DocumentPdfService.php:145-151` always falls through). `line_items.blade.php` is included by six templates.

The key mismatch is total, not partial. Writers store `license_plate`/`brand` (`WriteDocumentVehicleContextForWorkOrderInvoice.php:72-80`; same keys in `Vehicle/Application/Services/VehicleContextBuilder.php:48-50`); blades read `make`/`registration_number` (`components/totals.blade.php:15,18`). `DocumentPdfService.php:199` casts the JSONB snapshot with a bare `(object)`, so both reads are undefined properties ⇒ falsy ⇒ silently skipped. `grep -niE "\bmake\b|registration_number" app/Modules/Vehicle/` returns **zero** — those column names exist nowhere in the module. `model` and `vin` do print; brand and plate never do. `mileage_at_service` is written (`:89`) and read by no blade.

Three copies of the block: `components/totals.blade.php:11-26`, `templates/delivery_note.blade.php:49-64`, `templates/return_note.blade.php:60-75`. (`credit_note.blade.php` has a hand-copied totals box and **no vehicle block at all** — a workshop credit note drops vehicle context entirely; secondary finding.)

**Why parapharmacy is untouched — three independent gates:** `@if($vehicle ?? null)` (`totals.blade.php:11`); `$vehicle` is null without a `document_vehicle_contexts` row (`DocumentPdfService.php:199`); and the relation is only eager-loaded when the tenant has the Vehicle module (`DocumentPdfService.php:167-171`) — the **only** vertical branch in the entire PDF pipeline. A parapharmacy invoice renders no vehicle block, no empty box, no stray label.

A6 confirmed absent by four greps: no work-order/job-card template in `resources/views`, no `Pdf::`/PdfService in `app/Modules/Workshop`, no print affordance in `apps/web/src/features/workshop-work-orders/`, no `workOrderPdf`/`job-card` route. The only PDF routes are `Document/Presentation/routes.php:451,455`, keyed on a `Document`, which a WorkOrder is not.

> **Verdict: BOUNDED.** But the *fix* touches shared blades, so the fix lane needs a regression check that an IziPOS invoice PDF is byte-unchanged. **UNVERIFIED:** whether any PDF-render test exists (audit claims none at `01:566`); the test tree was not searched.

### Issue 5 — B1 delivery path: **BOUNDED (Otospex-only), zero parapharmacy impact**

Every item verified; all Otospex-only, and in several cases the current configuration is *correct* for tenant #1 rather than merely harmless.

- **No otospex build exists anywhere.** `ProductConfigContext.tsx:59-74` reads `import.meta.env['VITE_APP_PRODUCT']`, inlined at build ⇒ one bundle = one product. Every target resolves to `izipos`: `apps/web/Dockerfile:53` (`ARG …=izipos`), `docker-compose.staging.yml:269` (`${VITE_APP_PRODUCT:-izipos}`), `docker-compose.dokploy.yml:256` (**does not pass it at all** ⇒ Dockerfile default), `apps/web/.env:3`, `.env.example:3`; `.github/workflows/` has **zero** hits; `apps/pos/**` never reads it. Defaulting to izipos is the correct and only working config for tenant #1 — the gap is the *absence* of a second target.
- **Registration.** `apps/web/src/features/auth/config/verticals.ts:36-38` returns disjoint lists; the izipos list `:18-25` includes `parapharmacy` at `:24`. Hiding `mechanic` removes a card parapharmacy never uses.
- **Campaign.** `apps/web/e2e/campaign/journey.ts:325` + `selectors.ts:85` hard-wire parapharmacy. This is a **coverage gap for mechanic and a coverage benefit for parapharmacy** — tenant #1 is the one vertical the promotion gate actually exercises end-to-end.
- **Imports.** `Import/Domain/Enums/ImportType.php` has 7 cases (`:9,10,11,39,41,42,43`); no vehicle case, and `grep -niE "vehicle" app/Modules/Import` returns zero. Parapharmacy day-one needs are fully covered by Parties (`:9`, incl. opening AR/AP `:131-134`), Products (`:11`, incl. opening stock `:138`) and OpeningBalances (`:41`).
- **B4 fails safe.** `isOtospex` is build-time (`ProductConfigContext.tsx:94-101`), so it is `false` on every deployed build, and `ProductForm.tsx:132` / `ProductDetailPage.tsx:217` therefore **hide** the automotive/OEM section — exactly right for parapharmacy. The defect is strictly the converse direction.
- **B6 not sellable to izipos.** `Appointments`/`Fleet` (`ModuleName.php:32-33`) appear only in otospex `compatible_extras` (`config/verticals.php:17,194,253,283`); izipos verticals list `['Loyalty','Ecommerce','CompositeItems','PurchaseBonus']` (`:137`, `:340`). Scheduling is gated on `module:Workshop` (`Scheduling/Presentation/routes.php:52`), so izipos tenants are hard-blocked regardless.
- **B7 mechanic-only.** `DemoTenantSeeder.php:1013-1014` WO numbering sits in `seedWorkOrders()`, called only from `:231-236` inside `createUnlimitedDemoTenant()` (tenant created with `Vertical::Mechanic` at `:150`). The izipos demo tenants (`:119-124`, e.g. `createParapharmacyDemoTenant` `:2003-2072`) are skeletons that seed no products/stock/documents at all — irrelevant to tenant #1, which is a real registered tenant.

**Two premise corrections the readiness audit should absorb:**

1. **B5 is mis-stated.** `Tenant/Application/Services/DayOneCensus.php:38-54` censuses `companies`, `unit_categories`, `tax_configurations`, `locations`, `payment_repositories`, `payment_methods`, `documents`, `accounts`. It does **not** cover products, stock, customers or POS sales for *either* vertical. It is a vertical-agnostic **provisioning** census, not a data census — so "blind to mechanic tables" understates it: it is equally blind for parapharmacy, and "census is CLEAN" means less than the promotion gate assumes.
2. **B8 is reachable, not merely untidy.** The nine routes (`apps/web/src/routes/index.tsx:1644,1654,1664,1678,1688,1698,2835,2845,2855`) pass no `moduleKey`, and `RequirePermission.tsx:48-63` only checks module access when one is supplied; since admin holds every permission (`RolesAndPermissionsSeeder.php:545`), a **parapharmacy admin who types `/scheduling` renders the page shell**. It dead-ends at a backend 403 on all four route groups and the sidebar hides the links (`Sidebar.tsx:322-326,436`), so exposure is nil — but it is a real broken-page UX blemish for tenant #1, not a no-op.

> **Verdict: BOUNDED**, with one cosmetic shared leak (5b) and two corrected premises.

### Issue 6 — security findings: **PLATFORM-WIDE**

**6a — IDOR, and worse than reported.** `apps/platform/app/Modules/PurchaseHub/Presentation/Controllers/TenantOrderController.php:70,81,89` — `show`, `cancel`, `confirmReceipt` all do a bare `PurchaseOrder::findOrFail($id)` with no ownership predicate; zero `authorize`/`Gate::`/policy in the module. The correctly-scoped siblings show the intended shape: `SupplierOrderController.php:35,43` and `FulfillmentController.php:35` all add `->where(<owner>, $profile->id)`. Routes at `PurchaseHub/Presentation/routes.php:23,24,25`, group middleware `:16` = `[AuthenticateApiKey::class]` only.

The larger hole the audit missed: even `index`/`store` scope by `$partner->metadata['tenant_id'] ?? $partner->id` (`:32,49`). `partners.metadata` is `jsonb default '{}'` (`2025_01_28_000020_create_partners_table.php:23`) and **no code path or seeder ever writes `metadata.tenant_id`** — only the three read sites exist. So `$tenantId` always degrades to `$partner->id`, and since the ERP holds **one instance-wide key** (`services.php:106`, single-valued in `.env.example:147`), `purchase_orders.tenant_id` does not discriminate ERP tenants at all. Any holder of any active platform API key can read, cancel (free-text reason, `:77-82`) or confirm-receipt **any** PurchaseHub order by UUID.

**6b — expired keys: confirmed but latent.** `AuthenticateApiKey.php:30-33` filters on `is_active` only; `:35` checks partner active. `ApiKey::isValid()` (`:90-101`, expiry at `:96`) and `ApiKeyService::validate()` (`:64-80`) do check expiry but have **zero production callers** (only Filament generate/revoke at `ApiKeysRelationManager.php:78,98` and tests). Mitigation: nothing ever *sets* `expires_at` — the generate form collects only `name` (`:68-74`), `generate()` `:24-32` never fills it, the factory defaults null. **The bug is latent until someone issues an expiring key**, at which moment expiry silently stops being enforced across all seven partner API modules. Revocation does work.

**6c — PurchaseHub unmetered + ungated.** Platform: `routes.php:16` carries `[AuthenticateApiKey]` alone, versus `Automotive/Presentation/routes.php:14` `['auth.api_key','enforce_api_quota','throttle.automotive','log_api_usage']`. So PurchaseHub bypasses the tier cap and writes **no `ApiUsageLog` row** — meaning the 6a IDOR is also **unlogged**: no record of who read or cancelled what.

ERP side, verified directly — `PurchaseHub/Presentation/routes.php:12-21` carries `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]`, satisfying rule 12's auth half, but has **no `module:` gate, no `can:` on any of its five routes, and no `authorize()`/`can(`/`Gate::` in either controller**. It cannot be module-gated: `PurchaseHub` has no case in `ModuleName.php:16-39`. It is registered on every deployment (`bootstrap/providers.php:106`). **Any authenticated ERP user of any tenant, any role, no permission, can call `GET/POST /api/v1/purchase-hub/*`** — the single broadest authorization gap found in this review, and it is shared-engine.

Contained today, for reasons that are configuration rather than design: no ERP frontend calls it (zero `purchase-hub` hits across `apps/web/src` and `apps/pos/src`), and `SYNERIVA_PLATFORM_API_KEY` is empty in `.env.example:147` and absent from `docker-compose.staging.yml`, so the outbound calls 401 and `PurchaseHubService.php:51-55` swallows it. **Both mitigations evaporate the day the key is set** — and `SYNERIVA_PLATFORM_PUSH_ENABLED` gates only `placeOrder` (`:81-85`), not the GETs.

The unauthenticated ERP webhook (`routes.php:23-27`) is knowingly contained: `PurchaseHubWebhookController.php:13-63` is an annotated STUB, body `:67-77` is `Log::info` + 200, and `tests/Architecture/WebhookControllerTenantContextTest.php` fails CI if a side effect is added. **Correctly contained — do not "fix" it by adding behaviour without signature middleware first.**

**6d — SMS Alerts.** Both halves live in the **ERP**, not platform (`grep` for twilio/vonage/sms over `apps/platform/app` + `config` ⇒ zero hits). `Billing/Domain/PlanLimits.php:117` defines the feature, `true` on four tiers (`:310,367,422,477`), surfaced at `PlanEnforcementService.php:406` and labelled "SMS Alerts" at `apps/web/src/features/admin/components/TenantDetailModal.tsx:109`. The only transport is `Scheduling/.../DispatchAppointmentReminder.php:91-104`, which fabricates `'mock-'.bin2hex(...)`, logs, and sets `delivery_status = Sent` with `sent_at`. Every tenant of both verticals on the upper four tiers is sold an entitlement with no implementation, and reminders are **recorded as delivered when nothing was sent**. Runtime impact is confined to Scheduling (Workshop-gated ⇒ Otospex-only); the mis-selling and the false `Sent` audit state are not.

> **Verdict: PLATFORM-WIDE.** 6c's ERP half is the item that touches tenant #1 today.

### Issue 7 — sourcing / RFQ gaps: **SHARED-ENGINE, fully reachable by tenant #1**

RFQ lives in `Procurement`, on the unified `documents` table via `DocumentType::PurchaseQuoteRequest`. **There is no vertical gate anywhere on the path:**

- `Procurement/Presentation/routes.php:27-32` carries `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` and no `module:`. The file's own header `:20-24` says this is deliberate: *"No 'module:Procurement' is added because 'Procurement' is not in the ModuleName enum and no vertical enables it by name. Access is governed by per-route 'can:' permissions."* Confirmed: `ModuleName.php:16-39` has no Procurement case.
- **No vertical — otospex or izipos — lists `Procurement` in `config/verticals.php`.** The module axis simply does not gate RFQ for anyone.
- Permissions are seeded vertical-agnostically: `RolesAndPermissionsSeeder.php:159-163` registers all five `purchase-quote-requests.*`, granted at `:574,711,777`; zero `vertical` hits in that seeder.
- Frontend gates on a **role** alias, not a module: `apps/web/src/routes/index.tsx:884-923` uses `RequirePermission moduleKey="purchases"` → `usePermissions.ts:32,165-173` → `uiAliasPermissions.ts:6` (`['admin','purchases','manager']`). `Sidebar.tsx:173-186` gives the purchases group no `module:` key, and `:433-443` skips the `hasModule` check when absent.

So a parapharmacy admin or manager sees RFQ in the sidebar and hits the identical gaps:
- **`markSent()` sends nothing** — `PurchaseQuoteRequestService.php:112-136` sets `Confirmed` (`:122`) and stamps `sentAt` (`:129`) and stops. Zero `Mail::`/`Notification::`/`Mailable` hits in the module; `.env.example:124` `MAIL_MAILER=log`.
- **Winner-takes-all** — `PurchaseQuoteRequestAwardService.php:59-84` converts one winner (`:59-63`) then hard-cancels every sibling (`:71` `Cancelled`, `:79` `closedReason: 'lost'`). No per-line or split-award path exists.
- **Comparison is price-only and all-or-nothing** — `comparisonLogic.ts:64-85`: `if (cells.length !== siblings.length) return { bestSiblingIds: [] }` (`:66-68`), then lowest `unit_price` (`:75-77`). Real wholesaler behaviour (partial quotes) silently disables the comparison entirely.

A parapharmacy buying from wholesalers — where partial quotes and per-line splits across suppliers are the norm — hits every one of these. **The synthesis §4a intuition that "sourcing is platform-core, not vertical-specific" is confirmed in code.**

> **Verdict: SHARED-ENGINE.** Sourcing Phase 0 is not an Otospex lane; it ships value to tenant #1 too.

### Issue 8 — public storefront booking un-module-gated (new, minor)

`Scheduling/Presentation/routes.php:37-49` exposes `api/v1/storefront/{company_id}/availability` and `.../appointments` under `['api']` only — no `module:` gate — while the authenticated group below (`:52`) is gated. `StorefrontBookingController.php:44-56` validates the UUID and loads the company but never checks the tenant's module set. The `api` group runs `ResolveTenancy` (`bootstrap/app.php:143-146`), so it is tenant-scoped by domain, and it is throttled per-IP and per-company-phone plus `scheduling.captcha` (`:45-47`). Residual: an izipos tenant's domain accepts appointment writes for a feature it never bought and no UI shows. Low severity; worth a `module:Workshop` gate when A6/B6 are touched.

---

## 4. Does anything here threaten the TN parapharmacy tenant-#1 go-live?

**Yes — three items, and the first two are the same P0 defect class the audit believed was garage-only.** Tenant #1 uses POS + purchasing + inventory + transfers + treasury, no Workshop.

| Rank | Item | Class | Why it threatens tenant #1 | Evidence |
|---|---|---|---|---|
| **1** | **POS account-charge writes gross into net `line_total`** | P0 money | Every credit sale books VAT into GL revenue (`AccountingService.php:649`), computes **VAT on a TTC base** (`:1941`), corrupts the VAT-declaration base (`PostedLineTaxSnapshotBuilder.php:180`), prints a wrong Amount column (`line_items.blade.php:68`) — or drives the residual negative and **refuses to post** (`AccountingService.php:383,390-391`). A TN fiscal filing computed off this is wrong. | `POSAccountChargeDraftService.php:92` |
| **2** | **POS account-charge moves no stock** | P0 inventory/COGS | Goods leave the shop; revenue + receivable recognised; inventory and COGS never move. Bypasses the delivery gate by *shape* (`product_id = null`), so unlike the Workshop case it claims no exemption, is not stamped, and the affected population **cannot be found by query** afterwards. | `POSAccountChargeDraftService.php:80`; `AccountChargeReceiptProjection.php` (no stock); `PosCoreReceiptProjection.php:216-219`; `PhysicalLinePredicate.php:36-43`; `DeliveryComplianceGate.php:75,400` |
| **3** | **ERP PurchaseHub routes have no permission gate at all** | P0 authz | Any authenticated user of tenant #1 — cashier, viewer, any role — can call all five endpoints incl. `POST orders`. Contained today only because no FE calls it and the platform key is unset; **both mitigations evaporate the day the key is set**. | `PurchaseHub/Presentation/routes.php:12-21`; zero `authorize`/`can(` in either controller; `ModuleName.php:16-39` (no case) |
| 4 | Platform `TenantOrderController` IDOR + unmetered/unlogged | P0 platform security | Any API-key holder reads/cancels/confirms any order; no `ApiUsageLog` row, so exploitation is invisible. Reaches tenant #1's data only once the ERP key is issued — i.e. it is a **precondition to fix before enabling PurchaseHub**, not a today-blocker. | `TenantOrderController.php:70,81,89,32,49`; platform `routes.php:16` |
| 5 | RFQ manual send + winner-takes-all + price-only comparison | P1 functional | A parapharmacy sourcing from wholesalers gets no supplier-facing send, cannot split an award, and loses the comparison entirely on partial quotes. Not a go-live blocker; a day-one disappointment. | `PurchaseQuoteRequestService.php:112-136`; `PurchaseQuoteRequestAwardService.php:59-84`; `comparisonLogic.ts:64-85` |
| 6 | Delivery note silently skips stock on unresolvable location | P1 inventory | A sealed, confirmed DN can move no stock for a physical line; caught only by a nightly scan. | `DeliveryNoteService.php:311-313` |
| 7 | `DayOneCensus` does not census products/stock/customers/POS | P1 process | A CLEAN census is a promotion precondition but proves far less than assumed — for **either** vertical. | `DayOneCensus.php:38-54` |
| 8 | "SMS Alerts" sold with no transport; reminders marked `Sent` | P2 commercial | Tenant #1 on an upper tier is sold a feature that does not exist. Runtime impact nil (Scheduling is Workshop-gated). | `PlanLimits.php:117,310,367,422,477`; `DispatchAppointmentReminder.php:94-104` |
| 9 | Marketplace `cancelOrder` releases zero reservations | P2 inventory | Reserved stock stays reserved up to 24h after a cancel; self-heals via the sweeper. | `MarketplaceOrderService.php:72` vs `:195` |
| 10 | Expired platform API keys still authenticate | P2 latent | Harmless until someone issues a key with an expiry; then expiry silently stops being enforced everywhere. | `AuthenticateApiKey.php:30-33` |
| 11 | Nine FE workshop routes render a broken shell for a parapharmacy admin | P3 UX | Backend 403s; sidebar hides them. Cosmetic. | `routes/index.tsx:1644-1698,2835-2855`; `RequirePermission.tsx:48-63` |

Plus the vertical-agnostic launch gates already tracked in `docs/handoff/LEDGER.md` (no production host, P0-2 registration timeout, G-4 staging verification, CI blind since 2026-08-21) — unchanged by this review; the ledger remains authoritative.

**Ranked answer in one line:** items 1 and 2 are new P0s against tenant #1 that the readiness audit classified as Otospex money-tail defects; item 3 is a shared authorization hole; everything else in the audit's A/B list is genuinely Otospex-bounded.

---

## 5. What this changes about the audit's sequencing

The synthesis §5 puts A1 under "Now (this week)" as an Otospex money-path fix. Two corrections follow from the above:

1. **A1 and A3 need a second fix each, on the POS account-charge lane**, and those are tenant-#1 blockers rather than garage-readiness items. The A1 lane should fix `POSAccountChargeDraftService.php:92` **and** its drift mirror at `:223` together, or the corrected rows will be reported as mismatches.
2. **A legacy sweep is needed on both verticals, not one.** The synthesis scopes the GL + VAT-snapshot sweep to WO-generated invoices; it must also cover posted account-charge invoices. The Workshop population is findable via `work_order_id` / the exemption stamp; **the account-charge population is not stamped at all** and must be identified by `documents` joined to the account-charge draft metadata (`POSAccountChargeDraftService.php:70-72` writes `account_charge_uuid` and `canonical_payload`).
3. Sourcing Phase 0 should be described as a **platform-core lane serving both verticals**, which strengthens the §4a placement ruling rather than weakening it.

---

## 6. Open / UNVERIFIED

| Item | What would settle it |
|---|---|
| Whether the TN parapharmacy tenant will actually **use** POS account-charge (credit) sales — the lane is gated only by a per-customer `credit_limit` on the Partner, not by vertical or module | An owner answer, or a query for partners with a non-null `credit_limit` on the staging/first-customer tenant. **This single answer decides whether threats 1 and 2 are go-live blockers or latent.** |
| `RefundService.php:1046` — third suspected gross writer | Trace a real `credit-partial` client payload, or tighten `RefundController.php:295-309` to validate `line_total` explicitly |
| `PurchaseOrderController.php:102` trusts client `line_total` in `PriceEntryMode::Total`, and the FE editor computes it **gross** (`DocumentLineEditor.tsx:142-151`) | Determine whether the PO editor can reach `PriceEntryMode::Total`; if yes this is a fourth live gross-in-net writer, on a shared lane |
| Whether the POS device ever emits a `SALE_RECEIPT` alongside `ACCOUNT_CHARGE` in some configuration | Exhaustive trace of the checkout branches in `apps/pos/src/stores/paymentStore.ts`; none found at `:1447-1481`, which references `SALE_RECEIPT` only as a refund/void target |
| Whether `ReceiptCreationService::decrementStock()` (`:927`) is still reachable or fully superseded by the fiscal projection | Controller wiring trace; the code comment says "legacy draft path" |
| Whether any PDF-render test exists for the document templates | Search the test tree (not searched here; no suites were run) |
| Whether `.worktrees/` copies carry a divergent `PostingContext` with extra bypassing cases | All findings above are scoped to the main `apps/api` tree only |

---

*Produced read-only. No code was modified and no test suite was run. Where this document and `docs/handoff/LEDGER.md` disagree on vertical-agnostic launch items, the ledger wins.*

---

## 7. Post-report corrections + owner rulings (2026-08-30 evening, session K)

**Correction:** the §4 containment claim for threats 3–4 ("platform key unset") is **WRONG** — `SYNERIVA_PLATFORM_API_KEY` is set in the ERP `.env` (read by `apps/api/config/services.php:106`), confirmed by the owner ("there is already a platform key being used"). The PurchaseHub exposure is live, not latent. Lane K-4.

**Owner rulings:** (1) A1/A3-POS: fix properly even though tenant #1 won't use credit sales — correctness; long-running Codex lane K-1. (2) A3-WO goods lane = **internal consumption document** (lane K-3). (3) A3-POS stock = **project like SALE_RECEIPT** (lane K-1 task 2). (4) `line_total` = **NET forever**, POS not an exception at storage (glossary pin, K-1 task 5). (5) A5 timbre: **show on final invoice now**, TN accountant confirms wording later (lane K-5).

**Lane briefs:** `docs/sessions/session-K-otospex-money-2026-08-30/LANE-K1..K5-*.md` (K-1 = the owner-dispatched long-running Codex lane).
