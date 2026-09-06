# Supplier-Invoice API Contract (tonight's minimal go-live cut)

> Created 2026-06-26. Contract-first artifact so the backend-API agent and the web agent can build in parallel.
> **GATED:** do not start the build until the two Codex re-reviews land clean (supplier-invoice payment C4 + supplier credit-note D1). Payment endpoint below is conditional on the C4 verdict.

## Scope (minimal cut)
Create → auto-match → **post** + list + detail + attachment upload. Payment **reuses** the existing `POST /api/v1/payments` single-payment supplier path (already built; pending C4 re-review). Supplier **credit-note** API/UI = DEFERRED (services done; expose post-go-live).

## Done services to wrap (no business logic in controllers)
- `SupplierInvoiceMatcher::match(Document): SupplierInvoiceMatchStatus` · `assertPostable(Document, MatchEnforcement): void` · `matchableQty(DocumentLine): string`
- `SupplierInvoicePostingService::post(Document): void`  (408→401+VAT+timbre, locked/idempotent)
- `ProcurementPolicyResolver::forCompany(string $companyId): ProcurementPolicy`  (match enforcement warn|block)
- `SupplierCreditNotePostingService::post(Document): void`  (deferred from UI)

## Conventions (non-negotiable)
- Route middleware: `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` + `can:` per action. Module-gate the route group (`module:Procurement` or the resolved purchases gate) per CLAUDE.md rule 12.
- Pattern: follow `Document\Presentation\Controllers\PurchaseOrderController` (index/show/store/post actions). New `SupplierInvoiceController` registered via a Procurement `Presentation/routes.php` loaded from `ProcurementServiceProvider` (mirror `DocumentServiceProvider::loadRoutesFrom`).
- Money/qty are **strings** end-to-end (precision contract). API responses already unwrapped by `apiGet/apiPost` (no double-unwrap). All FE text via `t()`; design tokens only.
- Types: after DTOs land, run `php artisan typescript:transform`; FE consumes generated types. Until then FE uses interim types matching this contract.

## Endpoints

### GET /api/v1/supplier-invoices  (can:documents.view)
List. Query: `partner_id?`, `status?` (draft|posted|paid), `match_status?` (matched|price_variance|qty_blocked|unmatched), `date_from?`, `date_to?`, `page?`. Returns paginated `{data:[SupplierInvoiceListItem], meta}` (use `api.get`, keep `meta`).
`SupplierInvoiceListItem`: `{id, number, partner:{id,name}, issue_date, currency, total, status, match_status, has_source_document:bool}`.

### GET /api/v1/supplier-invoices/{id}  (can:documents.view)
Detail: header + `lines[]` (each: source PO line ref, qty, unit_price net, vat_rate, recoverable_tax_amount, line_subtotal), `source_purchase_order:{id,number}`, `match:{status, per_line[]:{po_line_id, ordered, received, invoiced, matchable, price_variance}}`, `attachments[]` (from the generic media endpoint), GL `posted_at?`.

### POST /api/v1/supplier-invoices  (can:supplier-invoices.manage)
Create DRAFT, link to a PO, then auto-run `match()`. Body: `{partner_id, source_document_id (PO id), currency, issue_date, due_date?, supplier_reference?, lines:[{source_line_id (PO line id), quantity (string, qty scale), unit_price (string, net/HT), vat_rate (string)}]}`. 201 → full detail incl. computed `match_status`. Validate: regex scale ceilings (qty `…{1,4}`, money `…{1,3}`, percent `…{1,2}`); every `source_line_id` belongs to the linked PO; partner matches PO partner.

### POST /api/v1/supplier-invoices/{id}/match  (can:supplier-invoices.manage)
Re-run `match()`; returns updated `match` block. (Idempotent; create already matches.)

### POST /api/v1/supplier-invoices/{id}/post  (can:supplier-invoices.manage)
`assertPostable($doc, $policy->matchEnforcement)` then `SupplierInvoicePostingService::post()`. 200 → detail with `status=posted`, `posted_at`. **422** when the hard quantity invariant blocks (over-invoice vs received) — this is NOT bypassable by `warn`. Price variance under `warn` → allowed (surface a warning in the response); under `block` → 422.

### POST /api/v1/documents/{document}/attachments  (can:documents.update + DocumentPolicy::attach) — REUSE (Stage E)
Existing generic `DocumentAttachmentController::store`. Stage E adds an optional validated `role`; supplier-invoice UI passes `role=source_document` (`MediaRole::SourceDocument`, `MediaAssetType::Document`, s3 disk). List/download/delete via the same generic endpoints.
F-W2-14 fix round 1: the route keeps the coarse `can:documents.update`, but store/destroy now take a per-TYPE verdict through `DocumentPolicy::attach()` — on a **supplier invoice** the caller must hold `supplier-invoices.manage`. Read (index/download) is unchanged (`can:documents.view`).

### Payment — REUSE `POST /api/v1/payments` (single-payment supplier path)  ⚠️ GATED on C4 re-review
Already built: Dr 401 / Cr treasury, decrements `payable_balance`. The multi-line `storeMultiple` path now rejects supplier invoices (`b593ee70b`). FE detail page "Record payment" posts the single-payment supplier payload. **Confirm SHIP before wiring.**
F-W2-14 fix round 1: `POST /payments` keeps `can:payments.create` on the route, and its **supplier-side** branch additionally requires `payments.pay-supplier` (`SupplierPaymentAuthorizer`, taken after validation and before any write). Customer-side payments are unchanged. Seeded to admin/manager/accountant only.

## Web (Stage F) — Purchases → Supplier Invoices
`apps/web/src/features/purchases/supplier-invoices/` (sits beside `GoodsReceiptListPage`). List page (filters + match-status badge), detail page (lines, linked PO, per-line match table, Post action, attachment upload+list, Record-payment if C4 ships). Route + nav under Purchases, module-gated. i18n keys in `purchases`/`common` namespaces, all 3 locales (en/fr/ar). Vitest for list/filter + match badge; typecheck + lint.

## Out of scope tonight (deferred, services already exist)
Supplier credit-note API/UI; advanced filters/bulk actions; import-mode (invoice-ahead-of-receipt = Phase 2).
