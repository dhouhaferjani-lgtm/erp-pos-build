# Research A — Purchase-order create / edit / confirm / cancel (code truth)

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`, branch `dev`.
All paths relative to `apps/erp`. Every claim below was read at the cited line.
Scope: wave-2 Playwright scenario matrix for a **Tunisian parapharmacy tenant (IziPOS / Vertical::Parapharmacy, country TN, currency TND)**.

---

## 0. Executive findings (the ones that change the scenario matrix)

1. **There is NO cancel endpoint for a purchase order.** No `purchase-orders.*/cancel` route exists anywhere (`apps/api/app/Modules/Document/Presentation/routes.php:274-309`; the only `cancel` routes in that file are `invoices.cancel:224`, `credit-notes.cancel:269`). The un-do path for a PO is **`POST /api/v1/documents/{id}/revert`** (Confirmed → Draft) and **`DELETE /api/v1/purchase-orders/{id}`** (Draft only). `DocumentStatus::Cancelled` is reachable in the adjacency map (`DocumentStatusMachine.php:123-136`) but **no PO caller writes it**.
2. **`PriceEntryMode::Total` trusts the client's `line_total` verbatim and stores it in the NET column** (`PurchaseOrderController.php:99-104`), while the **frontend's `line.line_total` is GROSS (net + tax)** in every mode (`DocumentLineEditor.tsx:142-151`, `:592-598`, `:589` for the total-mode branch). This is the K1-S1-12 question — see §6.4. It is only reachable when the PurchaseBonus gate is on (module + country allowlist).
3. **Confirm does not update `subtotal`** — only `tax_amount` and `total` (`PurchaseOrderService.php:136-139`), and `TaxCalculationService` recomputes the subtotal from `qty × unit_price − discount` rather than from the stored `line_total` (`TaxCalculationService.php:348-370`). In Total mode with a non-divisible total those two disagree (see §9 E-4).
4. **A draft PO carries NO `document_number`** — it is allocated on the Draft→Confirmed transition and nowhere else (`PurchaseOrderController.php:389`, `DocumentStatusService.php:168-183`, `:186-220`). Numbering is per `company_id` + type + year (`DocumentNumberingService.php:44-66`).
5. **Zero `data-testid` in the whole PO UI** (`DocumentListPage.tsx`, `DocumentForm.tsx`, `DocumentLineEditor.tsx`, `PurchaseOrderDetailPage.tsx` — grep count 0 each). Playwright must select on roles / aria-labels / i18n text.
6. **`POST /purchase-orders` has no idempotency key of any kind.** Two identical POSTs create two drafts. Confirm *is* idempotent (§5).

---

## 1. Routes

### 1.1 Purchase-order routes — `apps/api/app/Modules/Document/Presentation/routes.php`

Group middleware for the whole file: `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`, prefix `api/v1` (`routes.php:33`). **No `module:` gate on any PO route.**

| Method + path | Controller method | `can:` | Line |
|---|---|---|---|
| `GET /api/v1/purchase-orders` | `index` | `purchase-orders.view` | `:274-276` |
| `GET /api/v1/purchase-orders/{purchaseOrder}` | `show` | `purchase-orders.view` | `:278-280` |
| `GET /api/v1/purchase-orders/{purchaseOrder}/receipt-lines` | `receiptLines` | `documents.view` (+ `whereUuid`) | `:282-285` |
| `POST /api/v1/purchase-orders` | `store` | `purchase-orders.create` | `:287-289` |
| `PATCH /api/v1/purchase-orders/{purchaseOrder}` | `update` | `purchase-orders.update` | `:291-293` |
| `DELETE /api/v1/purchase-orders/{purchaseOrder}` | `destroy` | `purchase-orders.delete` | `:295-297` |
| `POST /api/v1/purchase-orders/{purchaseOrder}/confirm` | `confirm` | `purchase-orders.confirm` | `:299-301` |
| `POST /api/v1/purchase-orders/{purchaseOrder}/receive` | `receive` | `purchase-orders.receive` | `:303-305` |
| `GET /api/v1/purchase-orders/{purchaseOrder}/receipt-status` | `receiptStatus` | `purchase-orders.view` | `:307-309` |

Note: only `receipt-lines` has `whereUuid('purchaseOrder')` (`:284`). The other `{purchaseOrder}` routes do **not**, so a non-UUID id reaches `->find($id)` on a PG uuid column — the same shape as the K-6 500 (memory: `GET /imports//preview`). Probe it (§9 E-12).

### 1.2 Adjacent routes a PO scenario touches (same file)

- `POST /api/v1/documents/{document}/revert` — `can:documents.update`, `whereUuid` (`:84-87`). **This is the PO "un-confirm".**
- `POST /api/v1/documents/auto-save` — `can:documents.update` + per-type `purchase-orders.create` inside `AutoSaveDraftRequest::authorize()` (`:76-78`; `AutoSaveDraftRequest.php:101`, `:154-171`).
- Additional costs (landed cost): `GET .../additional-costs` `can:documents.view` (`:368-370`); `POST` / `PATCH` / `DELETE` all `can:purchase-orders.update` (`:372-383`); `GET .../landed-cost-breakdown` `can:documents.view` (`:385-387`).
- PDF: `GET /documents/{document}/pdf` and `/pdf/preview`, both `can:documents.view` (`:451-457`). There is **no PO-specific PDF route**.
- Inventory-side receive routes reuse `can:purchase-orders.receive` (`apps/api/app/Modules/Inventory/Presentation/routes.php:171`, `:176`).

### 1.3 Procurement module routes — `apps/api/app/Modules/Procurement/Presentation/routes.php`

Same middleware stack (`:28-33`). Explicitly documented as **no `module:Procurement` gate** because `Procurement` is not in `ModuleName` (`:19-23`). Relevant to a PO scenario:
- `POST /purchase-quote-requests/{id}/convert-to-po` — `can:purchase-quote-requests.convert` (`:68-71`). A PO born this way carries `source_document_id` pointing at the RFQ, which **blocks revert** (§4.4).
- `POST /goods-receipts/standalone` — `can:goods-receipt.create-standalone` (`:41-43`).
- Supplier invoices (`:80-119`) — mostly `can:documents.view` / `can:documents.update`.

### 1.4 Replenishment

`Gate::authorize('purchase-orders.create')` inside `ReplenishmentActionController.php:58` — the replenishment "create PO" action goes through `DraftPurchaseOrderService` (§3), not the controller.

---

## 2. `PurchaseOrderController::store` / `::update`

File: `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php` (983 lines).
Constructor injects `CompanyContext`, `LocationContext`, `PurchaseOrderService`, `GoodsReceiptService`, `VehicleContextBuilder`, `CurrencyScaleResolverInterface`, `DocumentLineTaxResolver`, `PurchaseBonusGate` (`:66-74`).

### 2.1 Validation — `CreateDocumentRequest` (shared across document types)

`apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php`, rules at `:65-153`.

**Header:**
| Field | Rules | Line |
|---|---|---|
| `partner_id` | `required`, `uuid`, `ScopedExists::tenantAndCompany('partners', tenant, company)` | `:66-70` |
| `vehicle_context` | `nullable|array` if Vehicle module, else **`prohibited`** | `:71` |
| `document_date` | `required_without:issue_date`, `date` | `:80` |
| `issue_date` | `required_without:document_date`, `date` | `:81` |
| `due_date` | `nullable`, `date`, `after_or_equal:document_date` | `:82` |
| `valid_until` | `nullable`, `date`, `after_or_equal:document_date` | `:83` |
| `currency` | `nullable`, `string`, `size:3` — **no whitelist against company/tenant currencies** | `:84` |
| `notes` / `internal_notes` | `nullable|string|max:5000` | `:85-86` |
| `reference` | `nullable|string|max:100` | `:87` |
| `source_document_id` | `nullable`, `uuid`, `ScopedExists::tenantAndCompany('documents', …)` | `:88-92` |
| `location_id` | `nullable`, `uuid`, `ScopedExists::company('locations', companyId)->where('is_active', true)` | `:93-99` |
| `lines` | `required|array|min:1` | `:100` |

**Lines (rule-19 regex ceilings — which have them and which don't):**
| Field | Rules | Ceiling? | Line |
|---|---|---|---|
| `lines.*.product_id` | `nullable`, `uuid`, `prohibits:lines.*.service_id`, scoped exists | n/a | `:101-106` |
| `lines.*.service_id` | `nullable`, `uuid`, `prohibits:lines.*.product_id`, scoped exists | n/a | `:107-112` |
| `lines.*.location_id` | `nullable`, `uuid`, scoped exists + `is_active` | n/a | `:113-118` |
| `lines.*.description` | `required|string|min:1|max:500` | n/a | `:119` |
| `lines.*.quantity` | `required|numeric|gt:0|regex:/^\d+(\.\d{1,4})?$/` | **YES (4dp, non-negative)** | `:120` |
| `lines.*.free_quantity` | gate on → `nullable|numeric|min:0|regex 4dp`; gate off → **`prohibited`** | YES | `:121-123` |
| `lines.*.unit_price` | `required|numeric|min:0|regex:/^\d+(\.\d{1,3})?$/` | **YES (3dp)** | `:124` |
| `lines.*.line_total` | `nullable|numeric|min:0|regex 3dp` | **YES (3dp)** | `:125` |
| `lines.*.price_entry_mode` | gate on → `Rule::in(PriceEntryMode::values())`; gate off → `Rule::in(['unit'])` | n/a | `:126-128` |
| `lines.*.is_bonus_line` | gate on → `nullable|boolean`; off → **`prohibited`** | n/a | `:129-131` |
| `lines.*.discount_percent` | `nullable|numeric|min:0|max:100|regex:/^\d+(\.\d{1,2})?$/` | **YES (2dp)** | `:132` |
| `lines.*.discount_amount` | `nullable|numeric|min:0|regex 3dp` + `LineDiscountAmountWithinGross` | **YES (3dp)** | `:133-142` |
| `lines.*.tax_rate` | `nullable|numeric|min:0|max:100|regex 2dp` | **YES (2dp)** | `:143` |
| `lines.*.tax_configuration_id` | `nullable|uuid|exists(country_code, applies_to=LINE_ITEMS)` + `TaxConfigurationCountryCoherent` | n/a | `:144-151` |
| `lines.*.notes` | `nullable|string|max:1000` | n/a | `:152` |

**Fields the controller USES but the request DOES NOT declare:** `lines.*.variant_id` (dropped by `validated()`), `external_document_number` / `external_document_date` (the FE sends these — `DocumentForm.tsx:481-483` — and they are **dropped**, since the rule set never declares them). `partner_id` is validated but `payment_terms` / `price_list` are **not declared anywhere** → see §2.6.

**Cross-field:** `withValidator()` (`:195-218`) adds "line_total is required when price_entry_mode is total" (`:211-213`) and runs `DiscountPolicyDocumentValidator` — which **no-ops for purchase orders**, its route allowlist is `invoices.store|invoices.update|orders.store|orders.update` only (`DiscountPolicyDocumentValidator.php:23-28`, `:149-154`). Same for the tolerance rules (`AppliesDiscountToleranceRule.php:140-160`).

**`prepareForValidation`** (`:176-193`): trims `description` / `notes`, strips client-supplied `designation_default_snapshot`.

### 2.2 `UpdateDocumentRequest`

`apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php:62-131`. Same line rules verbatim, with these header differences:
- `partner_id` is `sometimes` (`:63-67`); `document_date` / `issue_date` are `sometimes` (`:77-78`); `due_date` / `valid_until` lose `after_or_equal` (`:79-80`).
- **`currency`, `location_id`, `source_document_id` and `lines.*.location_id` are NOT declared** → `validated()` drops them, so a PATCH can never change the PO currency or its destination location. (Header currency change is impossible through the API.)
- `lines` is `sometimes|array|min:1` (`:84`) — a PATCH with `lines: []` fails `min:1`; a PATCH with no `lines` key leaves lines untouched (`PurchaseOrderController.php:520-521`, `:558`).

### 2.3 `PriceEntryMode` and the per-mode arithmetic

Enum: `apps/api/app/Modules/Document/Domain/Enums/PriceEntryMode.php:9-10` — exactly two cases, `Unit = 'unit'` and `Total = 'total'`.

`PurchaseOrderController::normalizePurchaseLine()` — **`:88-127`**, the single place both `store` and `update` normalise a line:

```
:92   quantity      = CurrencyScale::bcformatStrict($line['quantity'], 4)
:94   freeQuantity  = CurrencyScale::bcformatStrict($line['free_quantity'] ?? '0', 4)
:95-96 mode         = PriceEntryMode::tryFrom($line['price_entry_mode'] ?? 'unit') ?? Unit

:98-104  TOTAL branch:
         lineTotal        = bcformatStrict($line['line_total'], scale)      <-- CLIENT VALUE, TRUSTED
         derivedUnitPrice = bcformatStrict(bcdiv(lineTotal, quantity, scale+1), scale)
         $line['unit_price'] = derivedUnitPrice                             <-- client unit_price DISCARDED
         (discount_percent / discount_amount are NEVER applied in this branch)

:105-116 UNIT branch:
         unitPrice = bcformatStrict($line['unit_price'], scale)
         lineTotal = DocumentLine::computeLineTotal(qty, unitPrice, discount_percent, discount_amount, scale)
                                                                            <-- client line_total DISCARDED

:118-121 line['quantity'|'free_quantity'|'line_total'|'price_entry_mode'] written back
:123-125 if (mode === Total || freeQuantity > 0):
         landed_unit_cost = bcformatStrict(bcdiv(lineTotal, quantity, scale+4), 6)
```

`DocumentLine::computeLineTotal` (`apps/api/app/Modules/Document/Domain/DocumentLine.php:280-302`) is the canonical **NET** value: `bcmul(qty, unitPrice, scale)`, minus percent discount if non-zero (`:290-292`) **else** flat amount (`:293-295`), floored at zero (`:297-299`). Percent takes precedence over amount; they never combine.

Behavioural consequences worth a scenario each:
- **Total mode ignores discounts entirely.** `discount_percent` / `discount_amount` are still persisted onto the row (`:743-744` in `store`) but never enter the arithmetic. So a Total-mode line with `discount_percent: 50` stores the discount and a `line_total` that does not reflect it.
- **Total mode overwrites `unit_price`**, proven by `PurchaseBonusQuantityEntryTest::total_mode_stores_entered_total_and_materializes_scale_six_cost_basis` (`apps/api/tests/Feature/Procurement/PurchaseBonusQuantityEntryTest.php:184-215`): sent `unit_price 14.285` + `line_total 100.000` with qty 7 → stored `unit_price 14.285`, `line_total 100.000`, `landed_unit_cost 14.285714`. Note `100/7 = 14.285714…` truncates to `14.285`, which coincidentally matched the sent price.
- **Total mode is gated.** `lines.*.price_entry_mode` accepts `total` only when `PurchaseBonusGate::enabledFor($company)` (`CreateDocumentRequest.php:126-128`). The gate = tenant has the `PurchaseBonus` module AND company `country_code` ∈ `config('procurement.bonus_quantity_countries')` default `['TN']` (`apps/api/app/Modules/Procurement/Application/PurchaseBonusGate.php:17-37`). **`PurchaseBonus` is a `compatible_extras` entry for Parapharmacy, NOT a `default_modules` entry** (`apps/api/config/verticals.php:47` vs `:51-61`) — so on a fresh Parapharmacy tenant Total mode is **refused** until the extra is enabled.

### 2.4 Header totals

There is **no** `DocumentTotalsCalculator` call in this controller. `store` sums inline (`:394-408`):
```
subtotal  = Σ line_total                       (per line, bcadd at scale)
lineTax   = bcmul(line_total, bcdiv(tax_rate,'100',4), scale)
taxAmount = Σ lineTax
total     = subtotal + taxAmount
```
`update` repeats the identical loop (`:574-586`, `:620`). `line_tax_amount` / `stamp_duty_amount` are **not** written here — those only come from `DocumentTotalsCalculator::recalculate` (`apps/api/app/Modules/Document/Domain/Services/DocumentTotalsCalculator.php:33-57`), which the PO path never calls. `DocumentTotalsCalculator` is used only by `DraftPersistenceService` (`:47`) and the WorkOrder adapter.

Tax is only re-derived at **confirm**, by `TaxCalculationService::calculateDocumentTaxes` (§4.3).

### 2.5 Tax resolution per line

`DocumentLineTaxResolver::resolve` runs BEFORE `normalizePurchaseLine` in both paths (`store` `:386`, `update` `:571`). Precedence, highest first (`apps/api/app/Modules/Document/Application/Services/DocumentLineTaxResolver.php:61-97`):
1. line `tax_configuration_id` (`:64-69`) — a configuration id **outranks** any `tax_rate` the client also sent (this ordering is defect N-1's fix, `:43-60`).
2. explicit line `tax_rate` (`:71-73`).
3. product `default_tax_configuration_id` (`:75-80`).
4. product `tax_rate` (`:82-84`).
5. company `default_tax_configuration_id` (`:86-91`).
6. company `default_tax_rate` (`:93-95`).
7. `'0.00'` (`:97`).

A configuration is only honoured when it is a percentage row for the company's country with `applies_to = LINE_ITEMS` (`:103-115`); otherwise it falls through. Rates are formatted to 2dp (`:130-133`). Mixed rates per line are fully supported — nothing forces a single rate. `0.00` reaches `bcmul(lineSubtotal, 0, scale)` → zero tax, no special case.

### 2.6 Currency, scale, supplier defaults, numbering, company/location

- **Currency:** `store` writes `$validated['currency'] ?? $company->currency` (`:414`). No validation that the code is a currency the tenant supports, and `update` cannot change it (§2.2).
- **Scale:** `private function scale()` calls `$this->scaleResolver->getScale()` with **no currency argument** (`:76-79`). That is the CompanyContext-bound resolver, fine in an HTTP request, but it means the scale comes from the *active company*, not from the document's `currency` field — a PO created with `currency: 'EUR'` inside a TND company is scaled as TND. Contrast `PurchaseOrderService::guardAgainstUnpricedLines`, which correctly uses `getScaleSafe($purchaseOrder->currency, 3)` (`PurchaseOrderService.php:92`).
- **Supplier defaults: NONE.** Grep of `store()` (`:346-491`) shows no read of the partner beyond the FK. No payment-terms default, no supplier currency, no supplier price list, no `due_date` derivation. The only supplier-derived pricing in the whole PO surface is the **product's** `purchase_price` (frontend default, `DocumentLineEditor.tsx:486`, and `DraftPurchaseOrderService.php:136`), plus the RFQ→PO award which carries the quoted price (`linePayload.ts:186-187`).
- **Document numbering:** `store` sets `'document_number' => null` explicitly with a long comment (`:387-389`, `:416`). Allocation happens once, at the Draft→X transition, in `DocumentStatusService::allocateNumberAndTransition` (`:168-183`, `:186-220`) — **never on the way to `Cancelled`** (`:170-171`, `:201-205`). `DocumentNumberingService::generateForKeyOnce` locks the `document_sequences` row scoped by `company_id` + `sequence_type` (`:44-66`) and formats `sprintf('%s-%d-%04d', prefix, year, next)` (`:66`) → **per company, per type, per year**. Two companies in the same tenant each get their own `PO-2026-0001`.
- **`company_id` / `tenant_id`:** always from `CompanyContext` (`:399-401` in store; `:414-416` on create). Client cannot supply them.
- **`location_id`:** `LocationContext::resolveLocationId($validated['location_id'] ?? null, $companyId)` (`:411-414`) → explicit param > active location context > company default location > null (`apps/api/app/Modules/Company/Services/LocationContext.php:128-150`). **`store` does NOT call `validateLocationAccess`** (only `receive` does, `PurchaseOrderController.php:800-806`); the request-level `ScopedExists::company('locations', …)->where('is_active', true)` is the only guard.
- **Status/fiscal at birth:** `status = Draft`, `fiscal_status = FiscalStatus::Draft`, `fiscal_category = FiscalCategory::fromDocumentType(PurchaseOrder)` (`:409-413`).
- **Line writes:** `store` `:420-455` (line_number = index+1, `designation_default_snapshot` from product/service name truncated to 500), `update` `:601-618`. Response: 201 via `documentCreatedResponse` (`HandlesDocuments.php:90-93`).

### 2.7 `update` guards (order matters)

`PurchaseOrderController::update` (`:493-637`):
1. `baseQuery()->ofType(PurchaseOrder)->find()` → 404 `NOT_FOUND` (`:500-508`).
2. `isFiscallyImmutable()` → 422 `DOCUMENT_SEALED` (`:510-512`).
3. `isEditable()` → 422 `DOCUMENT_NOT_EDITABLE` (`:514-516`). **`DocumentStatus::isEditable()` returns true for Draft AND Confirmed** (`DocumentStatus.php:19-25`), and `Document::isEditable()` is `status->isEditable() && !isFiscallyImmutable()` (`Document.php:621-624`). A PO is `fiscal_category` non-fiscal, so **a CONFIRMED purchase order is editable via PATCH** — including its line set, its partner and its dates. High-value scenario (§9 E-9).
4. Receipt lock — **outside the transaction, deliberately** (`:539-554`): if any existing line has a goods receipt, return 422 `PO_LINES_LOCKED_BY_RECEIPTS`. Only fires when `lines` is present in the payload; a header-only PATCH on a partially-received PO is allowed.
5. Transaction: header `update($validated)`, then if `lines !== null` **delete all lines and recreate them** (`:559-563`) — so line ids change on every save, and `quantity_received` bookkeeping on the old rows is destroyed (guarded by step 4 only when receipts exist).

`destroy` (`:642-670`): 404 → `isFiscallyImmutable()` 422 `FISCAL_DOCUMENT_NOT_DELETABLE` → `isDeletable()` 422 `DOCUMENT_NOT_DELETABLE` (Draft only, `DocumentStatus.php:30-36`) → soft delete, `204`.

Error envelope for all of these: `{"error": {"code": …, "message": …}}` at 422 (`HandlesDocuments.php:315-323`) / 404 (`:302-309`). Codes: `DOCUMENT_SEALED` (`:328-334`), `DOCUMENT_NOT_EDITABLE` "Posted documents cannot be modified" (`:339-345`), `DOCUMENT_NOT_DELETABLE` (`:350-356`), `FISCAL_DOCUMENT_NOT_DELETABLE` (`:361-367`).

---

## 3. `DraftPurchaseOrderService` and the autosave path

These are **two different things**; neither is the controller path.

### 3.1 `DraftPurchaseOrderService` — replenishment only

`apps/api/app/Modules/Document/Application/Services/DraftPurchaseOrderService.php`. Only caller: `ReplenishmentFulfillmentService` (`apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:9`, `:27`). Not reachable from the PO editor.

Differences from the controller:
- Prices come from `$product->purchase_price ?? '0'` — **the client never supplies a price** (`:133-138`).
- `line_total = bcformatStrict(bcmul(quantity, unitPrice, scale+1), scale)` — **no discount support at all** (`:147-150`).
- It **computes and persists `tax_amount` / `tax_recoverable` / `recoverable_tax_amount` / `non_recoverable_tax_amount` per line** (`:155-158`, `:194-198`) — the controller path leaves these null until confirm.
- `price_entry_mode` hard-coded to `Unit`, `is_bonus_line` false, `free_quantity '0.0000'`, discounts null (`:190-201`).
- Currency = `$company->currency` always; scale = `getScale($company->currency)` (`:38`, `:53`).
- `document_number => null` (`:51`), same deferred numbering.
- `appendLines()` (`:65-101`) re-locks the document and refuses anything that is not a Draft PO for the same company **and same supplier** (`:72-81`), then renumbers `line_number` from `max+1` (`:86`) and recomputes header totals from all lines (`:88-97`).

### 3.2 Editor autosave — `POST /documents/auto-save` → `DraftPersistenceService`

Request class: `apps/api/app/Modules/Document/Presentation/Requests/AutoSaveDraftRequest.php`. Type must be one of seven (`:97-105`), `purchase_order` mapped to ability `purchase-orders.create` (`:101`); `authorize()` enforces it (`:154-171`).

Rules (`:174-243`): `draft_id` nullable uuid; `type` required enum-restricted; `partner_id` nullable uuid scoped; `document_date`/`due_date` nullable date; `lines` `sometimes|array` (**may be empty**); `lines.*.id` `nullable|string|max:64` (**not uuid** — the editor mints `line-<epoch>-<rand>` ids, `:203-212`); `lines.*.quantity` regex `^-?\d+(\.\d{1,4})?$` (**sign open**, `:216`); `lines.*.unit_price` regex `^-?\d+(\.\d{1,3})?$` (`:217`); `lines.*.tax_rate` 2dp 0-100 (`:218`); `lines.*.tax_configuration_id` same scoped exists as create (`:231-239`).

**Explicitly NOT declared, therefore silently DROPPED by `validated()`:** `line_total`, `discount_percent`, `discount_amount`, `free_quantity`, `price_entry_mode` (documented at `:39-56`). The frontend *does* send them (`DocumentForm.tsx:255` → `buildAutoSaveLinePayload`), they just never reach persistence.

`DraftPersistenceService` (`apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php`, 923 lines):
- `saveDraft` locks the row `lockForUpdate()` scoped to tenant+company (`:105-115`); returns **null** (no document) when a create payload carries no line (`:116-120`, `:141-153`).
- Update branch: `assertTypeMatches` (`:186-198`) then `assertDraftEditable` — refuses if fiscally immutable or **not Draft** (`:205-215`). So autosave cannot touch a Confirmed PO even though `PATCH` can.
- Create branch writes `currency` from the company (`:246-247`, `:262`), `document_number => null` (`:255-258`), `total/tax_amount/subtotal` all `'0.00'` (`:263-265`).
- Line totals are **recomputed server-side, always net-of-nothing**: `line_total = bcmul(quantity, unit_price, scale+1)` truncated to scale — `:558-561` (single-line update) and `:756` (batch insert). **No discount, no tax.**
- `tax_rate` persisted as `$lineData['tax_rate'] ?? 0` (`:439`, `:789`).
- Float casts exist only at the audit-event boundary on already-stringified values (`:447-449`, `:566-572`, `:800-806`) — documented as rule-8 event signatures, not a precision violation.

**Practical consequence for the matrix:** while typing in the PO editor, the server-side draft has line totals that ignore every discount and every Total-mode entry; the real numbers only exist after an explicit Save (POST/PATCH).

---

## 4. State machine

### 4.1 Statuses

`apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php:9-14`: `Draft`, `Confirmed`, `Posted`, `Paid`, `Received`, `Cancelled`.
- `isEditable()` → true for **Draft and Confirmed** (`:19-25`).
- `isDeletable()` → true for **Draft only** (`:30-36`).
- `isTerminal()` → true for `Cancelled` only (`:48-54`).

Adjacency map, `apps/api/app/Modules/Document/Domain/Services/DocumentStatusMachine.php:120-146`:
```
Draft     -> Confirmed, Cancelled
Confirmed -> Draft, Posted, Cancelled
Posted    -> Paid, Cancelled
Paid      -> Posted
Cancelled -> (terminal)
Received  -> []          <-- no outgoing edges; nothing in this machine writes Received either
```
Self-loops are always forbidden (`:52-59`) — idempotence is the caller's job (`DocumentStatusService.php:104-108`).

`Received` is written by the goods-receipt path, not by this machine (`DocumentStatusMachine.php:39-41` says so explicitly).

### 4.2 Confirm — controller

`PurchaseOrderController::confirm` (`:681-762`):
1. `Document::forCompany($companyId)->ofType(PurchaseOrder)->where('id', …)->exists()` → 404 (`:690-699`).
2. Inside `DB::transaction`, re-fetch with `lockForUpdate()` (`:702-711`).
3. **Idempotency check under the lock** (`:713-720`): if not Draft and status === `Confirmed`, return the document **silently, 200, no re-confirm**. Any other non-Draft status → `\DomainException('Only draft purchase orders can be confirmed')`.
4. `PurchaseOrderService::confirm($lockedDocument, $user->id)` (`:723`).
5. `UnpricedPurchaseOrderLineException` caught **before** `\DomainException` (`:726-735`) → 422 with `UnpricedPurchaseOrderLineException::ERROR_CODE`.
6. `\DomainException` → 422 `INVALID_STATUS_TRANSITION` (`:736-738`).

### 4.3 Confirm — what actually happens

`apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php`:
- Type guard (`:53-57`), Draft guard (`:59-63`).
- **`guardAgainstUnpricedLines`** (`:90-109`): every non-`is_bonus_line` line must have `bccomp(unit_price, '0', scale) > 0`, scale from `getScaleSafe($purchaseOrder->currency, 3)` (`:92`). Otherwise `UnpricedPurchaseOrderLineException`.
- `confirmAndAllocateCosts` (`:114-149`) inside `DB::transaction`:
  - `DocumentStatusService::transition($po, Confirmed, ['confirmed_at' => now(), 'confirmed_by' => $actorId])` — **this is where the number is allocated** (`:127-130`).
  - `TaxCalculationService::calculateDocumentTaxes($po)` (`:133`).
  - `$po->update(['tax_amount' => $taxResult->totalTax, 'total' => $taxResult->total])` — **`subtotal` is NOT updated** (`:136-139`).
  - `TaxCalculationService::snapshotTaxDetails` (`:142`).
  - `LandedCostService::allocateCostsAndTaxes($po, $taxResult)` (`:145`) — this is why additional costs must be posted **before** confirm (see `apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts:10-15`).
  - `event(new PurchaseOrderConfirmed(...))` with `requireDocumentNumber()` (`:154-171`, `Document.php:545-560`).
- **No stock reservation.** Grep shows no reservation call on the PO confirm path; reservation release exists only for SalesOrder (`DocumentPostingService.php:558-571`).

`TaxCalculationService::calculateSubtotal` (`:348-376`) recomputes the taxable base as `Σ CurrencyScale::bcformat($line->calculateTotal($scale+1), $scale)` — i.e. **from `qty × unit_price − discount`, not from the persisted `line_total`**. Then `total = subtotal + totalTax` (`:334-335`).

### 4.4 "Cancel" — what exists instead

There is no PO cancel endpoint (§0.1). The two withdrawal paths:

**(a) Revert (Confirmed → Draft)** — `POST /documents/{id}/revert`, `can:documents.update` (`routes.php:84-87`) → `DocumentController::revert` (`:207-246`) → `DocumentPostingService::revert` (`:518-535`) → `revertPurchaseOrder` (`:587-637`). It refuses with `\DomainException` (mapped to 422, code == message, `DocumentController.php:231-238`) when:
- status is not Confirmed → `'Only confirmed documents can be reverted. Current status: X'` (`:591-595`);
- **any line has a goods receipt** → `PURCHASE_ORDER_HAS_RECEIPTS` (`:605-607`);
- the PO's `source_document_id` resolves to a `PurchaseQuoteRequest` → `PURCHASE_ORDER_FROM_RFQ` (`:609-620`);
- any supplier invoice references the PO (`source_document_id` or `payload->supplier_invoice->source_document_ids`) → `PURCHASE_ORDER_HAS_SUPPLIER_INVOICES` (`:622-633`).
On success: `transition(Draft, ['confirmed_at' => null, 'confirmed_by' => null])` (`:635-638`). **The document_number is kept** (never renumbered, `DocumentStatusService.php:206-211`).
Revert on an already-Draft document is a silent no-op returning the document (`DocumentPostingService.php:520-523`).

**(b) Delete (Draft only)** — `DELETE /purchase-orders/{id}` soft-deletes (§2.7). After partial receipt the PO is Confirmed, so delete is refused with `DOCUMENT_NOT_DELETABLE`.

**So: cancel after partial receipt is blocked**, but by two different mechanisms — revert by `PURCHASE_ORDER_HAS_RECEIPTS`, delete by `isDeletable()`. There is **no way to close/cancel a partially-received PO at all** in the current code: it stays Confirmed forever unless fully received (which flips it to `Received` in `GoodsReceiptService`).

---

## 5. Idempotency / duplicate protection

- **`POST /purchase-orders`: none.** No `Idempotency-Key` header handling anywhere in the Document module (grep for `idempotency` in `app/Modules/Document` returns only the confirm-lock comments). No unique constraint on (partner, date, total). Two identical POSTs → two Draft POs, both unnumbered.
- **`POST /{id}/confirm`: idempotent** — the `lockForUpdate()` + `status === Confirmed → return` short-circuit (`PurchaseOrderController.php:713-720`). A second confirm returns 200 with the same document and **does not** re-run landed-cost allocation or re-dispatch `PurchaseOrderConfirmed`.
- **`POST /documents/{id}/revert`: idempotent on Draft** (`DocumentPostingService.php:520-523`).
- **`POST /documents/auto-save`**: the client supplies `draft_id`; without one every call creates a new draft (`DraftPersistenceService.php:105-122`). The FE holds the id after the first response (`DocumentForm.tsx` autosave hook), but a page reload before the first response can orphan a draft.
- **Numbering race** is handled: one conditional UPDATE over `(id, expected Draft status, document_number IS NULL)` plus a `lockForUpdate()` on `document_sequences` inside a nested transaction/savepoint (`DocumentStatusService.php:186-220`; `DocumentNumberingService.php:42-66`).
- `receive` has no idempotency guard visible at the controller level (`PurchaseOrderController.php:764-866`) — it delegates to `GoodsReceiptService` (out of scope here).

---

## 6. Web frontend

### 6.1 Routes — `apps/web/src/routes/index.tsx`

| Path | Element | Guard | Line |
|---|---|---|---|
| `/purchases` | redirect to `/purchases/suppliers` | — | `:839` |
| `/purchases/orders` | `DocumentListPage documentType="purchase_order"` | `RequirePermission moduleKey="purchases"` | `:927-935` |
| `/purchases/orders/new` | `DocumentForm documentType="purchase_order"` | `RequirePermission permission="purchases.create"` | `:937-945` |
| `/purchases/orders/:id` | `PurchaseOrderDetailPage` | `moduleKey="purchases"` | `:947-955` |
| `/purchases/orders/:id/edit` | `DocumentForm documentType="purchase_order"` | `permission="purchase-orders.update"` | `:957-965` |
| `/purchases/receipts` | `GoodsReceiptListPage` | `moduleKey="purchases"` | `:969-977` |
| `/purchases/receipts/new` | `StandaloneReceiptPage` | `permission="goods-receipt.create-standalone"` | `:979-987` |

**Gating asymmetry worth a scenario:** the *create* route is gated on **`purchases.create`**, which is a **UI-only alias**, not a backend permission — `apps/web/src/hooks/uiAliasPermissions.ts:7` maps it to roles `['admin','purchases','manager']`, and the file's own header says "UI grouping gates — NOT backend authorization." The backend route demands `purchase-orders.create` (`routes.php:288`). A principal holding `purchase-orders.create` but none of those three roles is shown a "Permission Denied"/redirect by the FE while the API would accept them; conversely a `manager` without `purchase-orders.create` reaches the form and gets a 403 on submit. `moduleKey="purchases"` resolves to backend permission `purchases.view` (`apps/web/src/hooks/usePermissions.ts:32`).

`RequirePermission` behaviour on denial: renders `fallback` if given, else `<Navigate to="/dashboard" replace state={{permissionDenied:true}}/>` when not already on `/dashboard` (`apps/web/src/features/auth/components/RequirePermission.tsx:64-79`).

### 6.2 The editor — `apps/web/src/features/documents/components/DocumentLineEditor.tsx` (1245 lines)

Client-side arithmetic (all bcmath-style string helpers, no `parseFloat`):
- `calculateDiscountedSubtotal(qty, unitPrice, discountPercent, discountAmount)` = `qty×unitPrice` minus percent (else flat amount), floored at `'0.000'` (`:121-136`).
- `calculateLineTax(discountedSubtotal, taxRate)` = `subtotal × rate/100` (`:138-140`).
- **`calculateLineTotal(qty, unitPrice, taxRate, discountPercent, discountAmount)` = `discountedSubtotal + tax` → GROSS / TTC** (`:142-151`).
- `calculateTotalFromNetAmount(netAmount, taxRate)` = `net + tax` → also **GROSS** (`:153-156`).
- `calculateNetExtendedAmount(line)` = `calculateDiscountedSubtotal(...)` → **NET** (`:158-165`).

So `DocumentLine.line_total` in FE state is **always tax-inclusive**.

`handleUpdateLine` (`:547-606`):
- **Unit mode** (`:592-598`): `line_total = calculateLineTotal(qty, unit_price, tax_rate, discounts)` → gross.
- **Total mode** (`:572-591`): `netTotal` = the value the operator typed into the total cell (or `calculateNetExtendedAmount`), then `unit_price = deriveUnitPrice(line, netTotal)` and **`line_total = calculateTotalFromNetAmount(netTotal, tax_rate)` → gross**. Blank stays blank in both directions (`:585-589`, the W2-6 gate-r2-C1 guard).

`deriveUnitPrice` (`:339-368`): total mode only; qty ≤ 0 → `'0.000'`; percent-discount branch divides the net by `qty×(1−pct)` at working scale 4 then `bcdiv(..., 3)`; amount-discount branch divides `(net + discount_amount)` by qty; else `net/qty`.

**Price-entry-mode switch UI** (`:848-866`): a `<button type="button" aria-pressed={mode==='total'}>` whose label is `t('sales:lineItems.priceEntryMode.unit'|'total')`. It renders **only when `purchaseBonusEnabled`** (`:855`), which is `documentType === 'purchase_order' && hasModule('PurchaseBonus') && companyConfig?.purchase_bonus_enabled === true` (`:334-337`). `companyConfig.purchase_bonus_enabled` is served by `CompanyConfigController.php:80` = `PurchaseBonusGate::enabledFor($company)`. So the FE toggle and the backend validator share one gate.
Total mode renders a `DraftMoneyInput` whose `initialValue` is `calculateNetExtendedAmount(line)` (**net**) and whose `onCommit` fires `handleUpdateLine(id, { line_total: value })` (`:812-838`); unit mode renders a `MoneyInput` bound to `unit_price` (`:839-854`).

**Testids: none.** `grep -c data-testid` = 0 in `DocumentLineEditor.tsx`, `DocumentForm.tsx`, `DocumentListPage.tsx`, `PurchaseOrderDetailPage.tsx`. Usable selectors: `aria-label={t('sales:lineItems.unitPrice')}`, `aria-pressed` on the mode button, `id="line-price-input-<lineId>"` (`:814`), `id="line-price-hint-<lineId>"` (`:813`), form ids `partner_id` / `issue_date` (`DocumentForm.tsx:581-614`).

### 6.3 The submit payload — `apps/web/src/features/documents/linePayload.ts` + `DocumentForm.tsx`

`buildLinePayload(line)` (`linePayload.ts:96-123`) sends: `quantity`, `unit_price` (blank passed through untouched, `:99-105`), **`line_total: line.line_total`** (`:106`), `price_entry_mode` (default `'unit'`, `:107`), `discount_percent`, `discount_amount` (`:108-109`), `product_id` **or** `service_id` (`:111-117`), `free_quantity` **omitted when zero/blank** (`:118-120`, so the `prohibited` rule doesn't 422 non-bonus tenants), and exactly one of `tax_configuration_id` / `tax_rate` via `applyLineTax` (`:58-67`) — configuration id wins, and a blank rate is omitted so the server can resolve from the product.

`buildAutoSaveLinePayload` (`:139-152`) additionally coerces a blank `unit_price` and a blank `line_total` to `'0'` — draft-only.

`findBlankPriceLineIds` (`:161-171`) blocks submit client-side when `unit_price` is blank **or** (total mode and `line_total` blank).

`DocumentForm::onSubmit` (`:451-486`): blocks on blank prices (`:457-462`), strips credit-note-only `reason` (`:466`), then posts `{...form fields, type, lines: lines.map(l => ({...buildLinePayload(l), description: l.description})), external_document_number, external_document_date}`. Form fields include `partner_id` and **`issue_date`** (`:48-49`, `:219-220`) — the backend normalises `issue_date` → `document_date` (`PurchaseOrderController.php:373-376`, `:531-534`). `external_document_number`/`external_document_date` are sent (`:481-483`) but **not declared in `CreateDocumentRequest`**, so they are dropped.
Endpoint: `documentTypeToApiEndpoint[effectiveType]` (`:98`, `:203`); create = `apiPost` (`:391`), edit = `apiPatch` to `${endpoint}/${id}` (`:425-426`). After a successful **create of a purchase order** the FE redirects to **`/edit`** rather than the detail page, so the operator can add additional costs (`:405-410`).

### 6.4 K1-S1-12 — does the FE reach `PriceEntryMode::Total`, and is a gross value stored in the NET column?

**Yes to both, conditionally.**

- Reachability: the mode button exists only when `purchase_bonus_enabled` is true, i.e. tenant has the `PurchaseBonus` module and company country ∈ `['TN']` (`DocumentLineEditor.tsx:334-337`; `PurchaseBonusGate.php:25-36`). For a **TN parapharmacy** the country half is satisfied; the module half is a `compatible_extras` opt-in (`config/verticals.php:47`). So: **enable the extra and the path is live; leave it off and the FE hides the toggle and the API `Rule::in(['unit'])` refuses `total`.**
- Value semantics: in Total mode the FE writes `line.line_total = calculateTotalFromNetAmount(netTotal, tax_rate)` = **net + tax** (`DocumentLineEditor.tsx:589`, `:153-156`) and `buildLinePayload` ships that field verbatim (`linePayload.ts:106`). The backend Total branch does `lineTotal = bcformatStrict($line['line_total'], scale)` and writes it straight into `document_lines.line_total` (`PurchaseOrderController.php:100-101`, persisted at `:445`), then derives `unit_price = line_total / qty` (`:102-103`).
  → **On a 19 % TN line the stored NET `line_total` and the derived `unit_price` are both inflated by the VAT the operator never intended to include, and the document `subtotal` (Σ line_total, `:400-405`) is a TTC figure sitting in the HT column.** `tax_amount` is then computed **on top of** that inflated base (`:401`), so VAT-on-VAT.
  Contrast: in **Unit** mode the backend discards the FE's gross `line_total` and recomputes net via `DocumentLine::computeLineTotal` (`:105-116`), which is why the gross/net mismatch has been invisible so far. `docs/architecture/precision-contract.md:190` already carries the deferred note "DocumentLineEditor client-side line-total still float (backend recomputes authoritatively)" — that "backend recomputes" premise is **false for Total mode**.
- Suggested probe: TN company, PurchaseBonus on, one line qty `10`, tax 19 %, operator types total `100.000` in Total mode → expect (bug) `document_lines.line_total = 119.000`, `unit_price = 11.900`, `documents.subtotal = 119.000`, `tax_amount = 22.610`, `total = 141.610`; the correct values are `100.000 / 10.000 / 100.000 / 19.000 / 119.000`.

### 6.5 `PurchaseOrderDetailPage.tsx`

`apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx`. Actions:
- Confirm: `apiPost('/purchase-orders/{id}/confirm', {})` (`:154-155`), dialog gated on `confirmAction === 'confirm'` (`:679-686`).
- Revert: `useRevertDocument(id, 'purchase_order')` → `apiPost('/documents/{id}/revert', {})` (`:151`; `apps/web/src/features/documents/hooks/useRevertDocument.ts:30`), dialog at `:689-696`.
- Receive: `api.post('/purchase-orders/{id}/receive', request)` (`:171-173`).
- **No cancel and no delete control anywhere on the page** (grep of `delete|destroy` in `DocumentListPage.tsx` also returns nothing).
- Status-conditioned UI: receipt status shown for `['confirmed','received','partially_received']` (`:318`); payments tab / record-payment for `['confirmed','received']` (`:325`, `:358`, `:653`); landed-costs tab only when `status === 'received'` (`:650`).
- Supplier-invoice CTA gated on the UI alias `purchases.create` **and** at least one uninvoiced receipt line (`:144`, `:345`).

---

## 7. Existing tests to mirror

### 7.1 Backend — `apps/api/tests/`

Files that exercise the `purchase-orders` HTTP surface (grep `purchase-orders` under `tests/`):
- **`tests/Feature/Procurement/PurchaseBonusQuantityEntryTest.php`** — the closest fixture to our tenant. Cases: `free_quantity` prohibited when the gate is off (`:47-63`), gate refused for FR even with the module (`:65-81`), unit-mode line-total math + free-qty persistence (`:83-113`), explicit-null line fields preserved (`:115-142`), `is_bonus_line` preserved across PATCH (`:144-181`), **Total mode stores the entered total + scale-6 `landed_unit_cost`** (`:184-215`), Total mode requires a 3-dp `line_total` (missing → 422, `100.0001` → 422) (`:217-256`).
  **Fixtures (`bootTenant`, `:275-317`):** `Tenant::create(vertical: Vertical::Parapharmacy|Retail, status Active, plan Professional)` → `Company::create(country_code 'TN'|'FR', currency TND|EUR, locale fr_FR, timezone Africa/Tunis)` → `PermissionRegistrar::setPermissionsTeamId(tenant->id)` → `seed(RolesAndPermissionsSeeder::class)` → `User::create(status Active)->assignRole('admin')` → `UserCompanyMembership::create(role Admin)` → `CompanyContext::setCompanyId()` → `Partner::create(type 'supplier', code 'PBSUP')`. Payload helper `purchaseOrderPayload()` (`:261-269`) = `{partner_id, document_date, lines}` — no location, no currency.
- **`tests/Feature/Document/PurchaseOrderUnpricedLineConfirmTest.php`** — confirm refusals: zero unit price (`:142`), one unpriced line among several (`:161`), the error is **not** `INVALID_STATUS_TRANSITION` (`:175-187`), bonus line at zero allowed (`:190`), fully priced confirms (`:205`). Same fixture shape (`:73-107`), `country_code 'TN'`, `RolesAndPermissionsSeeder`, `CompanyContext::setCompanyId`. Helper `createDraftPurchaseOrder(array $lines)` (`:229`) builds the Document/DocumentLine rows directly rather than through HTTP.
- **`tests/Feature/Procurement/PurchaseOrderLineMutationGuardTest.php`** — the `PO_LINES_LOCKED_BY_RECEIPTS` guard. Fixture (`:52-…`) also `Vertical::Parapharmacy`, and additionally creates a `Location` (warehouse), a `Product` with `ProductType`, and a `Partner` with `PartnerType` + `PartnerTaxStatus`; injects `GoodsReceiptService` to post a receipt.
- **`tests/Feature/Document/DeferredDocumentNumberingTest.php`** — the "draft carries no number" contract.
- **`tests/Feature/Document/AutoSaveRouteHardeningTest.php`** — autosave authz/validation (named in `AutoSaveDraftRequest.php:200-202`).
- **`tests/Feature/Taxation/TaxSnapshotPurchaseOrderTest.php`** — tax snapshot at confirm.
- **`tests/Feature/Inventory/GoodsReceiptTest.php`, `GoodsReceiptDestinationTest.php`, `GoodsReceiptLedgerWriteTest.php`, `PurchaseBonusGoodsReceiptTest.php`** — receive side.
- **`tests/Feature/Procurement/AutoGeneratedPurchaseOrderExposureTest.php`** — the `include_auto_generated` index filter (`PurchaseOrderController.php:230-233`).
- **`tests/Feature/Treasury/PurchaseOrderAllocationRefusedTest.php`**, **`tests/Unit/Document/PurchaseOrderServiceTest.php`**.

### 7.2 E2E — `apps/web/e2e/`

- **`apps/web/e2e/purchasing/additional-costs.spec.ts`** — **fully mocked** (`page.route('**/api/v1/...')` with hand-written JSON, `:1-60`). Uses `authenticatedPage` from `../fixtures`. Its mock PO uses `currency: 'TND'`, `tax_rate: '19'`, status `draft` (`:3-40`). Good for UI-shape assertions, useless as a backend contract.
- **`apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts`** — **real stack**, tenant `demo-pharmacy-tn`, real login (`:1-45`). `loginAsRole(page,'owner')` + `apiRequest(page, 'POST'|'GET'|'DELETE', path, body)` from `./helpers`; supplier via `createSupplier` and location constant `WAREHOUSE_LOCATION_ID` from `./w2b-support`; products via `createW4Product`, and `poAndReceive` from `./w4-support`. Direct calls: `POST /purchase-orders` (`:78`, `:181`, `:227`, `:256`, `:297`), `POST /purchase-orders/{id}/confirm` (`:200`, `:246`, `:270`, `:314`), `POST /purchase-orders/{id}/receive` (`:274`, `:322`, `:346`), `DELETE /purchase-orders/{id}` (`:96`). Its header comment records that **landed costs are allocated at confirm and re-allocated at receipt, so an additional cost added after confirm leaves `allocated_costs` at 0 until something re-allocates** (`:10-15`), and that `GET /documents/{id}/landed-cost-breakdown` **recomputes in FLOAT and returns JSON numbers** (`:17-21`).
  Note `MTP-PUR-15` (`:68-110`) is the canonical shape for a "gate probe then clean up" scenario, and records the refusal arm as PARTIAL because `demo-pharmacy-tn` has only a TN company (campaign debt C-9).
- Siblings: `purchasing-matching.spec.ts`, `purchasing-receipt-validation.spec.ts`, `purchasing-rfq.spec.ts`; `money-campaign/idempotency.spec.ts` exists but contains **no** `purchase-orders` call.
- FE unit tests near the editor: `apps/web/src/features/documents/components/__tests__/DocumentLineEditor.purchasePriceDefault.test.tsx` (sets `purchase_bonus_enabled` from a fixture, `:87`), `DocumentLineEditor.quantityStep.test.tsx`, `DocumentLineEditor.test.tsx`, and `apps/web/src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx`. Company-config fixtures default `purchase_bonus_enabled: false` (`apps/web/src/test/fixtures/companyConfig.ts:29`, `:43`, `:63`, `:86`, `:104`, `:122`).

---

## 8. Precision-contract touchpoints

`docs/architecture/precision-contract.md`:
- §`unit_price` is context-overloaded (`:192-205`): **"B2B / Documents (quotes, orders, invoices) → `unit_price` means net / HT"** (`:199`). Purchase-order lines are documents, so **PO `unit_price` and `line_total` are HT/net**. Confirmed by the backend: `DocumentLine::computeLineTotal` is documented as "canonical NET line total … before tax" (`DocumentLine.php:259-272`), and `TaxCalculationService::calculateSubtotal` treats `$line->calculateTotal()` as the taxable base (`:348-370`).
- Known deferred item at `:190`: "DocumentLineEditor client-side line-total still float (backend recomputes authoritatively)". As shown in §6.4 the parenthetical does **not** hold in Total mode — worth flagging in the wave report.
- **Float casts / `number_format` in PO-adjacent code:**
  - `apps/api/app/Http/Controllers/Api/DocumentAdditionalCostController.php:121-129` — `(float)` on `additionalCosts()->sum('amount')`, `$lines->sum('line_total')`, `$line->line_total`, `$line->quantity`, `$line->unit_price`. This is the `landed-cost-breakdown` endpoint, which the money campaign already documents as float-only evidence (`purchasing-landed-cost.spec.ts:17-21`). **Read the persisted `document_lines.allocated_costs` / `landed_unit_cost` columns for exact verdicts, never this endpoint.**
  - `PurchaseOrderController.php` and `PurchaseOrderService.php`: **zero** `(float)` / `number_format` / `floatval` (grep clean).
  - `DraftPersistenceService.php:447-449`, `:566-572`, `:800-806`: `(float)` only when populating audit-event DTOs whose signatures are frozen by rule 8; the persisted values are strings.
- Scale handling in the PO controller uses `CurrencyScale::bcformatStrict` throughout (`:92`, `:94`, `:100`, `:102`, `:107`, `:124`) with `$this->scale()` — but see §2.6: `scale()` is the **no-argument** `getScale()`, i.e. company-currency-derived, not document-currency-derived.

---

## 9. EDGE CASES WORTH PROBING

Each with the line that makes it risky.

**E-1 — Line discount 100 %.** `computeLineTotal` applies the percent branch and floors at zero (`DocumentLine.php:290-292`, `:297-299`), and `discount_percent` allows `max:100` (`CreateDocumentRequest.php:132`). Result: `line_total = 0.000`, `unit_price` still > 0 → the confirm guard passes (`PurchaseOrderService.php:99`) and a zero-value PO line confirms. Probe: does the receipt/WAC path handle a 0-value non-bonus line?

**E-2 — `discount_amount` exactly equal to gross, and `discount_amount` > gross.** `LineDiscountAmountWithinGross` compares against `bcmul(qty, unit_price, scale)` and only fails when **strictly greater** (`apps/api/app/Modules/Document/Presentation/Rules/LineDiscountAmountWithinGross.php:87-90`). Equal → accepted → `line_total 0.000`. Also: the rule reads the RAW `lines` array (`:41-48`, `:71`), so in **Total mode** it compares against a gross built from a `unit_price` the backend is about to throw away.

**E-3 — Both `discount_percent` and `discount_amount` on one line.** Nothing forbids it (`CreateDocumentRequest.php:132-142`); `computeLineTotal` silently applies the percent and **ignores the amount** (`DocumentLine.php:290-295`), yet both are persisted (`PurchaseOrderController.php:743-744`). The FE hint text will disagree with the stored numbers.

**E-4 — Total-mode `subtotal` vs `total` divergence at confirm.** `store` writes `subtotal = Σ line_total` (`:400-405`) but `PurchaseOrderService::confirmAndAllocateCosts` updates only `tax_amount` and `total` (`:136-139`), and `TaxCalculationService::calculateSubtotal` re-derives the base from `qty × unit_price` (`:348-370`). With qty 7 and total 100.000 → `unit_price 14.285`, recomputed base `99.995`, so after confirm `subtotal = 100.000` while `total = 99.995 + tax`. **The header does not add up.**

**E-5 — K1-S1-12: gross `line_total` stored in the NET column in Total mode.** `DocumentLineEditor.tsx:589` (+`:153-156`) vs `PurchaseOrderController.php:100-101`. See §6.4 for the exact expected/actual numbers.

**E-6 — Total mode silently drops discounts.** `PurchaseOrderController.php:98-104` never touches `discount_percent`/`discount_amount`, but they are still written to the row (`:743-744`) and the FE's `deriveUnitPrice` *does* factor them in (`DocumentLineEditor.tsx:351-365`). Enter a total + a 10 % discount and compare FE display to `document_lines`.

**E-7 — Quantity with 5 decimals; quantity `0`; negative quantity.** `regex:/^\d+(\.\d{1,4})?$/` + `gt:0` (`CreateDocumentRequest.php:120`) → 5dp is 422 ("must have at most 4 decimal places", `:166`); `0` is 422; negative fails the regex (no `-` allowed). **But `AutoSaveDraftRequest.php:216` allows a leading `-`**, so a negative quantity can be autosaved into a draft and only be caught on the real submit.

**E-8 — `unit_price` with 4 decimals; `unit_price 0`.** 4dp → 422 (`:124`, message `:168`). `unit_price: '0'` passes `min:0` at create, then **confirm** refuses with `UnpricedPurchaseOrderLineException` unless `is_bonus_line` (`PurchaseOrderService.php:90-108`). So the failure surfaces one step later than the operator expects.

**E-9 — PATCH a CONFIRMED purchase order.** `Document::isEditable()` is true for Confirmed (`DocumentStatus.php:19-25`, `Document.php:621-624`), so `PATCH /purchase-orders/{id}` on a confirmed, **numbered**, already-landed-cost-allocated PO succeeds: it deletes and recreates every line (`PurchaseOrderController.php:559-563`) and rewrites `subtotal/tax_amount/total` (`:620-626`) **without re-running the tax snapshot or landed-cost allocation**. Only blocked once receipts exist (`:539-554`). This is the single highest-value probe in the matrix.

**E-10 — Duplicate POST with the identical payload.** No idempotency anywhere (§5) → two drafts. Then confirm both → two numbers burned. Compare with the fixed defect the code comments describe (`PurchaseOrderController.php:385-389`).

**E-11 — Confirm twice / concurrent confirm.** Second call returns 200 with no side effects (`:713-720`). Concurrency: `lockForUpdate()` (`:704-707`) + conditional number UPDATE (`DocumentStatusService.php:186-220`). Probe two parallel confirms and assert exactly one `PurchaseOrderConfirmed` event and one number.

**E-12 — Non-UUID / empty `{purchaseOrder}` segment.** Only `receipt-lines` carries `whereUuid` (`routes.php:284`); `show`/`update`/`destroy`/`confirm`/`receive`/`receipt-status` do not (`:278-309`). A non-UUID id reaches `->find($id)` against a PG `uuid` column — the K-6 500 shape. Probe `GET /api/v1/purchase-orders/not-a-uuid` and `POST /api/v1/purchase-orders//confirm`.

**E-13 — Supplier belonging to another company (same tenant).** `partner_id` is `ScopedExists::tenantAndCompany('partners', tenant, company)` (`CreateDocumentRequest.php:66-70`) → expect 422, not 404. Same for `lines.*.product_id` (`:101-106`) and `location_id` (`:93-99`, plus `is_active`). Probe an **inactive** location too — the rule requires `is_active = true`.

**E-14 — Second company: numbering collision and isolation.** Numbers are per `company_id` (`DocumentNumberingService.php:47-66`), so company B's first confirm also yields `PO-2026-0001`. Rule 22 "second-of-everything": confirm one PO in each company on the same day and assert both numbers exist and neither list leaks (`baseQuery()` = `Document::forCompany($companyId)`, `HandlesDocuments.php:44-49`).

**E-15 — `currency` on create.** `nullable|string|size:3` with **no allowlist** (`CreateDocumentRequest.php:84`), while `scale()` resolves from the *company* currency (`PurchaseOrderController.php:76-79`). Probe `currency: 'XAF'` (0-decimal) on a TND company and check what scale the lines are stored at, then confirm (`getScaleSafe($po->currency, 3)`, `PurchaseOrderService.php:92`) — the create scale and the confirm-guard scale can disagree.

**E-16 — Revert a PO that came from an RFQ, or that has a supplier invoice.** `PURCHASE_ORDER_FROM_RFQ` (`DocumentPostingService.php:609-620`) and `PURCHASE_ORDER_HAS_SUPPLIER_INVOICES` (`:622-633`). Both surface as 422 with **code == message** (`DocumentController.php:231-238`), which the FE renders raw (`useRevertDocument.ts` has no `onError`; `PurchaseOrderDetailPage.tsx:211-216` only handles success) — likely an untranslated raw string in the toast.

**E-17 — Cancel after partial receipt.** No cancel endpoint; revert refuses `PURCHASE_ORDER_HAS_RECEIPTS` (`DocumentPostingService.php:605-607`); delete refuses `DOCUMENT_NOT_DELETABLE` (`PurchaseOrderController.php:663-665` + `DocumentStatus.php:30-36`). Confirm this dead-end is intended, and that the UI says something useful.

**E-18 — Autosave a draft, then confirm from the API without ever POSTing.** Autosave persists `line_total = qty × unit_price` with **no discount and no tax** (`DraftPersistenceService.php:756`, `:558-561`) and drops `price_entry_mode`/`free_quantity`/discounts entirely (`AutoSaveDraftRequest.php:39-56`). A draft confirmed straight from that state has different numbers than the same document saved through the form.

**E-19 — Autosave against a Confirmed PO.** `assertDraftEditable` refuses non-Draft (`DraftPersistenceService.php:205-215`) whereas `PATCH` allows Confirmed (E-9). Two write paths, two different rules on the same document.

**E-20 — `free_quantity` when the bonus gate is off.** Rule is `prohibited` (`CreateDocumentRequest.php:121-123`), and the FE omits zero/blank values precisely to avoid that 422 (`linePayload.ts:118-120`, `:83-89`). Probe a non-zero free quantity on a tenant without the `PurchaseBonus` extra and assert the 422 reaches the operator as a readable message.

**E-21 — `price_entry_mode: 'total'` posted by API when the gate is off.** `Rule::in(['unit'])` (`:126-128`) → 422 on `lines.0.price_entry_mode`. Worth asserting explicitly, because the FE simply hides the toggle.

**E-22 — `tax_rate` and `tax_configuration_id` sent together.** The configuration wins (`DocumentLineTaxResolver.php:64-69`), by design after defect N-1. A stale cached FE bundle sends both; assert the stored `tax_rate` matches the configuration, not the echoed number.

**E-23 — `tax_configuration_id` from another country / not `LINE_ITEMS`.** Rejected at validation (`CreateDocumentRequest.php:144-151`), and even if it slipped through, `rateFromConfigurationId` returns null and the resolver falls through (`DocumentLineTaxResolver.php:103-115`).

**E-24 — Mixed 0 % and 19 % lines on one PO.** Nothing forces a single rate; `store` accumulates per line (`PurchaseOrderController.php:394-405`) and confirm re-derives per rate bucket. Combine with a document-level `discount_amount` — `TaxCalculationService::calculateSubtotal` subtracts it from the subtotal (`:371-374`) and prorates it across rate buckets with largest-remainder (`:381-…`), a path the PO controller never populates but a conversion might.

**E-25 — `external_document_number` / `external_document_date`.** The FE sends them on every PO submit (`DocumentForm.tsx:481-483`) but `CreateDocumentRequest`/`UpdateDocumentRequest` never declare them → silently dropped by `validated()`. If the tester expects a supplier reference to persist, it will not.

**E-26 — Line-editor client ids reaching the update path.** The editor mints `line-<epoch>-<rand>` ids (`AutoSaveDraftRequest.php:203-212` documents this) and `update` deletes/recreates all lines (`PurchaseOrderController.php:559-563`), so **line ids change on every save**. Any Playwright step holding a line id across a save (e.g. a `quantities: { [lineId]: … }` receive payload) will break.

**E-27 — `due_date` / `valid_until` on update.** Create enforces `after_or_equal:document_date` (`CreateDocumentRequest.php:82-83`); update drops that constraint (`UpdateDocumentRequest.php:79-80`). A PATCH can set a due date before the document date.

**E-28 — `lines: []` on PATCH vs on autosave.** PATCH → 422 (`min:1`, `UpdateDocumentRequest.php:84`); autosave → accepted and **strips every line** (`AutoSaveDraftRequest.php:29-31` "a lineless DRAFT is a legal intermediate state"). Same gesture, two outcomes.

**E-29 — Permission split on the FE create route.** `/purchases/orders/new` is gated on the **UI alias** `purchases.create` (roles admin/purchases/manager, `uiAliasPermissions.ts:7`) while the API demands `purchase-orders.create` (`routes.php:288`). Probe a role that holds one and not the other in both directions.

**E-30 — `receive` free quantities without the gate.** `PurchaseOrderController.php:808-810` returns 422 `GOODS_RECEIPT_FAILED` "free_quantities is not enabled for this company" — a different code from the create-side `prohibited` 422. Also `location_id` on receive is the only place `validateLocationAccess` runs (`:800-806`, 403 `LOCATION_FORBIDDEN`), unlike create (§2.6).
