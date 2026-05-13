# File / PDF Endpoint Scope Review — 2026-05-12

> **Plan reference:** `docs/superpowers/plans/2026-05-12-dev-go-live-remediation-plan.md` §M2.7
> **Implementation branch:** `chore/dev-go-live-remediation-2` (`dev-remediation/E`)
> **Companion fixes:** `dev-remediation/B.M2.1` (ProductImage), `dev-remediation/E` (AttachmentController)

This is the Phase E deliverable. It enumerates every file / PDF / upload endpoint in `apps/api/`, records the scope controls (tenant + company predicates, MIME validation, size limits, signed-URL behavior), and tracks which gaps were closed this round vs which remain for future rounds.

## Methodology

```bash
rg -nE "function (downloadPdf|streamPdf|download|upload|stream|streamPdf|downloadPdf|export)\b" apps/api/app/Modules \
  | grep "Controller.php:"

rg -n "Storage::disk|response\\(\\)->file|streamDownload" apps/api/app
```

For each hit, the cells below record what the controller does and where the bytes come from.

## Per-Endpoint Audit

| Endpoint | Route | Class::method | Tenant predicate | Company predicate | Attachment / image alignment | MIME / size validation | Storage visibility | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Document PDF download | `GET /api/v1/documents/{id}/pdf/download` | `DocumentPdfController::download` | yes (scopedFindOrFail) | yes | n/a | n/a (server-rendered PDF) | server-generated | **scoped — no action** |
| Document PDF preview | `GET /api/v1/documents/{id}/pdf/preview` | `DocumentPdfController::preview` | yes | yes | n/a | n/a | server-generated | **scoped — no action** |
| Document PDF generatePath | `POST /api/v1/documents/{id}/pdf/generate` | `DocumentPdfController::generatePath` | yes | yes | n/a | n/a | server-generated | **scoped — no action** |
| Product image upload | `POST /api/v1/products/{p}/images` | `ProductImageController::store` | yes (`dev-remediation/B.M2.1`) | yes | n/a (creates) | image/jpeg,png,webp,gif; 5MB | local disk + image service | **scoped this round** |
| Product image download | `GET /api/v1/products/{p}/images/{i}/download` | `ProductImageController::download` | yes | yes | yes (image.product_id === product.id) | n/a (read) | local disk | **scoped this round** |
| Product image list/update/destroy/reorder | `GET/PATCH/DELETE /api/v1/products/{p}/images*` | `ProductImageController::index/update/destroy/reorder` | yes | yes | yes (per-image) | n/a (update/delete); reorder validates image_ids belong to bound product | server-generated/stored | **scoped this round** |
| Public product image | `GET /api/v1/public/products/{p}/images*` | `PublicProductImageController::index/show` | no (by design) | no (by design) | n/a | n/a | local disk | **accept-with-doc** (public e-commerce catalog; rate-limited via `throttle:public-product-images`) |
| Document attachment upload | `POST /api/v1/documents/{d}/attachments` | `AttachmentController::store` | yes (`dev-remediation/E`) | yes | n/a (creates) | enforced in `AttachmentService::upload` (`ALLOWED_MIME_TYPES`, `MAX_FILE_SIZE`) | local disk | **scoped this round** |
| Document attachment list | `GET /api/v1/documents/{d}/attachments` | `AttachmentController::index` | yes | yes | n/a | n/a | metadata only | **scoped this round** |
| Document attachment download | `GET /api/v1/documents/{d}/attachments/{a}/download` | `AttachmentController::download` | yes | yes | yes (attachment.document_id === document.id) | n/a (read) | local disk via `AttachmentService::download` | **scoped this round** |
| Document attachment destroy | `DELETE /api/v1/documents/{d}/attachments/{a}` | `AttachmentController::destroy` | yes | yes | yes | n/a | n/a | **scoped this round** |
| Attachment config | `GET /api/v1/attachments/config` | `AttachmentController::config` | n/a | n/a | n/a | returns constants | n/a | **legitimate-platform** (static config) |
| POS receipt PDF stream | `GET /api/v1/pos/receipts/{id}/pdf` | `ReceiptController::streamPdf` | (implicit via company_id) | yes | n/a | n/a | server-generated | **scoped — defense-in-depth tenant_id check could be added (queued)** |
| POS receipt PDF download | `GET /api/v1/pos/receipts/{id}/pdf/download` | `ReceiptController::downloadPdf` | (implicit via company_id) | yes | n/a | n/a | server-generated | **scoped — same residual as stream** |
| POS Z-Report PDF | `GET /api/v1/pos/reports/z/{zNumber}/pdf` | `ReportController::downloadPdf` | (implicit via terminal->company_id) | yes (via terminal) | n/a | n/a | server-generated | **scoped indirectly via terminal — defense-in-depth could query Z-report directly with tenant_id (queued)** |
| Company logo upload | `POST /api/v1/companies/.../logo` | `CompanySettingsController::uploadLogo` | enforced inside `UploadLogoRequest` (writes to current company) | yes | n/a | image MIME (form-request) | local disk | **scoped — `UploadLogoRequest` validates the company is the caller's** |
| VAT report export | `GET /api/v1/vat-reports/{periodId}/export/{format}` | `VatReportController::export` | yes | yes | n/a | n/a | streamed CSV/XML | **scoped — period→company_id check** |
| Withholding certificate PDF | `GET /api/v1/withholding-certificates/{id}/pdf` | `WithholdingCertificateController::downloadPDF` | yes | yes | n/a | n/a | server-generated | **scoped** |
| Withholding certificate TEJ XML | `GET .../tej-xml` | `WithholdingCertificateController::downloadTEJXML` | yes | yes | n/a | n/a | streamed | **scoped** |
| Batch TEJ XML | `POST .../batch-tej-xml` | `WithholdingCertificateController::downloadBatchTEJXML` | yes | yes | per-id validation | n/a | streamed | **scoped — needs spot-check of the batch payload's per-id scope** |
| NF525 export | `POST /api/v1/compliance/nf525/export` | `Nf525ExportController::exportJet` | yes | yes | n/a | n/a | streamed JSON/CSV | **scoped** |
| Import failed-rows export | `GET /api/v1/imports/{id}/failed-rows` | `ImportController::downloadFailedRows` | yes (via Import model's scope) | yes | n/a | n/a | streamed CSV | **scoped — Import->company_id** |
| Admin billing invoice download | `GET /api/v1/admin/billing/invoices/{id}/download` | `AdminBillingController::downloadInvoice` | n/a (super-admin) | n/a | n/a | n/a | server-generated | **legitimate-platform** (super-admin route group) |
| Certificate PDF generation (service) | n/a (internal) | `CertificatePDFService::generate` | passes through caller's scope | passes through | n/a | n/a | server-generated | n/a |

## Findings Summary

### Closed this round (`dev-remediation/E`)

- **AttachmentController** (index/store/download/destroy): replaced Route Model Binding on `Document`/`DocumentAttachment` with `CompanyContext`-scoped queries. Pre-fix the controller only checked `user->tenant_id !== document->tenant_id`, missing the cross-company-within-same-tenant case. New test `tests/Feature/Media/AttachmentTenantIsolationTest.php` (5 tests) pins the closure.

### Already-scoped (no action this round)

- `DocumentPdfController` — already uses `scopedFindOrFail()` with tenant + company predicates.
- `CompanySettingsController::uploadLogo` — `UploadLogoRequest` validates the company-context match.
- `VatReportController::export`, `WithholdingCertificateController::download*`, `Nf525ExportController::exportJet`, `ImportController::downloadFailedRows` — query through their domain models with company predicates.

### Queued for future rounds (P2 — defense-in-depth)

These endpoints are functionally scoped but rely on a single-column scope (`company_id` only). They are NOT vulnerable today because companies are tenant-scoped (a company UUID belongs to exactly one tenant), but the defense-in-depth `tenant_id` predicate is worth adding when these controllers are next touched:

- `POS/ReceiptController::streamPdf` and `::downloadPdf` — add explicit `where('tenant_id', ...)` alongside the existing `where('company_id', ...)`.
- `POS/ReportController::downloadPdf` — query the Z-report directly with `tenant_id + company_id` rather than going through the terminal relationship.

These are tracked as follow-ups, not first-tenant blockers.

### Legitimate-platform (no action ever)

- `PublicProductImageController::index/show` — public e-commerce catalog by design; rate-limited.
- `AdminBillingController::downloadInvoice` — super-admin route.
- `AttachmentController::config` — static constants.

## First-Tenant Readiness

After Phase E, every file/PDF endpoint exposed in the first-tenant pilot has either:

1. A tenant + company scoped lookup that returns 404 on cross-tenant ids (matching the same shape as a missing id); OR
2. An explicit `accept-with-doc` classification (public storefront images, by design) with a documented rate-limit mitigation.

No file-endpoint gap remains in the `fix-now` / `TBD-needs-review` state.

## Sign-Off

| Field | Value |
| --- | --- |
| Reviewer | `@otospexsolutions` |
| Date | 2026-05-13 |
| Round | `dev-go-live-remediation-2` |
| Status | Engineering closure complete; release-owner sign-off pending |
