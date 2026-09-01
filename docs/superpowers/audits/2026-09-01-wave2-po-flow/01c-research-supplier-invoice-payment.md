# Research C — Supplier invoice (link receipts → match → post) + supplier payment + GL/payable truth

**Scope:** wave-2 code-truth research for a Tunisian parapharmacy (IziPOS) Playwright scenario matrix.
**Repo/worktree read:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow` (branch `dev`).
**Citations** are `path:line` relative to `apps/erp`. Everything below was read in source; anything inferred is marked **UNVERIFIED**.
**Read-only:** no repo file was modified; no test suite or server was run.

---

## 0. One-paragraph shape of the flow

A supplier invoice is a row in the unified `documents` table with `type = 'supplier_invoice'` (`apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:17`, prefix `SI` at `:64`). It is created **DRAFT** against one or more **PurchaseOrders** (not receipts — see §2), auto-matched by a 3-way matcher, and then **posted** straight `Draft → Posted` (no `Confirmed` step — `apps/api/app/Modules/Document/Domain/Services/DocumentStatusMachine.php:104-112`). Posting writes ONE GL journal entry (`source_type='supplier_invoice'`) clearing the 408 GR/IR accrual and crediting 401, sets `documents.balance_due = total`, and snapshots deductible VAT into `document_tax_details`. Payment happens on the **Treasury** `POST /api/v1/payments` endpoint with a `SupplierInvoice` allocation, which posts `Dr 401 / Cr bank` and moves cash OUT of a ledgered repository.

---

## 1. Routes + permissions (Procurement module)

All supplier-invoice routes live in `apps/api/app/Modules/Procurement/Presentation/routes.php`, in a group with middleware `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (`:76-81`).

| Method + path | Controller action | `can:` gate | line |
|---|---|---|---|
| `GET /api/v1/supplier-invoices` | `index` | `documents.view` | `:84-86` |
| `GET /api/v1/supplier-invoices/duplicate-reference` | `duplicateReference` | `documents.view` | `:89-91` |
| `GET /api/v1/supplier-invoices/{id}` | `show` | `documents.view` | `:94-97` |
| `POST /api/v1/supplier-invoices` | `store` | `documents.update` | `:100-102` |
| `POST /api/v1/supplier-invoices/{id}/match` | `match` | `documents.update` | `:105-108` |
| `POST /api/v1/supplier-invoices/{id}/link-receipts` | `linkReceipts` | `supplier-invoices.link-receipts` | `:111-114` |
| `POST /api/v1/supplier-invoices/{id}/post` | `post` | `documents.update` | `:117-120` |

**There is NO update, NO PATCH, NO delete and NO cancel route for a supplier invoice.** The controller itself says so verbatim: "`Procurement/Presentation/routes.php` exposes no update, no PATCH and no delete for a supplier invoice" (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:373-374`).

**No module gate.** The routes file states this explicitly: "No `module:Procurement` is added because 'Procurement' is not in the ModuleName enum and no vertical enables it by name. Access is governed by per-route `can:` permissions." (`apps/api/app/Modules/Procurement/Presentation/routes.php:20-23`).

Adjacent Procurement routes in the same file (first group, `:27-74`): `GET/PUT /procurement-policies` (`can:settings.view` / `settings.update`, `:33-38`), `POST /goods-receipts/standalone` (`can:goods-receipt.create-standalone`, `:40-42`), plus the RFQ (`purchase-quote-requests.*`) routes `:44-73`.

**Extra permission checks inside `store()`** (not on the route):
- `supplier-invoices.create-pending` — required when `invoice_first_delivered` OR `pending_receipt` is true; 403 otherwise (`SupplierInvoiceController.php:251-253`).
- `goods-receipt.create-standalone` — additionally required when `invoice_first_delivered` is true (`:255-257`).
- `supplier-invoices.approve-invoice-first` — checked at POST time inside the posting service, not the controller (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:692`).

All four permission strings are seeded: `apps/api/database/seeders/RolesAndPermissionsSeeder.php:149-152` (`goods-receipt.create-standalone`, `supplier-invoices.create-pending`, `supplier-invoices.link-receipts`, `supplier-invoices.approve-invoice-first`), granted to `manager` (`:571-572`) and `accountant` (`:582`).

**Listing scope:** `index()` builds from `$this->baseQuery()` (`SupplierInvoiceController.php:96`), which is `Document::forCompany($companyId)` where `$companyId = CompanyContext::requireCompanyId()` (`apps/api/app/Modules/Document/Presentation/Controllers/Concerns/HandlesDocuments.php:44-49`). Filters: `partner_id`, `status`, `match_status` (`:103-109`), `pending_receipt` (JSON payload probe with per-driver SQL, `:111-128`), date range, plus a search over `document_number` OR `partners.name` (`:78-86`). Cursor pagination (`:132`).

---

## 2. `CreateSupplierInvoiceRequest` — every rule

File: `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php`.

### 2.1 `source_document_ids` semantics — **PO ids, NOT receipt ids**

The docblock says it (`:27-29`) and `withValidator()` enforces it: every id must resolve to a `Document` whose `type === DocumentType::PurchaseOrder`, else "The source document must be a purchase order." (`:247-255`). Likewise `lines.*.source_line_id` must be a **PO line** id — validated with `DocumentLine::whereIn('document_id', $sourceDocumentIds)->pluck('id')` (`:299-316`).

`prepareForValidation()` normalises the legacy singular `source_document_id` into the array and back-fills `source_document_id = $sourceDocumentIds[0]` (`:46-61`).

### 2.2 The `invoice_first_delivered` / `pending_receipt` flags

`$invoiceFirstWithoutSources = $this->boolean('invoice_first_delivered') || $this->boolean('pending_receipt')` (`:72`). When true:
- `source_document_ids` becomes `['nullable','array']` instead of `['required','array','min:1']` (`:73`);
- `lines.*.source_line_id` becomes `['nullable','uuid']` instead of `['required','uuid']` (`:74`).

When `invoice_first_delivered` specifically is true, three fields flip to `required`: `location_id` (`:96-100`), `idempotency_key` (`:101-105`, `max:64`), and `lines.*.product_id` (`:115-119`).

Policy gate: if either flag is set and `ProcurementPolicyResolver::forCompany(...)->allowsInvoiceFirst()` is false, the request fails with "Invoice-first supplier invoices are disabled for this company." on the `pending_receipt` key (`:222-229`).

### 2.3 Full rule table (`rules()`, `:76-153`)

| field | rules | line |
|---|---|---|
| `invoice_first_delivered` | `sometimes, boolean` | `:77` |
| `pending_receipt` | `sometimes, boolean` | `:78` |
| `partner_id` | `required, uuid, ScopedExists::tenantAndCompany('partners',…)` | `:79-83` |
| `source_document_id` | `nullable, uuid, ScopedExists::tenantAndCompany('documents',…)` | `:84-88` |
| `source_document_ids` | required array min:1 (or nullable array in invoice-first) | `:89` |
| `source_document_ids.*` | `required, uuid, distinct, ScopedExists…('documents')` | `:90-95` |
| `location_id` | required iff `invoice_first_delivered`; `uuid`, `ScopedExists::company('locations',…)` | `:96-100` |
| `idempotency_key` | required iff `invoice_first_delivered`; `string, max:64` | `:101-105` |
| `external_reference` | `nullable, string, max:255` | `:106` |
| `external_date` | `nullable, date` | `:107` |
| `currency` | **`required, string, size:3`** — no allow-list, no company-currency check | `:108` |
| `issue_date` | `required, date` — **no period/backdating rule here** | `:109` |
| `due_date` | `nullable, date, after_or_equal:issue_date` | `:110` |
| `supplier_reference` | `nullable, string, max:255` (persisted as `external_document_number`) | `:111` |
| `notes` | `nullable, string, max:5000` | `:112` |
| `lines` | `required, array, min:1` | `:113` |
| `lines.*.source_line_id` | required uuid (nullable in invoice-first) | `:114` |
| `lines.*.product_id` | required iff invoice-first-delivered; `uuid`, `ScopedExists…('products')` | `:115-119` |
| `lines.*.variant_id` | `nullable, uuid` — **NOT scoped-exists** | `:120` |
| `lines.*.quantity` | `required, numeric, gt:0, regex:/^-?\d+(\.\d{1,4})?$/` | `:121-126` |
| `lines.*.unit_price` | `required, numeric, min:0, regex:/^-?\d+(\.\d{1,3})?$/` | `:127-132` |
| `lines.*.vat_rate` | `required, numeric, min:0, max:100, regex:/^-?\d+(\.\d{1,2})?$/` | `:133-139` |
| `lines.*.is_bonus_line` | `nullable, boolean` when `PurchaseBonusGate::enabledFor($company)`, else **`prohibited`** | `:146-148` |
| `lines.*.batch` | `nullable, array` | `:149` |
| `lines.*.batch.batch_number` | `required_with:lines.*.batch, string, max:100` | `:150` |
| `lines.*.batch.expiry_date` | `required_with:lines.*.batch, date_format:Y-m-d` | `:151` |
| `lines.*.batch.manufacturing_date` | `nullable, date_format:Y-m-d` | `:152` |

**Rule-19 regex ceilings: PRESENT and correct** for all three money/quantity/percent columns (`:125`, `:131`, `:138`), with custom messages at `:326-328`. The docblock states the contract at `:21-24`.
**Gap worth probing:** `lines.*.variant_id` has `uuid` but **no** `ScopedExists` (`:120`), unlike `product_id`.

### 2.4 Cross-field validation (`withValidator`, `:164-318`), in execution order

1. **Bonus lines** (runs BEFORE the short-circuit, `:187-212`): a bonus line on an invoice-first request is refused (`validation.bonus_line_not_supported_on_invoice_first`, `:196-199`); a bonus line whose `unit_price != 0` (bccomp scale 3) is refused (`validation.bonus_line_unit_price_must_be_zero`, `:204-210`).
2. Short-circuit if basic rules already failed (`:215-217`).
3. Invoice-first policy gate (`:221-229`).
4. Each `source_document_ids[i]` must be a `PurchaseOrder` (`:247-255`).
5. No PO may be `DocumentStatus::Cancelled` — "Cancelled purchase orders cannot be invoiced." (`:260-268`).
6. Every PO's `partner_id` must equal the request `partner_id` (`:273-282`).
7. Every PO's `currency` must equal the request `currency` — "The invoice currency must match all purchase order currencies." (`:284-295`).
8. Every `lines.*.source_line_id` must belong to one of the referenced POs (`:299-316`).

`PurchaseBonusGate`: requires module `PurchaseBonus` on the tenant config AND the company country in `config('procurement.bonus_quantity_countries', ['TN'])` (`apps/api/app/Modules/Procurement/Application/PurchaseBonusGate.php:17-37`). **TN parapharmacy is inside the allow-list by default.**

---

## 3. `CreateSupplierInvoiceService` — how the document is built

File: `apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php`. Entire creation runs in one `DB::transaction` (`:69`).

### 3.1 Lines are built from the REQUEST, not copied from the PO/receipt

Quantity, unit_price and vat_rate come straight from the request line (`:81-85`). The PO line is loaded only to inherit `product_id` / `variant_id` / `product_code` / `description` when the request omits them (`:154-158`, `:173-174`). **Nothing copies received qty or PO price into the invoice line** — the operator can invoice any qty/price and the matcher is what judges it.

### 3.2 `line_total` is NET — **report 07's claim is CONFIRMED**

`'line_total' => $ld['lineSubtotal']` at **`apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:185`**, where `lineSubtotal = bcround(bcmul(qty, unitPrice, scale+4), scale)` (`:92-94`). VAT is a separate column: `tax_amount` / `recoverable_tax_amount` = `lineTax` (`:181`, `:183`), `non_recoverable_tax_amount = '0.000'` (`:184`), `tax_recoverable = true` (`:182`). So on a supplier invoice line, `line_total` excludes VAT (unlike the POS `unit_price` trap in CLAUDE.md rule 19).

### 3.3 Precision

`$scale = scaleResolver->getScaleSafe($currency, 3)` (`:55`); working scale `= scale + 4` (`:90`). VAT is computed from the **un-rounded** high-precision subtotal (`:99`) and rounded once (`:101`). Rate fraction at scale 6 (`:96`).

### 3.4 Header

`Document::create` (`:120-147`) sets: `type = SupplierInvoice`, `fiscal_category = NonFiscal` (`:126`), `fiscal_status = Draft` (`:127`), `status = Draft` (`:128`), `document_date = issue_date` (`:130`), `subtotal`, `line_tax_amount`, `stamp_duty_amount='0.000'` placeholder (`:135`), `tax_amount`, `total = subtotal + lineTax` (`:137`), `external_document_number = supplier_reference` (`:138`), `payload.supplier_invoice = {source_document_ids, pending_receipt}` (`:140-145`), `match_status = Unmatched` (`:146`).

Then stamp duty (timbre) is resolved by the canonical `TaxCalculationService::calculateDocumentTaxes()` and persisted (`:197-202`), and `tax_amount`/`total` recomputed as `subtotal + lineTax + stampDuty` (`:209-215`). The invariant the GL later asserts is `total = subtotal + Σrecoverable_vat + non_recoverable_vat + stamp_duty` (`:204-206`). Stamp duty is only non-zero when an ACTIVE `is_stamp_duty` `DOCUMENT_TOTAL` `TaxConfiguration` matches the document type + date (`apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:274-306`); with none configured it is `0`.

### 3.5 Supplier resolution & document number

Supplier is `$validated['partner_id']` verbatim (`:123`) — no resolution from the PO (the request validator already forced them equal, §2.4/6).
Number: `DocumentNumberingService::generateNumber($tenantId, $companyId, DocumentType::SupplierInvoice)` (`:70`), i.e. `SI-YYYY-NNNN` from a `document_sequences` row keyed `(company_id, type, year)` under `lockForUpdate()` (`apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:42-67`).

### 3.6 Snapshot columns + auto-match at creation

For each non-bonus line with a `source_line_id`, `SupplierInvoiceMatchSnapshotService::forSourceLine()` stamps `price_match_basis` + `matched_receipt_line_id` (`:159-166`, `:232-235`); a running `plannedBySourceLine` accumulator makes two invoice lines against the same PO line consume different receipt slices (`:161-165`).
Finally `match_status = pendingReceipt ? Unmatched : $matcher->match($document)` (`:219-221`).

---

## 4. The 3-way match

### 4.1 `SupplierInvoiceMatchStatus`

`apps/api/app/Modules/Document/Domain/Enums/SupplierInvoiceMatchStatus.php:15-22` — five cases: `unmatched`, `matched`, `price_variance`, `quantity_variance`, `exception`. Deliberately lives in the Document module so Procurement→Document dependency direction holds (`:7-14`).

Severity order (`apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:57-63`): `exception(3) > quantity_variance(2) > price_variance(1) > matched/unmatched(0)`. Overall status = worst line status (`:83-136`).

### 4.2 Policy that drives it

`ProcurementPolicy` (`apps/api/app/Modules/Procurement/Domain/ProcurementPolicy.php`), one row per company in `procurement_policies` (`:54`). Fields: `bill_control_mode`, `match_mode`, `match_enforcement`, `variance_tolerance_percent`, `variance_tolerance_max_amount`, `allow_receipt_first`, `allow_invoice_first`, `invoice_first_requires_approval` (`:57-69`).

`defaultForVertical()` when no row exists (`:99-117`): `preset=Standard`, `bill_control_mode=Received`, `match_mode=ThreeWay`, `match_enforcement=Warn`, `variance_tolerance_percent='2.00'`, `variance_tolerance_max_amount='1.000'`, and **`allow_receipt_first=false`, `allow_invoice_first=false`, `invoice_first_requires_approval=true`** — deliberately fail-closed (`:109-114`).

`ProcurementPolicyResolver::forCompany()` (`apps/api/app/Modules/Procurement/Application/ProcurementPolicyResolver.php:24-52`) reads the persisted row else the vertical default, and **throws a `DomainException` if `bill_control_mode === Ordered`** ("Phase 1 procurement supports only receipt-first…", `:43-49`). `BillControlMode::Ordered` is a reserved Phase-2 value (`apps/api/app/Modules/Procurement/Domain/Enums/BillControlMode.php:19-25`).

`MatchMode` (`…/Enums/MatchMode.php:14-18`): `ThreeWay` = price basis is the receipt-line accrual; `TwoWay` = price basis is the PO line's contractual `unit_price` (`:10-12`).
`MatchEnforcement` (`…/Enums/MatchEnforcement.php:13-17`): `Warn` allows posting on price variance; `Block` refuses.

### 4.3 What is compared

**Quantity — HARD, at PO-line AGGREGATE grain.** `buildQtyGroupStatuses()` (`SupplierInvoiceMatcher.php:291-469`) sums all non-bonus invoice-line quantities per `source_line_id` at scale 4 (`:325-333`), then per group:
1. PO line must exist → else `Exception` (`:343-349`).
2. Parent document must be a `PurchaseOrder` of the SAME `company_id` → else `Exception` (`:353-362`).
3. `matchable <= 0` while `totalQty > 0` → `Exception` ("nothing received") (`:367-374`).
4. `totalQty > matchable` → `QuantityVariance` (`:377-382`).
5. else `Matched` (`:385-386`).

`matchableQty()` (`:149-173`): if posted receipt lines exist for the PO line, it is `Σ (received_qty − quantity_invoiced)` per receipt line via `ReceiptLineConsumptionPlanner::matchableQty()` (`:151-164`; planner at `apps/api/app/Modules/Procurement/Application/ReceiptLineConsumptionPlanner.php:78-84`); otherwise the legacy fallback `poLine.quantity_received − poLine.quantity_invoiced` (`:167-172`).

**Bonus lines** run a parallel free-quantity ledger (`:389-462`) against `free_qty − free_quantity_invoiced` (`ReceiptLineConsumptionPlanner.php:91-97`), with the same Exception/QuantityVariance verdicts. Bonus lines are skipped from the paid-qty group and from the price check entirely (`:113-115`, `:235-237`, `:300-317`).

**Price — ADVISORY, per line, dual threshold.** `computePriceStatus()` (`:494-536`):
```
priceDiff        = |invoice.unit_price − basis|            (scale 3)
extendedVariance = priceDiff × invoiced_qty               (scale 3)
basisExtended    = basis × invoiced_qty                   (scale 3)
percentThreshold = basisExtended × (variance_tolerance_percent × 0.01)   (scale 6 → 3)
matched  ⟺  extendedVariance ≤ percentThreshold  AND  extendedVariance ≤ variance_tolerance_max_amount
```
(`:505-533`). Both thresholds must pass (AND, not OR).

**Price basis** (`priceBasisForInvoiceLine`, `:541-576`): TwoWay → `poLine.unit_price` (`:546-548`); ThreeWay → the line's stored `price_match_basis` if present (`:550-552`), else a FIFO-weighted receipt-line accrual computed live (`:554-575`), else `poLine.unit_price` if no slices.

The price check only runs for groups whose qty verdict is `Matched` (`:127-129`).

### 4.4 What blocks posting

`assertPostable(Document, MatchEnforcement)` (`:188-262`), called by the posting service under lock:
- zero invoice lines → throw (`:190-195`);
- any line with `source_line_id === null` (unlinked) → throw, "The match_enforcement setting does not apply to quantity/exception violations." (`:207-214`);
- any PO-line group with `Exception` or `QuantityVariance` → throw regardless of enforcement (`:217-231`);
- any `PriceVariance` **and** `enforcement === Block` → throw (`:255-261`). Under `Warn` it passes and the controller decorates the response with `warning: 'Price variance detected; posted under warn enforcement.'` (`SupplierInvoiceController.php:402-404`).

### 4.5 The "sb-q11 supplier-invoice match guard" fix

`POST /supplier-invoices/{id}/match` refuses any non-Draft invoice with a **422 `MATCH_NOT_ALLOWED`**: "Only draft supplier invoices can be re-matched. The match status of a posted invoice is the authoritative post-time value the general ledger was booked against." (`SupplierInvoiceController.php:275-286`). Cross-referenced in the handoff docs: branch `fix/sb-q11-supplier-invoice-match-guard`, commit `0f775b6f7`, treasury gate r1 ACCEPT-with-conditions (`docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md:150`); the same lane also fixed `ExpenseService::generateExpenseNumber` under a tenant advisory lock and surfaced C-27 (journal-entry numbering, second company cannot post a JE) — see `docs/handoff/HANDOVER-session-B2-2026-08-25.md:17` and gate file `docs/superpowers/reviews/2026-08-25-sb-q11-supplier-invoice-gate-r1.md`.

`match()` otherwise recomputes and persists `match_status` and returns only the match block (`SupplierInvoiceController.php:288-295`, block built at `:680-732`: per-line `ordered / received / invoiced / matchable / price_variance`).

### 4.6 `SupplierInvoiceMatchSnapshotService`

`apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatchSnapshotService.php`. `forSourceLine($sourceLineId, $qty, $qtyAlreadyPlanned)` (`:33-67`) asks `ReceiptLineConsumptionPlanner::plan()` for FIFO slices; with no slices it falls back to `accrual_unit_cost ?? landed_unit_cost ?? unit_price` of the PO line at scale 6 (`:42-47`); with slices it returns the quantity-weighted basis at scale 6 plus the FIRST slice's `receipt_line_id` (`:49-66`).
`ReceiptLineConsumptionPlanner::plan()` (`…/ReceiptLineConsumptionPlanner.php:17-71`) walks posted receipt lines for the PO line ordered by `created_at, id` (FIFO), skipping `qtyAlreadyPlanned` first, and emits `{receipt_line_id, qty, basis=accrual_unit_cost}`. **Pure read, no writes.**

### 4.7 `SupplierInvoiceReceiptLinkingService`

`apps/api/app/Modules/Procurement/Application/SupplierInvoiceReceiptLinkingService.php`, one `DB::transaction` (`:39`). Input is `links: [{invoice_line_id, receipt_line_id}]` validated inline in the controller (`SupplierInvoiceController.php:304-309`).

Refusals (all `\DomainException` → controller returns 422 `LINK_RECEIPTS_FAILED`, `SupplierInvoiceController.php:320-324`):
- empty links (`:35-37`);
- invoice not `SupplierInvoice` type or not `Draft` — "Only draft supplier invoices can link receipt lines." (`:41-43`);
- `payload.supplier_invoice.pending_receipt !== true` → **`LINK_NOT_PENDING`** (`:48-50`);
- an invoice line id that isn't on this invoice (`:65-67`);
- a receipt line that is not posted / not this company (`postedReceipts()` + `company_id` + `lockForUpdate()`, `:70-80`);
- the receipt line's PO must share company + partner + currency with the invoice (`:94-101`);
- `invoiceLine.product_id`/`variant_id` must equal the PO line's → **`LINK_PRODUCT_MISMATCH`** (`:103-105`).

Effects: sets each invoice line's `source_line_id` to the **PO line** id resolved from the receipt line (`:107`, `:122-124`) — the caller supplies a receipt line "for user ergonomics" but the invoice stores PO-line grain (`:24-30`); re-stamps `price_match_basis`/`matched_receipt_line_id` with a running `plannedBySourceLine` (`:134-142`); merges new PO ids into `payload.supplier_invoice.source_document_ids` (`:111-116`, `:149-153`); sets `pending_receipt = hasUnlinkedInvoiceLine` so a **partial** link keeps the invoice pending (`:145-152`); back-fills `source_document_id` if null (`:155`); re-runs the matcher and saves (`:158-159`).

---

## 5. `SupplierInvoicePostingService` — what posting does

File: `apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php`. Whole thing in one `DB::transaction` (`:68`). Called from `SupplierInvoiceController::post()` with the actor id (`SupplierInvoiceController.php:358`).

### 5.1 Ordered steps

1. Resolve policy → `enforcement` (`:70-71`).
2. **Refuse `payload.supplier_invoice.pending_receipt === true` → `DomainException('PENDING_RECEIPT_UNLINKED')`** (`:77-79`).
3. Invoice-first approval gate (`:80`, impl `:675-700`): if `policy->requiresInvoiceFirstApproval()` AND any source PO carries `payload.auto_generated.source === 'invoice_first'` (`:702-739`), the actor must hold `supplier-invoices.approve-invoice-first` on guard `sanctum`, else `DomainException('INVOICE_FIRST_APPROVAL_REQUIRED')` (`:682-699`).
4. **Idempotency pre-probe** (unlocked): if a `journal_entries` row with `source_type='supplier_invoice'`, `source_id=invoice.id`, `company_id` exists → `return` (no-op) (`:115-117`, `hasClearingEntry` at `:445-452`).
5. **VAT period backdating guard** (`PeriodBackdatingGuardInterface::assertBackdatingPeriodIsOpen(company, document_date, document_number)`, `:119-123`) — deliberately AFTER the pre-probe so a retry on a closed month stays a no-op (`:102-114`). Throws `ReturnPeriodLockedException`, which the controller renders as a bespoke actionable 422 with `period` + `remedies` (`SupplierInvoiceController.php:359-390`, `:420-467`).
6. `lockForUpdate()` on the referenced PO lines (`:134-140`), the posted receipt lines for those PO lines (`:143-149`) and the parent PO documents (`:159-165`).
7. **Idempotency re-check under the lock** (`:172-174`).
8. `assertLineParentsShareInvoiceHeader()` — every locked PO line's parent must be a PurchaseOrder of the same company, partner AND currency (`:176`, `:623-646`).
9. `matcher->assertPostable($invoice, $enforcement)` (`:180`) — §4.4.
10. Capture `matchStatus = matcher->match()` **before** incrementing (`:184`).
11. Positive-net-qty guard: aggregate qty per PO line must be `> 0` (`:187-198`).
12. **Consume receipt lines FIFO** (`consumeReceiptLines`, `:458-532`): per receipt line, slice = min(matchable, remaining); `accrued += slice × accrual_unit_cost` at scale+4 (`:499`); refuse if the receipt line has no `accrual_unit_cost` (`:488-495`); refuse receipt-line over-clear (`:505-514`); increment `goods_receipt_lines.quantity_invoiced` (`:516-517`); refuse if `remaining > 0` after the walk (`:522-529`). Legacy fallback when no receipt lines exist: accrue at `poLine.accrual_unit_cost ?? landed_unit_cost ?? unit_price` (`:241-247`).
13. Refuse if new `quantity_invoiced > quantity_received` on the PO line, then persist it (`:250-262`). Same for the bonus/free ledger (`:265-305`, `consumeFreeReceiptLines` at `:537-591`).
14. Sum `recoverable_tax_amount` and `non_recoverable_tax_amount` across invoice lines (`:308-319`); read `billedHt = documents.subtotal` (`:322`) and `timbre = documents.stamp_duty_amount` (`:324`).
15. **Post the GL entry** (`:327-334`) — §5.2.
16. `documentStatus->transition($invoice, Posted, ['balance_due' => total, 'match_status' => $matchStatus])` (`:343-346`).
17. **VAT snapshot**: refuse on empty/unresolvable currency (`:406-413`); build the deductible snapshot from the **persisted line amounts** via `PostedLineTaxSnapshotBuilder::build()` (`:415`); refuse if `divergences()` is non-empty — "its deductible VAT is not declarable" (`:416-423`); write via `TaxCalculationService::snapshotTaxDetails()` (`:425`), which deletes-then-rewrites this document's `document_tax_details` rows (`:376-379`).

### 5.2 The journal entry — `GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry`

`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2167-2370`. Refuses to run outside a transaction (`:2175-2180`).

Legs (docblock `:2149-2153`, implementation `:2252-2348`):

| Leg | Purpose | Amount | line |
|---|---|---|---|
| **Dr** `GoodsReceivedNotInvoiced` | clear the 408 accrual at the accrued PO/receipt cost | `accruedHt` (omitted if 0) | `:2253-2263` |
| **Dr** `VatDeductible` | recoverable input VAT | `recoverableVat` (omitted if 0) | `:2266-2276` |
| **Dr** `PurchaseStampDuty` | timbre — never to VatDeductible | `timbre` (omitted if 0) | `:2279-2289` |
| **Dr/Cr** `PurchasePriceVarianceExpense` / `…Income` | `priceDelta = billedHt − accruedHt` | Dr if > 0, Cr if < 0 | `:2292-2313` |
| **Dr/Cr** `Inventory` | plug carrying capitalized non-recoverable VAT | `inventoryPlug = plug − priceDelta` | `:2316-2337` |
| **Cr** `SupplierPayable` | **partner-tagged** (`partner_id = invoice.partner_id`) | `total` (TTC) | `:2340-2348` |

Plug arithmetic: `plug = totalR − (accruedHtR + recoverableVatR + timbreR)`; `priceDelta = billedHtR − accruedHtR`; `inventoryPlug = plug − priceDelta` (`:2220-2226`). Each leg rounded once HALF-UP at the currency scale (`:2196-2207`).

**Fail-loud invariant:** `total` must equal `billedHt + recoverableVat + nonRecoverableVat + timbre` else `DomainException` "internally inconsistent" (`:2210-2216`); plus a defensive debit==credit assertion before posting (`:2352-2365`).

Entry header: `source_type = 'supplier_invoice'`, `source_id = invoice.id`, `entry_date = documents.document_date`, `journal_code = JournalCode::fromSourceType('supplier_invoice')`, created `Draft` then posted via `postSystemGeneratedEntryAndDispatchPostedEvent()` (`:2238-2248`, `:2367`).

### 5.3 TN account codes come from a SEEDED chart, resolved by purpose

The GL never hardcodes a code — it calls `Account::findByPurposeOrFail($companyId, SystemAccountPurpose::X)` (`GeneralLedgerService.php:2228-2234`; helper at `apps/api/app/Modules/Accounting/Domain/Account.php:252-266`, whose failure message tells the operator to assign the purpose in Settings → Chart of Accounts).

TN mapping (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php`):

| Purpose | TN code | line |
|---|---|---|
| `Inventory` | **37** — Stocks de marchandises | `:165-166` |
| `SupplierPayable` | **401** — Fournisseurs | `:172-173` |
| `GoodsReceivedNotInvoiced` | **408** — Fournisseurs, factures non parvenues | `:178-179` |
| `SupplierAdvance` | 409 | `:180-181` |
| `VatDeductible` | **4456** — TVA déductible | `:200-201` |
| `PurchaseStampDuty` | **6354** — Droits d'enregistrement et de timbre | `:273-274` |
| `PurchasePriceVarianceExpense` | **6585** — Écart sur prix d'achat | `:287-288` |
| `PurchasePriceVarianceIncome` | **7585** — Écart sur prix d'achat | `:336-337` |

⚠️ That seeder class is annotated **`@deprecated compatibility artifact; frozen at 7d85232cc`** (`:22`). The live path is `ChartOfAccountsService::seedForCompany()` (`apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:49-90`): when `config('country_defaults.provisioning_enabled')` is **true** it uses `CountryTemplateResolver` + `TemplateChartOfAccountsSeeder` (`:51-71`); otherwise it falls back to the legacy per-country seeder (`TN → TunisiaChartOfAccountsSeeder`, `:73-77`, `:194`). **Check the flag on the test tenant before asserting codes.** `validateCompanyAccounts()` (`:97-113`) lists missing required purposes. `ProvisioningRequiredPurposesV1` declares SupplierPayable / VatDeductible / PurchasePriceVarianceExpense / PurchasePriceVarianceIncome as REQUIRED for this GL method (`apps/api/app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php:38,40,47,48`).

### 5.4 Fiscal / hash chain

The supplier invoice document itself is `fiscal_category = NonFiscal`, `fiscal_status = Draft` (`CreateSupplierInvoiceService.php:126-127`) — **no document-level fiscal hash chain**. The GL entry, however, goes through the hash-chained system posting path (`GeneralLedgerService.php:2367`); the existing test suite asserts `GeneralLedgerHashService::verifyChain()` after posting (`apps/api/tests/Feature/Accounting/SupplierInvoiceGlTest.php:382-439`).

### 5.5 Idempotency — YES, three layers

1. Unlocked pre-probe (`SupplierInvoicePostingService.php:115-117`).
2. Under-lock re-check (`:172-174`).
3. DB partial unique index `uniq_je_source_procurement ON journal_entries (source_type, source_id) WHERE source_type IN ('supplier_invoice','supplier_credit_note')` (`apps/api/database/migrations/tenant/2026_06_26_120000_unique_journal_entries_source_procurement.php:44-48`).

The controller deliberately performs **no status pre-check** so a retry reaches the no-op (`SupplierInvoiceController.php:347-351`). A second POST therefore returns **200 with the same detail payload**, not an error.

---

## 6. Invoice-first flow

Two distinct modes, both requiring `policy.allow_invoice_first = true` (`CreateSupplierInvoiceRequest.php:222-229` and again defensively in `CreateSupplierInvoiceService.php:65-67`).

### 6.1 `pending_receipt: true` — invoice with NO goods yet

- `source_document_ids` and `source_line_id` may be omitted (`CreateSupplierInvoiceRequest.php:73-74`).
- Persisted with `payload.supplier_invoice.pending_receipt = true`, `source_document_id = null`, `match_status = Unmatched` (matcher deliberately skipped, `CreateSupplierInvoiceService.php:142-143`, `:219`).
- **Cannot be posted**: `PENDING_RECEIPT_UNLINKED` (`SupplierInvoicePostingService.php:77-79`). **No GL is written at creation** — the only GL writer is `post()`.
- Later linked via `POST /supplier-invoices/{id}/link-receipts` (§4.7), which resolves PO lines from posted receipt lines and flips `pending_receipt` to false once every line is linked (`SupplierInvoiceReceiptLinkingService.php:145-152`).

### 6.2 `invoice_first_delivered: true` — invoice + goods arriving together

Routed to `InvoiceFirstOrchestrator::createDelivered()` (`SupplierInvoiceController.php:223-229`; impl `apps/api/app/Modules/Procurement/Application/InvoiceFirstOrchestrator.php:22-87`):
1. `StandaloneReceiptService::execute()` with `source: 'invoice_first'`, `postImmediately: true`, the caller's `idempotency_key`, `location_id` (`:27-38`) — this mints an **auto-generated PO** and a **posted GoodsReceipt**.
2. Idempotency replay: if `procurement_idempotency_keys` already carries a `supplier_invoice_id` for this key/company, return that invoice (`:40-51`).
3. Each request line is mapped **positionally** to the auto-PO's lines (`:59-69`) — "Invoice-first line mapping drifted from auto-PO line order." if counts mismatch (`:62`).
4. `CreateSupplierInvoiceService::create()` with `source_document_id(s) = [autoPO.id]` (`:71-76`), then the idempotency row is stamped (`:78-84`).

Posting such an invoice trips the **approval gate**: with the default `invoice_first_requires_approval = true`, the actor needs `supplier-invoices.approve-invoice-first` or the post throws `INVOICE_FIRST_APPROVAL_REQUIRED` (`SupplierInvoicePostingService.php:675-700`, PO detection via `payload.auto_generated.source === 'invoice_first'` at `:702-739`).

**Bonus lines are forbidden on both invoice-first modes** (`CreateSupplierInvoiceRequest.php:195-201`).

---

## 7. Supplier payment

**There is no `SupplierPayment*` controller.** Supplier payments go through the shared Treasury endpoint.

### 7.1 Route

`POST /api/v1/payments` → `PaymentController::store` with `can:payments.create` (`apps/api/app/Modules/Treasury/Presentation/routes.php:189-191`). Siblings: `GET /payments` + `/payments/{payment}` (`can:payments.view`, `:181-187`), `POST /payments/{payment}/refund` + `/partial-refund` (`can:payments.refund`, `:194-200`), `POST /payments/{payment}/reverse` (`can:payments.reverse`, `:202-204`).

**Supplier invoices are explicitly refused on the multi-line path**: `SUPPLIER_INVOICE_NOT_PAYABLE_VIA_MULTILINE` (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:1401-1414`).

### 7.2 Request rules (`PaymentController.php:368-419`)

| field | rules | line |
|---|---|---|
| `partner_id` | `required, uuid, ScopedExists::tenantAndCompany('partners')` | `:369-373` |
| `payment_method_id` | `required, uuid, ScopedExists…('payment_methods')` | `:374-378` |
| `instrument_id` | `nullable, uuid, ScopedExists…('payment_instruments')` | `:379-383` |
| `instrument.*` | `reference` required-with, `maturity_date`, `drawer_name`, `bank_id`/`bank_name`/`bank_branch`/`bank_account` | `:384-394` |
| `repository_id` | `nullable, uuid, ScopedExists…('payment_repositories')` | `:395-399` |
| `amount` | `required, numeric, min:0.01, regex:/^\d+(\.\d{1,3})?$/` | `:400` |
| `currency` | `nullable, string, size:3` | `:401` |
| `payment_date` | `required, date` | `:402` |
| `reference` / `notes` | `nullable, string` | `:403-404` |
| `allocations` | `nullable, array` | `:405` |
| `allocations.*.document_id` | `required_with, uuid, ScopedExists…('documents')` | `:406-410` |
| `allocations.*.amount` | `required_with, numeric, min:0.01, regex:/^\d+(\.\d{1,3})?$/` | `:411` |
| `withholding_enabled` / `withholding_rate` / `withholding_override_reason` | `nullable` boolean / numeric 0..1 4dp / string | `:412-414` |

Idempotency: an `Idempotency-Key` header short-circuits before validation and returns the original payment with **200** (`:358-366`); a losing race is recovered from the partial unique index (`:1372-1386`).

### 7.3 Guards specific to a supplier allocation

Executed per allocation (`:466-629`). `$isSupplierPayment` is set the moment an allocated document has `type === DocumentType::SupplierInvoice` (`:525-526`).

| Guard | Error code | line |
|---|---|---|
| allocated doc's partner ≠ payment partner | `ALLOCATION_PARTNER_MISMATCH` 422 | `:476-488` |
| document side vs partner role | via `DocumentAllocationStateGuard::assertDirectionMatchesPartner` | `:499` |
| withdrawn/unallocatable document | via `assertAllocatable` | `:506` |
| invoice not `Posted` | `SUPPLIER_INVOICE_NOT_POSTED` 422 | `:532-543` |
| no **posted** JE with a Cr-401 line **tagged to this partner** | `SUPPLIER_INVOICE_NOT_POSTED` 422 | `:548-583` |
| allocation > invoice outstanding | `SUPPLIER_PAYMENT_EXCEEDS_PAYABLE` 422 | `:589-601` |
| mixing supplier + non-supplier allocations | `MIXED_ALLOCATION_TYPES` 422 | `:634-641` |
| `repository_id` missing or its `gl_account_id` is null | `SUPPLIER_PAYMENT_REQUIRES_LEDGERED_REPOSITORY` 422 | `:754-772` |
| payment amount > total allocated (no supplier advance path) | `SUPPLIER_PAYMENT_EXCEEDS_PAYABLE` 422 | `:803-814` |
| re-checked on the **locked** document row inside the transaction | `SUPPLIER_PAYMENT_EXCEEDS_PAYABLE` 422 | `:1049-1064` |
| deferred (cheque/effet) supplier tender without instrument details | `INSTRUMENT_REQUIRED` / `MATURITY_REQUIRED` / `INVALID_OUTBOUND_REPOSITORY` 422 | `:715-749` |

Outstanding balance for the pre-check is `Document::outstandingBalance($scale)` — **computed from allocations, not the `balance_due` cache** (`:511-523`; impl `apps/api/app/Modules/Document/Domain/Document.php:852-882`, which prefers `balance_due` when non-null and otherwise recomputes `total − Σ payment_allocations − Σ credit_note_allocations`).

### 7.4 What a supplier payment writes

Inside one `DB::transaction` (`:826`):
1. `payments` row: `payment_type = PaymentType::SupplierPayment` (supplier arm evaluated FIRST, `:933-937`), `status = Completed`, `origin = WebAdmin`, `location_id` inherited from the first allocated document (`:645-654`), `idempotency_key` (`:942-962`).
2. One `payment_allocations` row per allocation (`:1078-1083`), `booked_as_advance` from the classifier (`:1075-1076`).
3. `documents.balance_due = currentBalance − allocationAmount` written explicitly on the locked row (`:1090-1092`). Note the PG trigger also recomputes it (see §8).
4. `documentStatus->markPaid($document)` when the new balance hits 0 and the type `canTransitionToPaid()` — **`SupplierInvoice` returns true** (`:1098-1104`; `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:123-129`). Fires `DocumentFullyPaid` (`:1107-1128`).
5. **GL**: `GeneralLedgerService::createSupplierPaymentJournalEntry(...)` in `PostingMode::SynchronousInTransaction` (`:1184-1195`). That method (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:994-1062`) writes exactly two legs — **Dr `SupplierPayable` partner-tagged** (`:1031-1039`) and **Cr the repository's `gl_account_id`** (`:1042-1050`) — with `source_type = 'supplier_payment'`, `source_id = payment.id` (`:1025-1027`), then posts it (`:1055-1056`). The payment row is linked back via `payments.journal_entry_id` (`:1219-1225`).
   Deferred (cheque/effet) supplier tenders instead go through `OutboundInstrumentIssuer::issueExisting()` (`:1170-1177`), which books Dr 401 / Cr the payable-instrument account (`GeneralLedgerService.php:1064-1088`).
6. **Cash**: `MovementService::record(MovementIntent(direction: Out, amount: paymentAmount, sourceType: Payment, sourceId: payment.id, journalEntryId: …))` (`:1347-1367`) — the single writer of `payment_repositories.balance` + the append-only movement row. Skipped for deferred instruments (`:1337`). Fails loudly if no JE backs the movement (`:1341-1345`).
7. `partners.payable_balance` is refreshed **asynchronously** by `RefreshPartnerBalanceOnJournalEntryPosted` reacting to the posted JE (comment `:1180-1183`; listener `apps/api/app/Modules/Accounting/Listeners/RefreshPartnerBalanceOnJournalEntryPosted.php`).

**Partial payments:** allowed — a partial allocation simply leaves `balance_due > 0` and the document stays `Posted`.
**Overpayment:** refused, not routed to an advance — "Supplier payments never create an advance" (`:800-802`), guarded at `:589-601`, `:803-814` and re-checked under lock at `:1049-1064`. The excess-as-customer-advance branch is explicitly `! $isSupplierPayment` (`:1274`).
**Payment methods:** any `payment_methods` row of the company; if `has_maturity` AND `instrument_kind ∈ {Cheque, Effet}` the deferred outbound branch applies (`:427-430`, `:715-749`). Immediate methods require a repository with `gl_account_id`.

---

## 8. Where the payable truth lives + cross-check SQL

Four independent stores, all in the `tenant_<uuid>` DB:

**(a) `documents.balance_due` / `documents.status`.**
Set to `total` at post time (`SupplierInvoicePostingService.php:343-346`). Decremented explicitly by `PaymentController` (`:1090-1092`). ALSO maintained by a PG trigger `payment_allocation_balance_update` on `payment_allocations` (AFTER INSERT/UPDATE/DELETE) running `update_document_balance_due()`:
`balance_due = COALESCE(total,0) − Σ payment_allocations.amount − Σ credit_note_allocations.amount` (`apps/api/database/migrations/tenant/2026_01_08_214145_add_balance_due_cache_trigger.php:27-61`). **PG only — the trigger does not exist on sqlite** (`:20-24`).
`documents.status` reaches `Paid` only from `Posted` for a supplier invoice (`DocumentStatusMachine.php:104-112`; `DocumentStatusService::markPaid` at `apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:434-437`; commentary that a supplier invoice's `Paid` is only reachable from `Posted` at `:451-454`).

**(b) GL journal lines.** `journal_entries` (header) + `journal_lines` (`apps/api/app/Modules/Accounting/Domain/JournalLine.php:36`). Relevant `source_type` values: `'supplier_invoice'` (Cr 401) and `'supplier_payment'` (Dr 401); the 401 legs are the only ones carrying `journal_lines.partner_id`.

**(c) `partners.payable_balance`.** `decimal:3` (`apps/api/app/Modules/Partner/Domain/Partner.php:163`), a **non-negative MAGNITUDE** cache (`credit − debit`, clamped at 0) recomputed by `PartnerBalanceService::refreshPartnerBalance()` from the `SupplierPayable` purpose account (`apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:333-357`, magnitude rationale `:340-350`). A DB CHECK enforces non-negativity (`apps/api/database/migrations/tenant/2026_06_22_140000_add_partner_non_negative_balance_constraints.php`). There is **no `partner_balances` table** — it is a denormalised column on `partners`.

**(d) Treasury repository balance.** `payment_repositories.balance`, written only through `MovementService::record()` (`PaymentController.php:1347-1367`), with an append-only movement row (`repository_movements`, source `payment`).

### Cross-check SQL (run against the tenant DB, one company)

```sql
-- 0. parameters
\set company '00000000-0000-0000-0000-000000000000'
\set partner '00000000-0000-0000-0000-000000000000'

-- 1. AP per invoice: document cache vs allocations
SELECT d.document_number, d.status, d.total, d.balance_due,
       COALESCE(SUM(pa.amount),0)                      AS allocated,
       d.total - COALESCE(SUM(pa.amount),0)            AS computed_balance,
       d.balance_due - (d.total - COALESCE(SUM(pa.amount),0)) AS cache_drift  -- must be 0
FROM documents d
LEFT JOIN payment_allocations pa ON pa.document_id = d.id
WHERE d.type = 'supplier_invoice' AND d.company_id = :'company'
GROUP BY d.id
ORDER BY d.document_number;

-- 2. GL 401 subledger for one supplier (credit-normal magnitude)
SELECT SUM(jl.credit) - SUM(jl.debit) AS gl_401_payable
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id
JOIN accounts a        ON a.id  = jl.account_id
WHERE je.company_id = :'company'
  AND je.status     = 'posted'
  AND a.system_purpose = 'supplier_payable'
  AND jl.partner_id = :'partner';

-- 3. Partner cache
SELECT payable_balance, balance_updated_at FROM partners
WHERE id = :'partner' AND company_id = :'company';

-- 4. Per-invoice GL sanity: exactly ONE clearing entry, Cr 401 == documents.total
SELECT d.document_number, d.total,
       COUNT(DISTINCT je.id)                          AS clearing_entries, -- must be 1
       SUM(CASE WHEN a.system_purpose='supplier_payable' THEN jl.credit ELSE 0 END) AS cr_401,
       SUM(CASE WHEN a.system_purpose='goods_received_not_invoiced' THEN jl.debit ELSE 0 END) AS dr_408,
       SUM(CASE WHEN a.system_purpose='vat_deductible' THEN jl.debit ELSE 0 END)    AS dr_4456,
       SUM(jl.debit) - SUM(jl.credit)                 AS entry_imbalance   -- must be 0
FROM documents d
JOIN journal_entries je ON je.source_type='supplier_invoice' AND je.source_id=d.id AND je.company_id=d.company_id
JOIN journal_lines  jl  ON jl.journal_entry_id = je.id
JOIN accounts       a   ON a.id = jl.account_id
WHERE d.type='supplier_invoice' AND d.company_id = :'company' AND d.status IN ('posted','paid')
GROUP BY d.id;

-- 5. Treasury: cash out vs the supplier-payment JEs
SELECT r.name, r.balance,
       COALESCE(SUM(CASE WHEN m.direction='out' THEN m.amount ELSE -m.amount END),0) AS net_movements
FROM payment_repositories r
LEFT JOIN repository_movements m ON m.repository_id = r.id
WHERE r.company_id = :'company'
GROUP BY r.id;

-- 6. The three-way assertion for one supplier
--    Σ documents.balance_due (posted/paid SI)  ==  gl_401_payable  ==  partners.payable_balance
```
Column names verified: `documents.balance_due`/`status`/`total`/`type`/`company_id`; `payment_allocations.{payment_id,document_id,amount}` (`apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:171-180`, `amount` originally `decimal(15,2)`, later widened — `PaymentRefundService::ALLOCATION_STORAGE_SCALE` treats it as scale 3, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:1248-1264`); `journal_lines.{journal_entry_id,account_id,partner_id,debit,credit,line_order}`; `journal_entries.{source_type,source_id,company_id,status,entry_date,journal_code}`; `partners.payable_balance`. **UNVERIFIED:** the exact table/column names for `repository_movements` (query 5) — I did not open that migration; adjust if it differs.

---

## 9. Cancellation / reversal

- **Cancelling a posted (or draft) supplier invoice: NOT POSSIBLE via the API.** No cancel/update/delete route exists (`apps/api/app/Modules/Procurement/Presentation/routes.php:76-121`, confirmed by the controller's own comment at `SupplierInvoiceController.php:373-374`). `/invoices/{invoice}/cancel` and `/credit-notes/{creditNote}/cancel` (`apps/api/app/Modules/Document/Presentation/routes.php:224-226`, `:269-271`) are the **sales** invoice/credit-note routes, not supplier ones. A stranded pre-post draft is a known open gap ("LEDGER `D-B19-4`", `SupplierInvoiceController.php:388-389`); the only remedy the API offers is "re-create this supplier invoice with an issue date inside an open VAT period. A supplier invoice has no edit path once created." (`:440-444`).
- **The economic reversal is a supplier credit note.** `SupplierCreditNotePostingService` exists (`apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php`, 1095 lines) and its GL mirror is `GeneralLedgerService::createSupplierCreditNoteEntry` — `Dr 401 (gross) / Cr VatDeductible / Cr-or-Dr Inventory plug`, timbre not reversed in Phase 1 (`GeneralLedgerService.php:2373-2397`). **But I found NO HTTP route and NO controller invoking it** — the only Procurement controllers are `ProcurementPolicyController`, `PurchaseQuoteRequestController`, `StandaloneReceiptController`, `SupplierInvoiceController`, and grepping the app for `SupplierCreditNotePostingService` returns only docblocks/console references. **Treat supplier credit notes as NOT reachable from the UI/API on this branch** (probe with a route dump before scripting it).
- **Reversing a supplier payment: REFUSED.** `PaymentType::SupplierPayment => ReversalSupport::Unsupported` (`apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:231`); `PaymentRefundService::reversePayment()` throws a `DomainException` on `Unsupported` (`apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:1220-1223`), surfaced as a 422 by the controller (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRefundController.php:177-181`). `POST /payments/{id}/refund` is technically reachable (`canRefund()` only checks `status === Completed` and the instrument status, `PaymentRefundService.php:1072-1085`) — **UNVERIFIED** whether the refund path produces a coherent AP reversal; it is built for the customer side.
- Posting itself is not reversible: a repost is a silent no-op (§5.5), and re-matching a posted invoice is refused (§4.5).

---

## 10. Second-company isolation

- **Numbering is company-scoped.** `DocumentSequence` is looked up by `company_id + type + year` (`apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:47-50`), and migration `2026_08_30_100500_enforce_company_scoped_document_numbers.php` swaps the old `UNIQUE (tenant_id, type, document_number)` for `UNIQUE (company_id, type, document_number)` after a collision census (`:20`, `:50-60`). So company A and company B in the same tenant may both hold `SI-2026-0001`.
- **Listing is company-scoped**: `baseQuery() = Document::forCompany(CompanyContext::requireCompanyId())` (`HandlesDocuments.php:44-48`), used by `index`, `show`, `duplicateReference`, `match`, `linkReceipts`, `post` (`SupplierInvoiceController.php:96, 157, 188, 266, 311, 338`).
- **Cross-company writes are refused at four independent points**: the matcher rejects a PO line whose parent belongs to another company (`SupplierInvoiceMatcher.php:355-362`); the posting service re-asserts company+partner+currency on the locked parents (`SupplierInvoicePostingService.php:623-646`); the receipt-linking service checks `purchaseOrder->company_id !== supplierInvoice->company_id` (`SupplierInvoiceReceiptLinkingService.php:94-101`) and only loads receipt lines `where('company_id', …)` (`:70-77`); the `formatDetail` helpers scope PO lookups and consumed receipts by tenant+company (`SupplierInvoiceController.php:596-600`, `:637-655`, `:693-698`).
- **Validation** uses `ScopedExists::tenantAndCompany(...)` for partner/documents/products (`CreateSupplierInvoiceRequest.php:82, 87, 94, 118`) and `ScopedExists::company('locations', …)` (`:99`).
- Payments: partner/method/instrument/repository/document all `ScopedExists::tenantAndCompany` (`PaymentController.php:369-410`); GL accounts resolved per `company_id` (`GeneralLedgerService.php:2228-2234`).
- **Known cross-company hazard (out of lane but relevant):** journal-entry numbering was tenant-scoped and 23505'd for the second company of a tenant — C-27, fixed under the same sb-q11 lane (`docs/handoff/HANDOVER-session-B2-2026-08-25.md:17`). Worth a second-company smoke on a JE-minting post.

---

## 11. Web frontend (`apps/web/src`)

### 11.1 Supplier invoices

Routes (`apps/web/src/routes/index.tsx:1019-1049`, lazy imports `:68-70`):

| Path | Component | Gate |
|---|---|---|
| `/purchases/supplier-invoices` | `SupplierInvoiceListPage` | `RequirePermission moduleKey="purchases"` (`:1023`) |
| `/purchases/supplier-invoices/new` | `SupplierInvoiceCreatePage` | `RequirePermission permission="purchases.create"` (`:1033`) |
| `/purchases/supplier-invoices/:id` | `SupplierInvoiceDetailPage` | `RequirePermission moduleKey="purchases"` (`:1043`) |

Files: `apps/web/src/features/purchases/supplier-invoices/{SupplierInvoiceListPage,SupplierInvoiceCreatePage,SupplierInvoiceDetailPage,api,types}.tsx|ts`.

Endpoints in `apps/web/src/features/purchases/supplier-invoices/api.ts`: list `:147`, detail `:168`, create `:182`, match `:365`, post `:383`, link-receipts `:400`, duplicate-reference `:346`, PO detail `:199,231`, open POs `GET /purchase-orders?partner_id&status=received&has_uninvoiced=1` `:309-316`, receipt lines `GET /purchase-orders/{id}/receipt-lines?uninvoiced=1` `:269-272, 287-290`, attachments `:419,436,457,469`, and `POST /payments` `:487`.
(The receipt-lines backend is `PurchaseOrderController::receiptLines`, `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:910-945`, route `apps/api/app/Modules/Document/Presentation/routes.php:282-285`; `uninvoiced=1` adds `whereColumn('received_qty','>','quantity_invoiced')` at `:937-940`.)

FE permission checks: `supplier-invoices.link-receipts` (`SupplierInvoiceDetailPage.tsx:88,93,514`), `payments.create` (`:89,380`), `supplier-invoices.create-pending` (`SupplierInvoiceCreatePage.tsx:211,289,831`), `+ goods-receipt.create-standalone` for delivered mode (`:212,290,847`), `document-ingestions.view` for the "Scan Invoice" link (`SupplierInvoiceListPage.tsx:187`, `SupplierInvoiceCreatePage.tsx:694`). Permission map rows at `apps/web/src/hooks/permissionsMap.generated.ts:253-255`.

**`data-testid` inventory (verbatim):**

*List* (`SupplierInvoiceListPage.tsx`): `match-badge-${invoiceId}` `:63` · `pending-receipt-badge-${invoice.id}` `:104` · `filter-status` `:226` · `filter-match-status` `:249` · `filter-date-from` `:281` · `filter-pending-receipt` `:295` · `filter-date-to` `:316`.

*Detail* (`SupplierInvoiceDetailPage.tsx`): `match-row-${row.po_line_id}` `:173` · `btn-post` `:355` · `btn-rematch` `:371` · `btn-record-payment` `:390` · `post-block-reason` `:403` · `pending-receipt-banner` `:413` · `link-source-po` `:473` · `link-receipts` `:527` · `link-receipt-line-selector-${line.id}` `:545`.

*Create* (`SupplierInvoiceCreatePage.tsx`): `manual-line-product-picker-${i}` `:504` · `manual-line-batch-number-${i}` `:509` · `manual-line-batch-expiry-${i}` `:515` · `manual-line-batch-manufacturing-${i}` `:521` · `manual-line-quantity-${i}` `:538` · `manual-line-unit-price-${i}` `:552` · `manual-line-vat-rate-${i}` `:566` · `remove-manual-line-${i}` `:580` · `invoice-line-quantity-${i}` `:626` · `invoice-line-unit-price-${i}` `:641` · `source-purchase-order` `:736` · `supplier-reference` `:795` · `invoice-first-pending` `:834` · `invoice-first-delivered` `:850` · `invoice-first-location-id` `:883` · `invoice-first-external-reference` `:899` · `invoice-first-external-date` `:909` · `add-manual-line` `:944` · `supplier-invoice-attachments` `:985`.

**Flow.** Create page has three entry modes `'receipts' | 'invoiceFirstDelivered' | 'invoiceFirstPending'` (`SupplierInvoiceCreatePage.tsx:75`); the invoice-first buttons are gated on `policyQuery.data?.allow_invoice_first === true && canCreatePendingInvoice` (`:289`) and, for delivered, additionally `goods-receipt.create-standalone` (`:290`); both are disabled when the page was deep-linked with `?po=` (`:835,851`). PO multi-select at `:734-762`; receipt-line prefill + client-side match preview at `:243-282` and `:127-135`; payload assembly at `:391-460`. On the detail page: `btn-rematch` → `handleRematch()` `:223-238` (rendered only when not posted); the link-receipts panel renders only when `hasPendingReceipt && !isPosted && canLinkReceipts` `:514`, with per-line selectors filtered by product/variant `:555-559`; `btn-post` is disabled when `postDisabled = isQtyBlocked || hasPendingReceipt` `:127`.

**FE error handling caveat (matters for scenario assertions):** no frontend code pattern-matches `POSTING_BLOCKED` or `LINK_RECEIPTS_FAILED`; every mutation handler toasts `err.response.data.error.message` verbatim. And `SupplierInvoiceDetail.warning` (the price-variance warning the API returns from `/post`, `types.ts:136-137`) is **never rendered** in `SupplierInvoiceDetailPage.tsx`. Price variance is only visible through the match badge / match table. Assert on toast text, not on codes.

### 11.2 Supplier payment UI

Three entry points:
1. **Treasury payments**: `/treasury/payments` → `PaymentListPage` (`routes/index.tsx:1832`, gate `moduleKey="treasury"`); `/treasury/payments/new` → `PaymentForm` (`:1842`, gate `permission="treasury.create"`); `/treasury/payments/:id` → `PaymentDetailPage` (`:1852`). `PaymentForm.tsx` accepts `?supplier_invoice=<id>` and prefills from `GET /supplier-invoices/{id}` (`:279, 376-438`), submits `POST /payments` (`:679`) with one allocation of `balance_due ?? total` (`:596-602`), then navigates back to the invoice (`:717-719, 740-741`). **Only two testids exist there** — `payment-fee-line` `:1081`, `payment-net-line` `:1089`; everything else uses plain `id` attributes (`amount`, `payment_method_id`, `repository_id`, `partner_id`, `payment_date`, `reference`, …, `:794-1116`). `PaymentListPage.tsx` has **no** testids.
2. **Inline modal on the invoice detail page**: `btn-record-payment` `:390` opens a native `<dialog>` prefilled with `balance_due ?? total` (`:99-108`), submits `POST /payments` (`:283-321`); modal fields use `id`s only: `supplier-payment-amount`, `supplier-payment-method`, `supplier-payment-repository`, `supplier-payment-date`, `supplier-payment-reference`, `supplier-payment-notes` (`:701-793`); errors render inline in a `role="alert"` div (`:796-800`), NOT a toast.
3. **"Pay in Treasury" link** → `/treasury/payments/new?supplier_invoice=${invoice.id}` (`SupplierInvoiceDetailPage.tsx:382-387`).

### 11.3 Balance / aging screens

- Supplier detail balance card: `apps/web/src/features/partners/PartnerDetailPage.tsx:461-547`, shows `partner.payable_balance` under `fields.totalPayable` when supplier context (`:498-506`), plus `GET /partners/{id}/account-balance/TND` (`:237-241`). Route `/purchases/suppliers/:id` (`routes/index.tsx:862-870`, gate `moduleKey="purchases" permission="partners.view"`). **No testids.**
- AP aging: `/finance/aged-payables` → `AgedPayablesPage` (`routes/index.tsx:2105-2114`, gate `permission="reports.operational"`), hook `useAgedPayables` → `GET /reports/aged-payables` (`apps/web/src/features/finance/api.ts:196-208`). **No testids.**

### 11.4 Sidebar

`apps/web/src/components/organisms/Sidebar/Sidebar.tsx`: Purchases group `:174-176` (`permission: 'purchases'`) with `supplierInvoices → /purchases/supplier-invoices` `:184` and `suppliers` `:178`; Banking & Payments group `:270-273` (`module: 'Treasury'`) with `payments → /treasury/payments` `:275`; Accounting & Reports group `:287-290` with `agedPayables → /finance/aged-payables` `:307` (`permission: 'reports.operational'`).

### 11.5 OCR / DocumentIngestion (note only)

`apps/api/app/Modules/DocumentIngestion/Application/Committers/SupplierInvoiceCommitter.php`, registered in `IngestionCommitterRegistry` (`apps/api/app/Modules/DocumentIngestion/Application/Services/IngestionCommitterRegistry.php:8,21`). Reachable over HTTP: `POST /document-ingestions` (`can:document-ingestions.create`), `/extract` (throttled 6/min), `/reject`, `/commit` (`can:document-ingestions.commit`) — `apps/api/app/Modules/DocumentIngestion/Presentation/routes.php:16-42`. The FE links into it from the supplier-invoice list and create pages behind `document-ingestions.view` ("Scan Invoice", `SupplierInvoiceListPage.tsx:187`, `SupplierInvoiceCreatePage.tsx:694`). Covered by `apps/api/tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php`. **UNVERIFIED whether the extract step works on dev without an external OCR provider** — do not put it on the critical path of the wave-2 matrix.

---

## 12. Existing tests (fixtures worth reusing)

| File | Purpose | Key fixtures |
|---|---|---|
| `apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php` | HTTP boundary: create / match / post / list / detail / link-receipts / invoice-first / pending-receipt / permissions (~2674 lines) | `PermissionRegistrar::setPermissionsTeamId` `:110` + `RolesAndPermissionsSeeder` `:111`; tenant/company/user/supplier/membership `:92-137`; `ProcurementPolicy::create` (Received/ThreeWay/Warn, 2%/1.000) `:140-148`; `createPoWithReceipt()` hand-builds a Confirmed PO with `quantity_received` pre-set `:162-197`; `createPostedStandaloneReceipt()` uses the REAL `StandaloneReceiptService::execute()` `:277-302`; `ChartOfAccountsService::seedForCompany` per GL test (`:663,725,782,815,866,999,1059,1105,1199,…`) |
| ↳ `test_store_pending_receipt_…_post_is_blocked` `:921-973` | asserts `PENDING_RECEIPT_UNLINKED` 422 | |
| ↳ `test_store_pending_receipt_…_rejects_when_invoice_first_policy_is_disabled` `:975-995` | policy gate | |
| ↳ `test_store_invoice_first_delivered_…` `:661-721` | auto-PO `payload.auto_generated.source='invoice_first'`, receipt Posted, snapshot columns stamped | |
| ↳ `test_post_invoice_first_delivered_posts_zero_ppv_gl_legs` `:864-919` | Dr 408 = 20.000, Cr 401 = 20.000 partner-tagged, no PPV | |
| ↳ link-receipts: `:997-1055` (happy), `:1057-1101` (`LINK_PRODUCT_MISMATCH`), `:1103-1134` (`LINK_NOT_PENDING`), `:1136-1195` (partial link keeps pending), `:1197-1272` (link→post clears 408) | | |
| `…/Procurement/SupplierInvoiceCreationReadApiTest.php` | `GET /purchase-orders/{id}/receipt-lines?uninvoiced=1`, `has_uninvoiced=1`, `duplicate-reference` | Parapharmacy vertical, TN/TND `:54-70`; `RolesAndPermissionsSeeder` `:73` |
| `…/Procurement/SupplierInvoiceMatcherTest.php` | 12 matcher scenarios: qty over-clear under warn AND block `:255-291`; unreceived→exception `:297-332`; price within/beyond tolerance `:338-430`; null `source_line_id` `:436-458`; multi-line aggregate over-clear `:471-527`; wrong doc type / wrong company / missing PO line `:533-667`; zero lines `:673-697`; AND-semantics of the dual threshold `:727-747`; bonus skip `:215-249` | tenant/company TN/TND + supplier `:69-92`; `ProcurementPolicy::create` `:96-104`; **no chart seeding** |
| `…/Procurement/SupplierInvoiceMatcherReceiptBasisTest.php` | receipt-line basis, FIFO slices, paid vs free windows, two-way mode, draft receipts excluded | `ProcurementPolicy` `:76-84` |
| `…/Accounting/SupplierInvoiceGlTest.php` | canonical C3 GL suite (14 tests) | accounts by purpose `:102-108`; TN company + `ChartOfAccountsService::seedForCompany` `:84-100`; supplier `PartnerTaxStatus::REGISTERED` `:110-116`; policy `:119-127`; 408 pre-accrued via `createGoodsReceiptGrIrEntry` `:186-195`; `net408()` helper `:291-306` |
| ↳ `test_matched_invoice_clears_408_with_vat_and_timbre_no_inventory_leg` `:382-439` | full leg set + `verifyChain()` | |
| ↳ `test_idempotent_second_post_is_noop` `:527-549` | JE count stays 1, no double increment | |
| ↳ `test_hard_overclear_throws_under_warn_and_rolls_back` `:555-580`, `test_write_boundary_overclear_rejected…` `:848-884` | | |
| ↳ PPV routing `:478-521`, `:717-748`; partial→remainder clears 408 `:586-628`; non-recoverable VAT capitalized `:676-711`; fail-loud invariant `:817-842` | | |
| `…/Accounting/SupplierInvoicePpvGlTest.php` | direct `createSupplierInvoiceGrIrClearingEntry` matrix vs WAC | `seedForCompany` `:80`; `StockLevel`/`Product.cost_price` `:109-129` |
| `…/Procurement/SupplierInvoiceReceiptClearingTest.php` | receipt-line-grain FIFO clearing, free window, draft receipts, multi-PO, partner drift, idempotent repost | accounts by purpose `:86-90`; policy `:100-108` |
| `…/Procurement/SupplierInvoiceBonusLineTest.php` | bonus flow end-to-end through `POST /purchase-orders/{po}/receive` then invoice+post | Parapharmacy vertical `:85`; seeder `:101`; `seedForCompany` `:119`; warehouse `:121-128`; GL asserts 408=100.000, VAT=19.000, Cr401=119.000, no PPV `:282-307` |
| `…/Procurement/SupplierInvoiceSnapshotTest.php` | `price_match_basis` / `matched_receipt_line_id` weighting + bonus skip | policy + receipt fixtures, no GL |
| `…/Procurement/SupplierInvoiceDocumentTest.php` | data layer: prefix, enum cast, fiscal category, `quantity_invoiced` defaults | none |
| `…/Treasury/SupplierPaymentGuardTest.php` | the B1 payment guard matrix | `seedForCompany` `:88`; `Account::findByPurposeOrFail(Bank)` `:89`; `RolesAndPermissionsSeeder` `:92`; `PaymentMethod` + `PaymentRepository(BankAccount, gl_account_id=Bank)` `:111-137` |
| ↳ draft invoice → 422 `:144-181`; posted w/o JE → 422 `:187-231`; happy path 201 `:238-290`; Draft JE → 422 `:299-371`; JE missing Cr-401 partner line → 422 `:381-455`; `balance_due = total` on posting `:462-563` | | |
| `…/Treasury/PaymentGlPostingTest.php` | `supplier_payment_posts_gl_and_refreshes_payable_balance` `:203-321` — JE `source_type='supplier_payment'` Posted `:274-280`, `PaymentType::SupplierPayment` + `isOutgoing()` `:291-297`, `payable_balance` refresh `:245,348` | |
| `…/Treasury/DeferredSupplierPaymentTest.php` | cheque/effet outbound: Dr **401** / Cr **4035** `:95-119`, Cr **403** `:121-135`, `INVALID_OUTBOUND_REPOSITORY` `:137-156`, immediate supplier Dr 401 / Cr bank + movement `:158-207`, idempotency header `:209-228` | `Company::factory()->tunisia()` + `seedForCompany` `:64-65`; seeder `:67`; `PaymentRepository::factory()` BankAccount `:86-92` |
| `…/Accounting/SupplierCreditNoteGlTest.php` | D1 reversal GL (2216 lines) — price adjustment vs goods return, `quantity_invoiced` decrement, bonus-return WAC un-dilution | `seedForCompany` `:120`; purposes `:122-126`; policy `:136-144`; `postedInvoiceWithPoLine()` hand-builds a Posted SI `:162-233` |
| `…/Taxation/SupplierInvoiceVatDeclarationTest.php` | closed-period refusal, VAT snapshot, TN declaration netting | `VatPeriod` / `TaxConfiguration` rows |
| `…/DocumentIngestion/CommitSupplierInvoiceTest.php` | OCR commit path (6 tests, `:130,159,199,235,260,298`) | seeder + `seedForCompany` + `StandaloneReceiptService` + `MediaAsset`/`DocumentIngestion` |
| `…/Permissions/P2pEntryPointPermissionsTest.php` | asserts `supplier-invoices.link-receipts` exists and is on `accountant` `:21,59` | |

**Note on TN codes in tests:** every supplier-invoice GL test resolves accounts by `SystemAccountPurpose`, never by literal code. The only literal TN codes asserted on the supplier side are in `DeferredSupplierPaymentTest.php` — **401** (`:110,132,183,204`), **4035** (`:114`), **403** (`:132`) — plus **5312** as a customer negative control (`:183`).

---

## 13. EDGE CASES WORTH PROBING

Each anchored to the line that makes it interesting.

1. **Invoice qty > received qty.** Aggregate per PO line: `bccomp($totalQty, $matchable) > 0 → QuantityVariance` (`SupplierInvoiceMatcher.php:377-382`) and `assertPostable` throws under **both** Warn and Block (`:217-231`). Expect create to succeed with `match_status='quantity_variance'`, then `POST /post` → 422 `POSTING_BLOCKED`. FE shows `post-block-reason` and disables `btn-post` (`SupplierInvoiceDetailPage.tsx:127, 403`). Also probe the **split** form: two invoice lines each within bounds whose SUM exceeds matchable (`:325-333`, covered by `SupplierInvoiceMatcherTest.php:471-527`).
2. **Invoicing something never received.** `matchable <= 0 && totalQty > 0 → Exception` (`SupplierInvoiceMatcher.php:367-374`) — a DIFFERENT status from over-clear, both hard. Probe that the FE's `post-block-reason` copy (which says "quantity over-invoice vs received") is honest for the `exception` case too.
3. **Price differs by 0.001.** With `variance_tolerance_percent='2.00'` and `variance_tolerance_max_amount='1.000'` (`ProcurementPolicy.php:107-108`), `extendedVariance = 0.001 × qty` must clear **both** thresholds (`SupplierInvoiceMatcher.php:531-533`). At qty 1 it passes; at qty 1001 the max-amount arm alone trips it → `price_variance` with a tiny per-unit delta. Under default `Warn` it still posts (`:255-261`) and the delta lands on **PPV 6585/7585** (`GeneralLedgerService.php:2292-2313`), not inventory. Probe the sign: invoice cheaper than the accrual → Cr `PurchasePriceVarianceIncome` (`:2303-2312`).
4. **The price basis is the RECEIPT accrual, not the PO price (ThreeWay).** `priceBasisForInvoiceLine` prefers the stamped `price_match_basis` (`SupplierInvoiceMatcher.php:550-552`). An invoice created BEFORE a second, cheaper receipt keeps the old basis. Probe: receive at 10, create invoice (basis 10), receive more at 12, re-match — the basis is the **stamped** one, so re-match can disagree with a fresh computation. Also probe `match_mode='two_way'`, where the basis flips to `poLine.unit_price` (`:546-548`).
5. **Two invoices for the same receipt.** The FIFO window is `received_qty − quantity_invoiced` per receipt line (`ReceiptLineConsumptionPlanner.php:78-84`); the first post increments `goods_receipt_lines.quantity_invoiced` (`SupplierInvoicePostingService.php:516-517`). The second invoice should read `matchable = 0` and land on `Exception`/`QuantityVariance`. **But the two invoices can both be created as Drafts first** — the snapshot planner's `qtyAlreadyPlanned` only de-duplicates lines WITHIN one invoice (`CreateSupplierInvoiceService.php:161-165`), so two drafts will both claim the same receipt slice and only the second POST fails. Worth a scenario.
6. **0 % VAT line beside a 19 % line.** Both go through the same per-line VAT math (`CreateSupplierInvoiceService.php:96-101`); the 0 % line still gets `recoverable_tax_amount = 0.000` and `tax_recoverable = true` (`:182-183`). The GL omits zero legs (`GeneralLedgerService.php:2266`), and `PostedLineTaxSnapshotBuilder::divergences()` must still reconcile — a mismatch throws "its deductible VAT is not declarable" and **rolls the whole post back** (`SupplierInvoicePostingService.php:416-423`). This is the highest-value probe for TN: the ledger rounds HALF-UP per line while the tax engine truncates per rate bucket (documented drift example at `:385-389`).
7. **Post twice.** Second POST is a **silent 200 no-op**, not an error (`SupplierInvoicePostingService.php:115-117`, `:172-174`, controller comment `:347-351`), backed by the DB partial unique index (`2026_06_26_120000_unique_journal_entries_source_procurement.php:44-48`). Assert `journal_entries` count stays 1 and `quantity_invoiced` does not double.
8. **Post into a closed VAT period.** `assertBackdatingPeriodIsOpen` (`SupplierInvoicePostingService.php:119-123`) → bespoke 422 with `error.period.reopenable` and `remedies` (`SupplierInvoiceController.php:420-467`). Then re-POST the **already-posted** invoice in a now-closed month: it must stay a 200 no-op because the pre-probe runs first (`:102-117`). Also note: there is **no way to fix the date** — no edit route (`:437-444`).
9. **Pay more than the balance.** Three independent refusals: pre-transaction per-allocation (`PaymentController.php:589-601`), payment-amount-vs-total-allocated (`:803-814`), and the locked-row re-check (`:1049-1064`) — all `SUPPLIER_PAYMENT_EXCEEDS_PAYABLE`. Unlike the customer side, there is **no cap-and-advance** (`:800-802`). Probe two concurrent partial payments summing above the balance to exercise the locked-row arm.
10. **Pay a Draft (or unposted-JE) supplier invoice.** `SUPPLIER_INVOICE_NOT_POSTED` twice over — status check (`:532-543`) and posted-Cr-401-partner-line evidence check (`:548-583`). Probe the second arm specifically by posting the invoice and then… (only reachable via a doctored JE; covered by `SupplierPaymentGuardTest.php:299-371`).
11. **Pay with a drawer/repository of another company or location.** `repository_id` is `ScopedExists::tenantAndCompany('payment_repositories')` (`PaymentController.php:395-399`), so a foreign company's repository fails validation. A **same-company but non-ledgered** repository (`gl_account_id IS NULL`) fails with `SUPPLIER_PAYMENT_REQUIRES_LEDGERED_REPOSITORY` (`:754-772`). Location is NOT validated against the invoice — the payment's `location_id` is simply inherited from the first allocated document (`:645-654`), so a repository in location B paying an invoice from location A is **accepted**. Worth flagging.
12. **Mixed allocation.** One payment allocating to a supplier invoice AND a customer invoice → `MIXED_ALLOCATION_TYPES` (`:634-641`). Allocating a supplier invoice through `payments` with a `payments[]` array → `SUPPLIER_INVOICE_NOT_PAYABLE_VIA_MULTILINE` (`:1401-1414`).
13. **Cancel after payment.** There is no cancel route for a supplier invoice at all (§9) and `PaymentType::SupplierPayment` reversal is `Unsupported` (`PaymentType.php:231` → 422 at `PaymentRefundService.php:1220-1223`). Probe that the UI offers no such affordance and that `POST /payments/{id}/reverse` 422s. Then probe `POST /payments/{id}/refund` — `canRefund()` passes for a completed cash supplier payment (`:1072-1085`) and the refund path is **not supplier-aware**; **UNVERIFIED** what GL it writes. High-value bug hunt.
14. **Invoice currency ≠ PO currency.** Blocked at validation with "The invoice currency must match all purchase order currencies." (`CreateSupplierInvoiceRequest.php:284-295`) and re-asserted on the locked parents at post time (`SupplierInvoicePostingService.php:632-644`). Separately, `currency` has only `size:3` (`CreateSupplierInvoiceRequest.php:108`) — probe a syntactically valid but unknown code (e.g. `XXX`) on an invoice-first request (which has no PO to compare against): the scale resolver falls back to 3 at creation (`CreateSupplierInvoiceService.php:55` uses `getScaleSafe($currency, 3)`) but posting uses the **strict** `getScale($currency)` (`SupplierInvoicePostingService.php:200`) plus a currency-refusal check (`:406-413`). Likely a create-succeeds / post-fails asymmetry.
15. **Bonus line at non-zero price.** Refused before anything else in `withValidator` (`CreateSupplierInvoiceRequest.php:204-210`). Bonus line on an invoice-first request → refused (`:195-201`). Bonus line for a company outside `PurchaseBonusGate` (module or country) → `prohibited` (`:146-148`; gate at `PurchaseBonusGate.php:25-36`). TN parapharmacy is inside the country allow-list, so the module toggle is the live axis.
16. **Partial link-receipts.** Linking only some lines leaves `pending_receipt = true` (`SupplierInvoiceReceiptLinkingService.php:145-152`), so post stays blocked with `PENDING_RECEIPT_UNLINKED` (`SupplierInvoicePostingService.php:77-79`) and the FE keeps `pending-receipt-banner` visible. Probe that the second link call succeeds and flips the flag.
17. **Re-match a posted invoice.** 422 `MATCH_NOT_ALLOWED` (`SupplierInvoiceController.php:275-286`) — the sb-q11 guard. The FE hides `btn-rematch` once posted (`SupplierInvoiceDetailPage.tsx:371`, rendered only `!isPosted`), so this must be probed at the API level.
18. **Invoice-first delivered without the approval permission.** Creation succeeds (needs `supplier-invoices.create-pending` + `goods-receipt.create-standalone`), but POST throws `INVOICE_FIRST_APPROVAL_REQUIRED` because `invoice_first_requires_approval` defaults to **true** (`ProcurementPolicy.php:114`, gate at `SupplierInvoicePostingService.php:675-700`). The FE surfaces only the raw message in a toast.
19. **`variant_id` is unscoped.** `lines.*.variant_id` is `nullable, uuid` with no `ScopedExists` (`CreateSupplierInvoiceRequest.php:120`), unlike `product_id` (`:115-118`). Probe a foreign-company variant uuid on an invoice-first line.
20. **`balance_due` written twice.** The PG trigger recomputes `balance_due` on every `payment_allocations` insert (`2026_01_08_214145_add_balance_due_cache_trigger.php:27-61`) AND the controller writes `currentBalance − allocationAmount` on the locked row afterwards (`PaymentController.php:1090-1092`). They agree in the normal case; probe a credit-note allocation against the same supplier invoice (the trigger subtracts `credit_note_allocations` too, `:41-45`) — the controller's arithmetic does not.
21. **Second company, same number.** Numbering and uniqueness are company-scoped (`DocumentNumberingService.php:47-50`; `2026_08_30_100500_enforce_company_scoped_document_numbers.php:50-60`), so `SI-2026-0001` may exist twice in a tenant. Probe the "second-of-everything" rule: create the same supplier + PO + invoice in company B and confirm the list, detail, match and post all stay isolated — and specifically that the JE minting works for company B (C-27, `docs/handoff/HANDOVER-session-B2-2026-08-25.md:17`).
22. **Missing GL account purpose.** Any of the seven purposes absent → `Account::findByPurposeOrFail` throws a `RuntimeException` (not a `DomainException`), so it will NOT be caught by the controller's `catch (\DomainException)` (`SupplierInvoiceController.php:391-393`) and will surface as a **500**. Probe on a company whose chart was provisioned under the flag path (`ChartOfAccountsService.php:51-71`) if `PurchasePriceVarianceIncome`/`PurchaseStampDuty` are missing.
