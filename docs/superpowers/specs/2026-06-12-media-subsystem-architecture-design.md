# Media subsystem architecture (program design)

> Owner ask (2026-06-12, post-meeting): "Fix this once and for all across the entire application —
> not just images but media in general." Products (automotive parts, parapharmacy, retail) must
> carry a rich, multi-type media set (images now; catalogues/guides/PDFs and videos later, incl.
> self-produced how-to videos), shown mainly on the **product detail page** — i.e. the system grows
> into a centralized **PIM (Product Information Management)** capability. Separately, business
> documents (PO/SO/additional-cost vouchers, etc.) get **inline preview** ("view to confirm").
>
> This is the **overarching program design**. It decomposes into staged sub-projects, each with its
> own spec → plan → TDD implementation. Stage 1 is detailed in
> [`2026-06-12-catalog-media-backend-foundation-design.md`](2026-06-12-catalog-media-backend-foundation-design.md).
>
> Grounded in two rounds of industry research (display UX; data-model/architecture) — sources cited
> at the end. Decisions locked with the owner are marked **[LOCKED]**.

---

## 1. Problem & current state

Two parallel, partial media systems exist today, and neither does what we now need:

1. **`product_images`** (Product module) — **image-only** (MIME hard-coded to jpeg/png/webp/gif),
   `storage_disk` ∈ {`s3`(MinIO), `url`(external)}, async variant generation (sm/md WebP), and a
   *working* frontend gallery/lightbox (`ProductImageGallery`, `ProductPrimaryImageDisplay`,
   `ImageGalleryModal`). Per-product-owned, single-type, single-rendition.
   - Table: `apps/api/database/migrations/tenant/2025_12_29_155412_create_product_images_table.php`
   - Service: `app/Modules/Product/Application/Services/ProductImageService.php`
   - Variants: `app/Modules/Product/Application/Services/ImageVariantService.php`
   - Serve: `ProductImageController::download` → `ProductImageService::serve` (disk-aware:
     `Storage::disk($disk)->response($path)`; `url` disk → redirect to external URL).
   - `ProductData.primary_image_url` is already populated on **both** index() and show()
     (the original ticket's "show doesn't load it" premise was stale).

2. **`document_attachments`** (Media module, `App\Modules\Media`) — **multi-type** (PDF/DOCX/XLSX/
   images), FK'd to fiscal `documents`, **download-only** (no inline preview), local disk.
   - Table: `apps/api/database/migrations/tenant/2025_12_14_000001_create_document_attachments_table.php`
   - Model/Service/Controller: `app/Modules/Media/{Domain/DocumentAttachment.php,
     Application/Services/AttachmentService.php, Presentation/Controllers/AttachmentController.php}`
   - Frontend: `apps/web/src/features/documents/components/DocumentAttachments.tsx`, `hooks/useAttachments.ts`.

**Gaps vs. the ask:**
- Car-parts / catalogue media is **not modeled at all** — `AutomotiveProductMetadata` has zero media fields.
- **No PDF or video rendering** exists in the web app (no pdf.js/react-pdf, no `<video>` player).
- Category `image_path` and variant `image_url` are **bare strings**, not real assets.
- Product media doesn't reliably surface where it should (pickers/search), and the detail gallery is
  a bespoke image-only stack.

## 2. Decisions [LOCKED with owner]

- **Two subsystems, not one merged rewrite.** Catalog/product media and business-document
  attachments stay separate backends (different lifecycle, authz, audit, retention). They **share
  the frontend viewer components only** — "shared viewer, separate plumbing."
- **Catalog media uses a new 3-table PIM-grade model** (asset library + renditions + link),
  replacing the image-only `product_images`, **owned by the `Catalog` module** (§3.3). **No
  backfill** — owner-confirmed (2026-06-12) there are no customers and no production data, only demo
  seed data, which is **re-seeded** against the new model. The drop migration still runs a
  pre-drop row-count guard and logs discarded counts (cheap insurance; Codex review).
- **First-class media type now = IMAGE.** PDF / video / 360° spin are later stages on the *same*
  model via the `type` enum — **no schema reshape** required to add them.
- **Keep existing image behavior:** continue generating thumbnail + optimized renditions (for POS
  and e-commerce channels later).
- **Document attachments:** add a **safe inline `preview`** (PDF + images only) + cheap audit
  hardening (checksum, category enum). Keep the `documents` FK (referential integrity beats
  Odoo-style polymorphism for a fiscal ERP). Office docs stay download-only.
- **No media in dense data tables** — one representative image + type badges in cards/pickers; the
  **full mixed-media gallery only on the product detail page**.
- **Architecture constraints:** hexagonal (Domain/Application/Infrastructure/Presentation),
  strict separation of concerns, **atomic design** on the frontend, expandable + maintainable.

## 3. Target architecture

### 3.1 Catalog media — three entities (asset library + renditions + link)

Consensus shape from Shopify (unified files), Akeneo (asset family + asset_collection link),
Pimcore (DAM as first-class), Magento (image roles):

- **`media_assets`** — the binary + *intrinsic* metadata (independent of any product):
  `type` (IMAGE | DOCUMENT | VIDEO | EXTERNAL_VIDEO | SPIN_360), `source` (UPLOAD | EXTERNAL_URL),
  `status` (UPLOADED | PROCESSING | READY | FAILED), `storage_disk`, `storage_path`/`external_url`,
  `mime_type`, `file_size`, `checksum` (sha256 — integrity + dedup), `width`/`height`,
  `duration_ms` (video), `frame_count` (360, GS1 ≥24), `original_filename`, `title`, `uploaded_by`.
- **`media_renditions`** — pre-generated derivatives (none for `EXTERNAL_URL`):
  `media_asset_id`, `name` (THUMBNAIL | SMALL | WEB | ZOOM — *purpose*, not pixels), `storage_disk`,
  `storage_path`, `format` (webp/jpeg), `width`/`height`, `file_size`. Unique (`asset`,`name`,`format`)
  (format in the key so a future JPEG fallback can coexist with WebP per name — see Stage 1 §2).
- **`media_attachments`** — the link carrying *contextual* metadata:
  `media_asset_id`, `owner_type` (PRODUCT | PRODUCT_VARIANT | CATEGORY), `owner_id`,
  `role` (PRIMARY | GALLERY | DATASHEET | MANUAL | VIDEO_POSTER | SPIN | SWATCH), `sort_order`,
  `channel` (nullable — e-commerce/POS/internal), `locale` (nullable), `alt`, `caption`.
  One asset → many attachments = **reuse** (one OEM photo across many part numbers; one variant
  re-using its product's image).

**Why these seams matter:** `type` makes PDF/video/360 additive (no reshape). `source` keeps the
existing `url`-disk external images first-class but rendition-free. `role` replaces the `is_primary`
boolean with an extensible vocabulary. `channel`/`locale` are *cheap columns now* (POS vs e-commerce
imagery, Arabic alt text) with resolution logic **deferred (YAGNI)**. Named renditions let callers
ask for a *purpose* (`web`) so pixel sizes retune without touching the frontend.

### 3.2 Ingestion & serve

- **Upload → register → process(queued) → READY** status lifecycle (Shopify pattern). Image
  processing generates the named rendition set (**WebP only**, matching the current
  `GenerateImageVariants` behavior; a JPEG fallback is a future option the `format` key allows but
  Stage 1 does not build) on the `images` queue — re-homed as `GenerateRenditions`.
- **External-URL assets** are created `READY` immediately with no renditions; frontend uses the URL.
- **Serve:** disk-aware (reuse the existing `Storage::disk($disk)->response()` path). Published
  catalog imagery → cacheable URLs keyed by asset-UUID path (cache-bust on replace). Presigned URLs
  reserved for upload + genuinely private assets. (S3/CDN hardening is later; `storage_disk`
  abstracts it.)

### 3.3 Module placement (hexagonal + module boundaries) [REVISED after Codex review]

The **asset library is a bounded context owned by the `Catalog` module** — not `Media`. Rationale:
the existing `Media` module is *document-attachment-specific* (its only model, `DocumentAttachment`,
is hard-wired to `document_attachments` and nested under `documents/{document}` routes), so dropping
catalog PIM assets there would over-couple two unrelated concerns under one provider/route namespace.
The `Catalog` module already owns most of the entities media attaches to — `ProductVariant`,
`ProductAttribute`, `CompositeItem` — so catalog media links belong with them. (`Category` currently
lives in the `Product` module; category media is Stage-3 scope and does not change the Stage-1 home.)

```
app/Modules/Catalog/
  Domain/            MediaAsset, MediaRendition, MediaAttachment, enums, repository interfaces
  Application/       upload/attach/rendition services, DTOs, GenerateRenditions job
  Infrastructure/    Eloquent repositories, storage adapters (s3/public/url)
  Presentation/      controllers (upload/serve/attach), FormRequests
Shared/Contracts/    CatalogMediaQueryInterface (Product → Catalog read seam)
```

The **Product** module consumes media only through `Shared/Contracts/CatalogMediaQueryInterface`
(never importing Catalog models — Rule 6; Deptrac-enforced). **Media composition happens in the
Product controller/application layer, not in the `ProductData` DTO** — `ProductData::fromModel()` is
a static factory and is *not* a DI boundary, so the controller resolves the query and passes a
`ProductMediaData` into DTO construction. The existing `/products/{id}/images` +
`products.images.download` API surface is **preserved** (now backed by the new model) so the working
detail gallery keeps functioning through Stage 1.

Document attachments stay in the `Media` module unchanged; the two subsystems share only the
frontend viewer (§3.4).

### 3.4 Frontend media layer (atomic design)

Built in Stage 2, consumed by Stage 3 (product detail) and Stage 4 (attachment preview):

- **atom** `<MediaThumb media>` — type-aware, never fetches the heavy asset; image →
  `<img srcset loading=lazy>`, video → poster + play overlay, pdf → first-page poster + badge,
  unknown/missing → file-type icon. Fixed aspect-ratio box (no CLS).
- **molecule** `<MediaGallery items>` — primary viewer + thumbnail strip; selection, keyboard,
  ARIA live region; opens the lightbox.
- **organism** `<MediaLightbox media>` — `role=dialog` `aria-modal`, focus-trap, ESC, return-focus;
  dispatches by type to `ImageViewer` / `PdfViewer` (lazy pdf.js + download link) / `VideoPlayer`
  (native `<video preload=none poster>` or YouTube/Vimeo **facade**) / `SpinViewer`.

Accessibility per WAI-ARIA (native `<button>` controls, no focus-move on prev/next, captions for
video, alt on images). Design tokens (`lib/designTokens`), `t()` keys, no `any`.

### 3.5 Document-attachment inline preview (Stage 4)

Keep the subsystem; add **one `preview` endpoint** that serves untrusted bytes safely (web.dev
"Securely hosting user data"): server-**re-verified** content-type, `X-Content-Type-Options: nosniff`,
`Content-Disposition: inline` **only** for an allow-list (`application/pdf`, jpeg/png/gif/webp),
`Content-Security-Policy: default-src 'none'; sandbox`, `Cross-Origin-Resource-Policy: same-site`.
Everything else (DOCX/XLSX/SVG) → `attachment` (download). Reuse the shared `PdfViewer`/`ImageViewer`.
Add `checksum` (sha256) + a `category` enum (`SignedPurchaseOrder`/`SupplierInvoice`/… ) for audit.

## 4. Display placement rules (all stages)

- **Dense tables (ProductListPage list view, etc.):** **no media.** (Owner [LOCKED]; NN/g — don't add
  thumbnails that don't aid the decision; near-identical part photos don't.)
- **Cards / listing grids / pickers / search dropdowns:** one representative image + small type
  **badges** ("datasheet", "video", "360°"). Thumbnail in a picker row only when it speeds
  identification.
- **Product detail page:** the full mixed-media gallery + a distinct labeled "Documents" section for
  datasheets/guides (later stages).

## 5. Staged delivery (each = own spec → plan → TDD impl)

1. **Catalog media backend foundation** — 3 tables + enums (hexagonal, **Catalog module**), replace
   `product_images`, re-seed demo data, image rendition job, disk-aware serve, `CatalogMediaQuery`
   contract + `ProductData` media set; preserve the existing image API surface. *Image-only
   behavior, extensible model.* → detailed in the Stage 1 spec.
2. **Shared frontend media library** — `<MediaThumb>`/`<MediaGallery>`/`<MediaLightbox>` +
   `ImageViewer` (pdf/video viewers stubbed); refactor the product gallery onto it.
3. **Surface wiring** — *resolves the original "images don't render" bug*: detail gallery, product
   cards, pickers/search (thumb + badges), categories, variants. No media in tables.
4. **Document-attachment inline preview** — safe `preview` endpoint + checksum/category; reuse the
   shared viewer.
5. **Future PIM media types** — PDFs (datasheets/guides), self-hosted + YouTube videos, 360° spins:
   rendition jobs (poppler `pdftoppm` first page, `ffmpeg` poster frame), detail "Documents"
   section, video facade, spin viewer. Additive — no schema reshape.

## 6. Out of scope / deferred (noted, not built)

- Channel/locale **resolution** logic + admin UI (columns only).
- Central cross-tenant asset reuse beyond same-tenant; CDN/CloudFront signing; checksum **dedup**.
- Attachment **versioning / retention / legal-hold**; polymorphic `attachable_type/_id` (only if we
  must attach to non-`documents` records); Office-doc inline rendering; a separate sandbox file origin.
- Full GS1 conformance (we adopt its *vocabulary* — primary role, dimensions, alt/caption — so data
  is export-ready).

## 7. Risks

- **Replacing `product_images` has a large consumer surface** (Codex review, verified). It is NOT
  just the gallery + `ProductData` + seeders — the full set is: authenticated image CRUD
  (`ProductImageController`), the **public e-commerce** image API (`PublicProductImageController`,
  cross-tenant-by-design), **POS sync** image URLs (`SyncController`), the **ZIP product-image
  import** pipeline (`ImportController` → `ProcessProductImageImport` → `ProductImageImportService`,
  + `ImportType` enum), the `GenerateProductImageVariants` console command, the `ProductImage` domain
  model + `Product::images()/primaryImage()` relations, generated shared TS types
  (`packages/shared/types/generated.d.ts`), and the **POS SQLite image cache** (`apps/pos`:
  `imageCache.ts`, `useProductImage.ts`, `migrations.ts`). Each must be migrated to the new model or
  to the preserved façade; the Stage 1 spec enumerates them. Mitigation: no real data (owner-confirmed
  — only demo seed data), TDD, preserve the existing + public image API surface, re-seed demo.
- **Cross-module seam:** Product must read Catalog media only via the contract — Deptrac-enforced;
  media composition lives in the controller layer, not the DTO.
- **Tenancy:** the rendition queue job **writes** tenant-DB rows, so unlike the current DB-free
  `GenerateImageVariants` it must use `BindsTenantContext` + carry `tenantId`.
- **Single-primary** must be a PG **partial unique index** (with `COALESCE` for nullable
  channel/locale), not app logic alone — race-safe under db-per-tenant.
- **Untrusted-file preview** (Stage 4) is a stored-XSS surface — the safe-serving header recipe and
  pdf.js (not native plugin / raw iframe) are mandatory, SVG never previewed inline. Stage 1 keeps an
  **image-only ingestion allow-list** so document/video/external paths cannot enter ahead of that work.

## 8. Sources

Display UX: W3C/WAI Carousels & ARIA APG; web.dev responsive-images & lazy-loading-video;
NN/g list-thumbnails & product-photos-on-listing-pages; react-pdf; hls.js; lite-youtube-embed; TecDoc.
Data model: Shopify product-media & unified files; Akeneo Asset Manager (media_file vs media_link,
locale/channel, transformations); Adobe Commerce image roles; Pimcore DAM; Cloudinary eager vs
on-the-fly; GS1 Product Image Specification; schema.org ImageObject; AWS presigned-vs-CDN.
Attachments: web.dev "Securely hosting user data"; OASIS CMIS; Odoo `ir.attachment`; SAP ArchiveLink;
NetSuite File Cabinet; D365 document management; Mozilla pdf.js range requests.
