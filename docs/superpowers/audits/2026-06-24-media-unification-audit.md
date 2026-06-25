# Media Subsystem Unification — Audit (grounded on `origin/dev` @ `57d78d081`)

> Created 2026-06-24 from the handover `docs/superpowers/coordination/2026-06-24-media-unification-handover.md`.
> Audit performed in worktree `feat/media-subsystem-unification` (checked out at `origin/dev`). Every claim is `file:line` on that checkout.
> **Reliability note:** a parallel audit agent read the *stale* main checkout (`docs/media-subsystem-architecture`, 496 commits behind) and reported product images as un-migrated. That was a false negative (its `grep --include=*.php` errored under zsh). Direct verification confirms the corrected state below.

## TL;DR — three patterns, one already retired

| System | Location | Storage | State on dev |
|---|---|---|---|
| **NEW** `media_assets`/`media_attachments`/`media_renditions` | `Catalog` module | MinIO (`s3`), per-asset `storage_disk` | ✅ Live; owner-agnostic core |
| **Product images** (former `product_images`/`ProductImage`) | — | — | ✅ **Already migrated** onto MediaAsset; legacy dropped |
| **LEGACY** `document_attachments` / `DocumentAttachment` | `Media` module | **local disk** | ❌ Not migrated; wired into 5 detail pages |
| **Supplier invoices** (incoming) | Procurement spec | plans legacy `AttachmentService` (→ MinIO via env) | 🔜 About to become the **3rd legacy consumer** |

## 1. NEW MediaAsset foundation (in `Catalog`)

- Core models: `Catalog/Domain/Media/{MediaAsset,MediaAttachment,MediaRendition}.php`. Enums in `Catalog/Domain/Enums/` (MediaAssetType, MediaOwnerType, MediaRole, MediaSource, MediaStatus, RenditionFormat, RenditionName).
- Contracts (Catalog-namespaced): `Catalog/Domain/Contracts/{MediaAssetRepositoryInterface, MediaAttachmentRepositoryInterface, MediaStorageInterface, RenditionGeneratorInterface}`. Bound in `CatalogServiceProvider.php:46-50`.
- Services: `MediaUploadService`, `MediaAttachmentService`, `MediaUrlResolver`, `RenditionService`; job `GenerateRenditions`; query `CatalogMediaQuery`; infra `MediaStorageAdapter`, `ImageRenditionGenerator`, two Eloquent repos.
- **Owner-agnostic core (verified):** `MediaAttachmentService::attach(string $assetId, MediaOwnerType $ownerType, string $ownerId, MediaRole $role, int $sort, string $tenantId)` (`MediaAttachmentService.php:47`) never branches on owner type. `RenditionService`, repos, storage adapter all generic. **Zero inbound imports** from Product/Category/Inventory into the core engine.
- **The single gate for a new owner = adding a `MediaOwnerType` case.** `owner_type` is a string cast to the enum (`MediaAttachment.php:38`); no FK/validation tying `(owner_type, owner_id)` to a table.
- **Catalog/Product couplings to de-couple on promotion:**
  1. `MediaUploadService::uploadForProduct(...)` hardcodes a product path `products/{tenant}/{product}/{uuid}/original.{ext}` (`MediaUploadService.php:97`) and takes a bare `productId`. Needs an owner-generic `upload(ownerType, ownerId, …)` + path scheme.
  2. `MediaUrlResolver::forPosSync()` calls `route('products.images.download', …)` (`MediaUrlResolver.php:130`) — a **frozen POS-cache URL contract** owned by Product.
  3. Read seam `CatalogMediaQueryInterface` → returns product-shaped `ProductMediaData` (`Shared/Contracts/CatalogMediaQueryInterface.php`). Injected by `ProductController.php:44` and `POS/SyncController.php:41`. Needs an owner-agnostic sibling.
  4. Media **routes are registered in `Product/routes.php:120-163`** (not a Catalog routes file) — existing cross-module smell. Controllers `ProductMediaController`/`PublicProductMediaController` are genuinely product-specific façades; `SignedMediaController` is generic.
- Schema: `media_assets` (per-asset `storage_disk` NOT NULL, `external_url`, `checksum`, dims, `status`, softDeletes); `media_renditions` (FK cascade, UNIQUE `(media_asset_id,name,format)`); `media_attachments` (`owner_type`,`owner_id`,`role`,`sort_order`,`channel`,`locale`,`alt`,`caption`; **PG partial UNIQUE** `one_primary` per `(owner_type,owner_id,COALESCE(channel,''),COALESCE(locale,''))` WHERE role='PRIMARY'`). ⚠️ the one-primary index is **NOT tenant-scoped** — latent cross-tenant note (mitigated by uuid owner_ids + tenant-scoped queries).
- Tests: extensive under `tests/Feature/Modules/Catalog/Media/` + `tests/Unit/Modules/Catalog/Media/`. **Gap: owner-type genericity is untested** — every test uses `MediaOwnerType::Product`. PG-only partial-unique behavior needs real-PG CI.

## 2. Product images — DONE

- `Product/routes.php:120`: *"Product Images (authenticated) — façade over media_assets / media_attachments"*, wiring Catalog `ProductMediaController`.
- `2026_06_12_100004_drop_product_images_table.php` exists. `ProductImage` model, `ProductImageService`, `ProductImageController` **deleted**.
- `ProductData.php:7,52,78` sources images from `ProductMediaData`/`MediaAttachmentData`. Nothing to do here.

## 3. LEGACY DocumentAttachment (in `Media` module)

- 6 files only: `Media/Domain/DocumentAttachment.php`, `Media/Application/Services/AttachmentService.php`, `Media/Presentation/Controllers/AttachmentController.php`, `Media/Presentation/Requests/UploadAttachmentRequest.php`, `Media/routes.php`, `Media/MediaServiceProvider.php` (near-empty: only `loadRoutesFrom`). No repository/contract/Resource.
- Table `document_attachments` (`2025_12_14_000001`): **hard FK `document_id` → documents, cascadeOnDelete** (`:16`); `storage_disk` default `'local'`; `AttachmentService::getStorageDisk()` hardcodes `return 'local'` (`:194-198`); local path `attachments/{tenant}/{document}/{uuid}.{ext}`.
- Validation: max 10 MB; mimes images+pdf+doc/docx+xls/xlsx+txt/csv (`AttachmentService.php:28-43`, `UploadAttachmentRequest.php:25-37`).
- Routes `documents/{document}/attachments` (`Media/routes.php:25-41`), gated `can:documents.view|update`, `CompanyContext` tenant+company scoped.
- **No factory/seeder.** Production volume = whatever users uploaded, per-tenant — must be assessed per tenant DB at migration time.
- Frontend: `documents/components/DocumentAttachments.tsx` + `hooks/useAttachments.ts`; wired into 5 detail pages: `InvoiceDetailPage.tsx:510`, `QuoteDetailPage.tsx:395`, `SalesOrderDetailPage.tsx:454`, `PurchaseOrderDetailPage.tsx:470`, `ExpenseDetailPage.tsx:282`.
- **Migration delta → media_assets/media_attachments (owner_type=Document):** mostly direct column mapping; gaps: (a) **local→MinIO disk** decision (leave on `local` vs copy bytes + rewrite path); (b) `description` → `caption`; (c) must NOT default doc attachments to `role=PRIMARY` (partial-unique); (d) cascade-on-document-delete (hard FK today) must be reimplemented as an observer/event since `media_attachments.owner_id` has no FK; (e) `checksum`/`status` backfill.

## 4. Module/platform mechanics

- **Registration:** explicit list in `bootstrap/providers.php` (no manifest). `MediaServiceProvider::class` at `:22`, `CatalogServiceProvider::class` at `:7`. New module = ServiceProvider + routes.php + append to providers.php.
- **Cross-module pattern (live):** `Shared/Contracts/` interfaces, bound centrally (`AppServiceProvider.php`) or in the owning module. The media seam (`CatalogMediaQueryInterface`) is the canonical example — but product-shaped, needs generalizing.
- **Existing `Media` module suitability:** name is right; provider near-empty; currently document-attachment-specific + local-disk. The Stage-1 spec rejected co-locating catalog assets here to avoid coupling (`2026-06-12-media-subsystem-architecture-design.md:112-118`) — that objection is obsoleted by the decision to *unify*.
- **New owner-type targets (all uuid-keyed, fit `owner_id` with no schema change):** `Document` (`documents`, uuid), `StockMovement`/`StockAdjustment` (`stock_movements`, uuid), `SupplierInvoice` (currently modeled as a `Document`).

## 5. Supplier-invoice RISK (time-sensitive)

`docs/superpowers/specs/2026-06-24-domestic-procurement-to-pay-gr-ir-design.md:73,114,120` explicitly targets the **legacy** `AttachmentService` (SupplierInvoice is a `Document`), only switching `getStorageDisk()` to an env-driven MinIO disk; media unification "explicitly deferred." → becomes the **3rd legacy consumer** unless redirected. Stock-adjustment §6-D (`2026-06-23-…-plan.md:149,157`) is already LOCKED to defer to MediaAsset.

## 6. Frontend & types

- **No shared uploader.** `ProductImageUpload.tsx` (image-only, `append('image')`) and `DocumentAttachments.tsx` (`append('file')`) are bespoke. A unified `<MediaUpload>` is net-new scope.
- **TS types:** media DTOs (`Catalog/Application/DTOs/*`) **not yet exported** via `typescript:transform`; frontend uses hand-written interfaces. No `media`/`attachments` i18n namespace (text lives under `products:images.*` / `documents:attachments.*`).

## 7. Remaining unification work (what this plan must cover)

1. **Promote** the MediaAsset core out of `Catalog` (de-Product-ize upload path/signature + `forPosSync`); decide its home module.
2. **Generalize** the cross-module read/write seam in `Shared/Contracts` (owner-agnostic, alongside the existing product-shaped one).
3. **Add owner types** `Document` (+ `StockMovement`/`SupplierInvoice` as needed).
4. **Migrate** `document_attachments` → `media_assets`/`media_attachments`; retire legacy `AttachmentService`/`Media` legacy; reimplement document-delete cascade.
5. **Redirect** the supplier-invoice session to MediaAsset before it cements a 3rd legacy consumer.
6. **(Scope TBD)** unified frontend component + TS type export + i18n namespace.
