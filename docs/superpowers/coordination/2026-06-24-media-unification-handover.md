# Media Subsystem Unification — Session Handover

> Created 2026-06-24. Audit + plan only; do not implement until owner confirms. Spawned from the stock-adjustment audit (`docs/superpowers/plans/2026-06-23-stock-adjustment-writeoff-audit-and-plan.md`, §6-D), which defers justification-document attachments to this unified system.

## Handover prompt (paste to a fresh session)

GOAL: Decide and plan the unification of media/attachments into one org-wide subsystem. AUDIT + PLAN ONLY — do not implement until the owner confirms. Ground every claim in actual code on `origin/dev` (file:line); do not trust the `docs/media-subsystem-architecture` working-tree branch, which is ~490 commits behind dev.

CONTEXT — three coexisting patterns today:

1. **NEW unified foundation** (merged to `origin/dev`, 2026-06-12), but scoped to catalog owners and living INSIDE the `Catalog` module:
   - `apps/api/app/Modules/Catalog/Domain/Media/{MediaAsset,MediaAttachment,MediaRendition}.php`
   - Enums: `Catalog/Domain/Enums/{MediaAssetType,MediaOwnerType,MediaRole,MediaSource,MediaStatus,RenditionFormat,RenditionName}.php`
   - `Domain/Contracts/{MediaAssetRepositoryInterface,MediaAttachmentRepositoryInterface,MediaStorageInterface,RenditionGeneratorInterface}.php` (already abstracted — portability is plausible)
   - `Application/Services/{MediaUploadService,MediaAttachmentService,MediaUrlResolver,RenditionService}.php`; `Application/Jobs/GenerateRenditions.php`
   - `Infrastructure/Storage/MediaStorageAdapter.php` (per-asset `storage_disk` → MinIO); `Infrastructure/Rendition/ImageRenditionGenerator.php`
   - Migrations: `tenant/2026_06_12_100001_create_media_assets_table`, `_100002_create_media_renditions_table`, `_100003_create_media_attachments_table`
   - `media_attachments` = link table: `media_asset_id` + `owner_type`(string) + `owner_id`(uuid) + role/channel/locale/alt/caption/sort_order; UNIQUE one-primary per `(owner_type,owner_id,channel,locale)`. NOTE: deliberately NOT Laravel `morphTo` — owner is an explicit `(owner_type,owner_id)` pair gated by the `MediaOwnerType` enum (currently ONLY Product/ProductVariant/Category).
2. **LEGACY document attachments**: `apps/api/app/Modules/Media/Domain/DocumentAttachment.php` (table `document_attachments`, HARD FK `document_id`, **LOCAL DISK**), `AttachmentService`/`AttachmentController`, routes `documents/{document}/attachments` (`can:documents.update`). PDF/image/office/text, max 10MB.
3. **LEGACY product images**: `apps/api/app/Modules/Product/Domain/ProductImage.php` (table `product_images`, HARD FK `product_id`, MinIO/s3) — predates and overlaps the new MediaAsset system.

Existing design docs (read, but verify against code — they describe intent, not necessarily current state):
- `docs/superpowers/specs/2026-06-12-media-subsystem-architecture-design.md`
- `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md`
- `docs/planning/media-architecture-plan.md`

WHY NOW: products (done), documents (legacy local-disk), and incoming **supplier invoices** (a parallel session, reportedly about to build on the LEGACY document pattern) all need media. The stock-adjustment plan defers its justification docs to this unification. Without it, the legacy pattern keeps accreting consumers.

DELIVERABLES:
1. **Audit** (file:line, on `origin/dev`): exact current state of all three systems; what the MediaAsset `Domain/Contracts` already abstract; whether `MediaUploadService`/`MediaAttachmentService` are coupled to Catalog/Product or are owner-type-agnostic; what `document_attachments` and `product_images` would need to migrate onto `media_assets`.
2. **Target architecture decision** (options + recommendation):
   - **Module ownership**: promote the MediaAsset system out of `Catalog` into a first-class module (reuse the existing `Media` module namespace? a new one? a Shared kernel?) so Document/Inventory/SupplierInvoice consume it WITHOUT violating hexagonal boundaries (cross-module only via Shared/Contracts, Events, or a public Service — never cross-module model imports; apps/erp CLAUDE.md rule 6).
   - **Owner-type model**: confirm `(owner_type,owner_id)` + `MediaOwnerType` enum scales; enumerate new cases (Document, StockMovement, SupplierInvoice, …).
   - **Migration**: migrate `document_attachments` (local disk → MinIO) and `product_images` onto `media_assets`; retire the legacy `Media` module + `ProductImage`; backfill + zero-downtime + tenant-by-tenant (db-per-tenant).
   - **Storage/renditions/access**: MinIO disk; per-owner-type validation (PDF for docs vs images for products); public vs signed URLs; permissions per owner type.
3. **Phased plan** (audit → module promotion → consumer migration → legacy retirement), each phase independently shippable, TDD, with precision/i18n/design-token conventions noted where UI is touched.

CONSTRAINTS: Laravel 12 hexagonal; db-per-tenant (`database/migrations/tenant/`); MinIO is the `s3` disk; do NOT introduce `morphTo` (explicit `owner_type/owner_id` was the deliberate choice); dev-branch + worktree-isolation workflow; cross-cutting refactor on live tables — sequence to avoid breaking products/documents in flight.

START by confirming the audit on `origin/dev`, then STOP and present findings + the ownership decision before planning further.

## Related coordination
- Supplier-invoice session: see the coordination note asking them to target MediaAsset (new `MediaOwnerType::SupplierInvoice`) instead of the legacy `DocumentAttachment` pattern.
- Stock-adjustment plan §6-D: justification docs deferred here.
