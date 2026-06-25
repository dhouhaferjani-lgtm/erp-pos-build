# Coordination: Supplier-Invoice Attachments → use the unified MediaAsset system (NOT legacy AttachmentService)

> To: the domestic procurement-to-pay / GR-IR session (`docs/superpowers/specs/2026-06-24-domestic-procurement-to-pay-gr-ir-design.md`).
> From: the media-unification session (`feat/media-subsystem-unification`). Created 2026-06-24. Owner-approved redirect.
> Audit backing this note: `docs/superpowers/audits/2026-06-24-media-unification-audit.md`.

## What's changing

The legacy `Media\Application\Services\AttachmentService` + `document_attachments` table (local disk, hard `document_id` FK) are **being retired** and migrated onto the unified `media_assets` / `media_attachments` system. The owner has approved the unification; product images already run on it, and document attachments are next.

Your procurement spec currently plans to attach the supplier-invoice PDF/scan via `AttachmentService::upload(Document, …)` and switch `getStorageDisk()` to an env-driven MinIO disk (spec lines 73, 114, 120). **Please do not build on `AttachmentService` internals or add a disk hack to it** — it is going away.

## What to do instead

A `SupplierInvoice` is modeled as a `Document` (a `DocumentType`). The unification rewires the **existing, unchanged** HTTP contract:

```
GET    documents/{document}/attachments
POST   documents/{document}/attachments          (multipart: field "file")
GET    documents/{document}/attachments/{id}/download
DELETE documents/{document}/attachments/{id}
```

…to write through the unified engine (`MediaUploadService` + `MediaAttachmentService`) with **`MediaOwnerType::Document`**, storing on **MinIO** automatically. So:

- **You do not need to touch media code at all.** Attach supplier-invoice documents through that same `documents/{document}/attachments` endpoint (the frontend `DocumentAttachments` component already calls it). Once your supplier invoice is a `documents` row, attachments work with zero media work on your side.
- **Do not** add a `SupplierInvoice`-specific attachment table, controller, or service.
- **Do not** modify `AttachmentService::getStorageDisk()` — the new path is MinIO by default.

## Sequencing / unblock

The media-unification plan sequences **`MediaOwnerType::Document` + the rewired documents-attachments endpoint EARLY** (Phase 2) specifically so you are unblocked. If you need supplier-invoice attachments before that phase lands:

- Coordinate here first. Worst case, attach via the legacy endpoint *as it exists today* (it already accepts any `Document`) and it will be transparently migrated when Phase 2 cuts over — **but do not extend or fork the legacy service.**

## Why

Without this, supplier invoices become the **third** consumer of a legacy pattern we are actively deleting (after products, already migrated, and documents, in progress). The stock-adjustment plan §6-D is already locked to defer its justification-doc attachments to this same unified system. One owner enum + one upload path for the whole org.

## Open the loop

If your timeline can't wait for Phase 2, reply here (or ping the media-unification session) and we'll re-sequence. Otherwise: build supplier invoices as `Document`s and attach through the standard documents-attachments endpoint.
