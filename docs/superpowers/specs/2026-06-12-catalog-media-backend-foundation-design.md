# Stage 1 — Catalog media backend foundation (spec)

> Sub-project 1 of the [media subsystem program](2026-06-12-media-subsystem-architecture-design.md).
> Backend-only. Replaces the image-only `product_images` with a PIM-grade **asset library +
> renditions + link** model in the **`Catalog`** module (hexagonal), **re-seeding** demo data (no
> backfill — owner-confirmed there is no production data). Keeps current image behavior (thumbnail +
> optimized rendition) and preserves the existing **and public** image API surface so the working
> gallery + storefront keep functioning. Type-first design so PDF/video/360 are additive later.
>
> **Revised 2026-06-12 after an adversarial Codex review** (`docs/superpowers/reviews/
> 2026-06-12-media-subsystem-spec-codex-review.md`, verdict REJECT → addressed). Changes: module
> home Media→Catalog; full consumer enumeration; partial-unique-index for single-primary; tenant-aware
> rendition job; contract seam moved out of the DTO; façade ID contract defined; public route +
> import + POS-sync + POS-cache covered; rendition uniqueness fixed; owner-type/channel scope clarified.
>
> Constraints (owner [LOCKED]): hexagonal Domain/Application/Infrastructure/Presentation; separation
> of concerns; enums for all type/status columns (Rule 9); constructor injection only (Rule 13); no
> `mixed` / DTOs for structured data; strict typing; TDD; PHPStan level 8; Pint; Deptrac.
> **No full PHPUnit suite** — scope every run with `--filter` / a path.

---

## 1. Goal & non-goals

**Goal:** stand up the catalog-media data model + ingestion/serve/rendition pipeline that the
frontend stages build on, with image support **at full parity to today across every existing
consumer**, and the `type`/`source`/`role` seams in place for future media types and reuse.

**Non-goals (this stage):** any frontend rendering change; PDF/video/360 rendition logic;
channel/locale **resolution** (columns only, not acceptance criteria); document-attachment changes;
category/variant media **wiring** (owner-type enum exists; only `PRODUCT` is delivered — see §8);
CDN/presigned hardening beyond what exists. The existing web gallery + public storefront image API
must keep working unchanged against a preserved façade.

## 2. Data model (PostgreSQL, tenant DB)

New tables (UUID PKs, `tenant_id` on **all three** for defense-in-depth + query-test ergonomics;
timestamps; soft-deletes on `media_assets`). Migrations under `apps/api/database/migrations/tenant/`.

### `media_assets` — binary + intrinsic metadata
`id`, `tenant_id` (idx), `type` (enum `MediaAssetType`: IMAGE | DOCUMENT | VIDEO | EXTERNAL_VIDEO |
SPIN_360), `source` (enum `MediaSource`: UPLOAD | EXTERNAL_URL), `status` (enum `MediaStatus`:
UPLOADED | PROCESSING | READY | FAILED), `storage_disk` (`s3`|`public`|`url`), `storage_path` (null),
`external_url` (varchar 2048, null), `original_filename` (null), `mime_type` (null), `file_size`
(bigint null), `checksum` (sha256, null), `width`/`height` (null), `duration_ms` (null, video),
`frame_count` (null, 360), `title` (null), `uploaded_by` (null), timestamps + `deleted_at`.
Indexes: (`tenant_id`,`type`), (`checksum`).

### `media_renditions` — pre-generated derivatives
`id`, `tenant_id`, `media_asset_id` (FK → media_assets, cascade), `name` (enum `RenditionName`:
THUMBNAIL | SMALL | WEB | ZOOM), `format` (enum `RenditionFormat`: WEBP | JPEG), `storage_disk`,
`storage_path`, `width`, `height`, `file_size`, timestamps.
**Unique (`media_asset_id`,`name`,`format`)** — includes `format` so a future JPEG fallback can
coexist with WebP for the same name. None generated for `source=EXTERNAL_URL`.

> **Rendition scope (Stage 1):** match current behavior — WebP only, names `THUMBNAIL`(~150) and
> `SMALL`(~400) (today's `sm`/`md`), plus `WEB`(~1000) for the detail viewer; original kept as the
> master (`ZOOM` references the original). **JPEG fallback is explicitly out of scope this stage**
> (current `ImageVariantService` is WebP-only); the `format` enum + unique key leave the door open
> without a reshape. Exact pixel targets confirmed in TDD; callers reference *names*, not pixels.

### `media_attachments` — link with contextual metadata
`id`, `tenant_id` (idx), `media_asset_id` (FK cascade), `owner_type` (enum `MediaOwnerType`: PRODUCT
| PRODUCT_VARIANT | CATEGORY), `owner_id`, `role` (enum `MediaRole`: PRIMARY | GALLERY | DATASHEET |
MANUAL | VIDEO_POSTER | SPIN | SWATCH), `sort_order` (smallint), `channel` (null), `locale` (null),
`alt` (null), `caption` (null), timestamps. Index (`tenant_id`,`owner_type`,`owner_id`,`sort_order`).

**Single-primary invariant — DB-enforced (not app-only):** a PostgreSQL **partial unique index**
guarantees at most one `PRIMARY` per owner per channel/locale, race-safe under db-per-tenant
(mirrors the existing default-variant partial index `2026_06_02_100003_create_product_variants_table`):

```sql
CREATE UNIQUE INDEX media_attachments_one_primary
  ON media_attachments (owner_type, owner_id, COALESCE(channel,''), COALESCE(locale,''))
  WHERE role = 'PRIMARY';   -- COALESCE: NULL channel/locale = the "global" slot
```

The service still demotes the prior primary on promote (for good UX), but correctness rests on the
index. (`NULLS NOT DISTINCT` is the PG15+ alternative; `COALESCE` is chosen for PG-version safety.)
**`media_attachments` has NO `deleted_at`** — links are **hard-deleted** (the asset is the durable,
soft-deletable entity; a link is just a placement). The index therefore has no `deleted_at` predicate.
(Codex r2 P1 — earlier draft referenced a non-existent column.)

### Drop `product_images` + remove `ProductImage`
A tenant migration drops `product_images` after a **pre-drop row-count guard** (logs discarded count;
owner-confirmed no production data). The `ProductImage` model and `Product::images()/primaryImage()`
relations are removed; all references are re-pointed (see §5 consumer table).

## 3. Hexagonal layout (Catalog module)

```
app/Modules/Catalog/
  Domain/
    Media/{MediaAsset.php, MediaRendition.php, MediaAttachment.php}
    Enums/{MediaAssetType, MediaSource, MediaStatus, RenditionName, RenditionFormat,
           MediaRole, MediaOwnerType}.php
    Contracts/{MediaAssetRepositoryInterface, MediaAttachmentRepositoryInterface,
               RenditionGeneratorInterface, MediaStorageInterface}.php
  Application/
    Services/{MediaUploadService, MediaAttachmentService, RenditionService, MediaServeService}.php
    DTOs/{MediaAssetData, MediaRenditionData, MediaAttachmentData, ProductMediaData}.php
    Jobs/GenerateRenditions.php          (BindsTenantContext, see §6)
    Queries/CatalogMediaQuery.php        (implements Shared contract, §4)
  Infrastructure/
    Persistence/Eloquent{MediaAsset,MediaAttachment}Repository.php
    Storage/MediaStorageAdapter.php      (wraps Storage::disk; disk-aware serve, §7)
    Rendition/ImageRenditionGenerator.php  (reuses current resizing lib; WebP)
  Presentation/
    Controllers/{ProductMediaController, PublicProductMediaController, MediaServeController}.php
    Requests/{UploadMediaRequest, AttachMediaRequest, ReorderMediaRequest}.php
```

Constructor injection only (bind interfaces in the Catalog service provider; no `app()`). DTOs for
every structured payload; no `mixed`. The asset library is internal to Catalog; Product reaches it
only via §4.

## 4. Cross-module seam (Product → Catalog) — composition in the controller, not the DTO

`ProductData::fromModel(Product $product)` is a **static factory and not a DI boundary** — it cannot
"consume" a service. So media composition moves up a layer:

```php
// Shared/Contracts/
interface CatalogMediaQueryInterface {
    public function forProduct(string $productId, string $tenantId): ProductMediaData;   // primary + gallery, READY only
    /** @param array<string> $ids @return array<string,ProductMediaData> keyed by product_id (no N+1) */
    public function forProducts(array $ids, string $tenantId): array;                     // tenantId required (db-per-tenant defense-in-depth)
}
```

- `CatalogMediaQuery` (Catalog/Application) implements it; bound in `Shared/Contracts`.
- **`ProductController`** constructor-injects the interface. `index()` calls `forProducts()` once for
  the page (no N+1, asserted by a query-count test) and `show/store/update` call `forProduct()`;
  each passes a `ProductMediaData` into a new `ProductData::fromModel($product, ProductMediaData $media)`
  parameter (replacing the `relationLoaded('primaryImage')` branch).
- `ProductData.primary_image_url` keeps its **exact current shape/semantics** (resolve PRIMARY →
  rendition URL for UPLOAD, or `external_url` for EXTERNAL_URL; null when none) — now sourced from
  the new model. A new `media: MediaAttachmentData[]` field is added (unused until Stage 3; harmless).
- The `with(['primaryImage'])` eager-loads in `ProductController` index/show are removed.

## 5. Consumers to migrate (verified — Codex review)

`product_images` / `ProductImage` / `ProductImageService` have **more consumers than the gallery**.
Stage 1 is not done until each is re-pointed at the new model or the preserved façade:

| Consumer | File | Stage 1 treatment |
|---|---|---|
| Auth image CRUD | `Product/Presentation/Controllers/ProductImageController.php` | Re-point to `ProductMediaController` over new model; **preserve routes** `/products/{product}/images[...]` |
| **Public e-commerce** image API | `Product/.../PublicProductImageController.php`, `routes.php:140-147` | Re-point to `PublicProductMediaController`; preserve `/api/v1/public/products/{product}/images` shape + `is_active_for_ecommerce` guard |
| Serve/download | `ProductImageController::download` → `ProductImageService::serve` | `MediaServeService`; preserve `?variant=sm|md` aliases → THUMBNAIL/SMALL |
| **POS sync** image URLs | `POS/Presentation/Controllers/SyncController.php:62-83` | Resolve primary image URL via `CatalogMediaQuery`; same URL contract (`variant=sm`) |
| **ZIP image import** | `Import/.../ImportController.php`, `Import/Application/Jobs/ProcessProductImageImport.php`, `Product/Application/Services/ProductImageImportService.php`, `Import/Domain/Enums/ImportType.php` | Re-point `ProductImageImportService` onto `MediaUploadService`; keep `ImportType` value + job (already `BindsTenantContext`) |
| Variant-gen command | `Console/Commands/GenerateProductImageVariants.php` | Re-point to regenerate `media_renditions`; keep command name/signature |
| Domain model + relations | `Product/Domain/ProductImage.php`, `Product/Domain/Product.php:287-305` | Remove model; replace `images()/primaryImage()` usage with the query seam |
| ProductData | `Product/Application/DTOs/ProductData.php:73-81` | §4 (composition moved to controller) |
| Generated TS types | `packages/shared/types/generated.d.ts` | Re-run `php artisan typescript:transform` after DTO changes (Rule 7) |
| **POS SQLite image cache** | `apps/pos/src/lib/images/{imageCache.ts,useProductImage.ts}`, `apps/pos/src/lib/db/migrations.ts`, `ProductCard.tsx` | **No change needed if** the POS sync URL contract is preserved; verify against the cache key + confirm no schema assumption on `product_images`. Flag if the contract shifts. |
| **Demo seeder** | `apps/api/database/seeders/ProductImagePlaceholderSeeder.php` (+ any product-image seeding in demo/coffee-shop/parapharmacy seeders) | Rewrite to create `media_assets` (EXTERNAL_URL) + PRIMARY `media_attachments` — see §6. |
| **Existing tests** | `apps/api/tests/Feature/Product/ProductImageControllerTest.php`, `ProductImageTenantIsolationTest.php`, unit tests under `apps/api/tests/Unit/Modules/Product/Application/*` | Migrate/replace to target the new model + façade; keep tenant-isolation coverage. These must stay green (scoped `--filter`). |

> The POS app is a separate Tauri client; Stage 1's obligation is to **keep the sync image-URL
> contract identical** so the POS cache keeps working untouched. Any URL-shape change is a breaking
> change that must be called out and coordinated (cross-app deprecation discipline).

## 6. Ingestion, serve & tenancy

- **Upload → register → process(queued) → READY** lifecycle. `MediaUploadService` (image-only
  allow-list this stage — request + service MIME validation, parity with current
  jpeg/png/webp/gif; non-image types are model-supported but **rejected at ingestion** until later
  stages), computes `checksum`, creates the asset `UPLOADED`, dispatches `GenerateRenditions`.
- **`GenerateRenditions` is tenant-aware** (Codex BLOCKER): unlike the current DB-free
  `GenerateImageVariants`, it **writes** `media_renditions`, so it carries `tenantId`, uses
  `App\Jobs\Concerns\BindsTenantContext`, and tenant-scopes every query — pattern already used by
  `ProcessProductImageImport`. It sets `PROCESSING`→`READY`/`FAILED`.
- **External-URL assets**: created `source=EXTERNAL_URL`, `status=READY` immediately, **no
  renditions**; `external_url` validated (https scheme, length, basic SSRF-safe display-only use).
- **Serve (`MediaServeService` / `MediaStorageAdapter`)** is disk-aware, preserving today's
  semantics: `s3`/`public` → `Storage::disk($disk)->response($path)`; `url` → redirect to
  `external_url`; `?variant=sm|md` aliases → THUMBNAIL/SMALL, unknown → original.
- **Storage tenancy (defined):** uploaded assets use `s3` with path
  `products/{tenant_id}/{product_id}/{asset_uuid}/{original|rendition}.{ext}` (asset-UUID keys give
  cache-busting + clean deletes). Note Stancl suffixes `local`/`public` but **not `s3`** (`config/
  tenancy.php`), so the tenant segment is **explicit in the path** for `s3` (as the current code
  already does) — not via Stancl suffixing. `public`-disk assets honor Stancl suffixing; `url` assets
  have no stored bytes.
- **Soft-delete / reuse policy (defined):** deleting a **link** (`media_attachments`) is a **hard
  delete** (no `deleted_at` on the table) and never deletes the asset — if it was PRIMARY, the
  service promotes the next by `sort_order`. Deleting an **asset**
  is allowed only when it has no remaining links; it soft-deletes the asset, deletes renditions, and
  (after commit) removes stored original + rendition files (mirrors current `ProductImageService::
  delete()` semantics, generalized for the link layer). Stage 1 product media is effectively 1:1, but
  the policy is reuse-correct from day one.

## 7. API surface (preserve façade, define the ID contract)

**Preserved (frontend gallery + storefront unaffected):**
- `GET /products/{product}/images` — list (reads `media_attachments` for the product, image types).
- `GET /products/{product}/images/{image}/download?variant=sm|md` — serve.
- `POST/PATCH/DELETE /products/{product}/images[/{image}]`, reorder — **preserve the existing route
  group exactly**: the full inherited middleware is `['api','auth:sanctum',SetPermissionsTeam,
  EnforceTokenTenantClaim,'module:Inventory']` plus per-route `can:products.*` and `throttle:
  image-upload` (`Product/routes.php:42`,`119+`). Do not enumerate a partial set — reuse the group.
- `GET /api/v1/public/products/{product}/images[/{image}]` — public storefront shape preserved.

**Façade `{image}` ID contract [DECIDED]:** the legacy `{image}` path segment maps to a
**`media_attachments.id`** (the per-owner link), **not** `media_assets.id`. Rationale: the old
`product_images.id` was a per-product identity; the attachment row is its exact successor, keeps
product-scoping unambiguous under asset reuse, and the list endpoint returns attachment IDs that the
download/update/delete calls echo back (matching today's `image.id` round-trip in
`apps/web/.../productImages.ts`). The façade is a **temporary adapter**; retirement (a richer
`/products/{id}/media` endpoint replacing the `images` alias) is **Stage 3, with a removal criterion:
the `images` routes are deleted once all callers — web gallery, POS sync, public storefront — consume
`/media`.**

## 8. Owner-type scope (resolve the inconsistency)

The `MediaOwnerType` enum keeps `PRODUCT | PRODUCT_VARIANT | CATEGORY` for model extensibility, but
**Stage 1 creates, serves, seeds, and tests only `PRODUCT` attachments.** All other existing
catalog image strings are left **exactly as-is** this stage and migrate to the asset model in
**Stage 3** (each with its own API/seeding/query behavior): category `image_path`
(`Category` / `CategoryData`), variant `image_url` (`Catalog\ProductVariant` / `ProductVariantData`),
**composite-item `image_url`** (`Catalog\CompositeItem`), and **product-attribute-value `image_url`**
(`Catalog\ProductAttributeValue`). No half-built non-product media in Stage 1.

## 9. Testing (TDD, scoped `--filter`)

Backend (PHPUnit, `RefreshDatabase`, real models, seeded permissions via `RolesAndPermissionsSeeder`):

1. **Model/enum** — assets/renditions/attachments persist; enum casts; `media_renditions` carries
   `tenant_id`.
2. **Single-primary partial unique index** — a second PRIMARY for the same (owner, global slot)
   **raises a DB unique violation**; distinct channel/locale slots coexist; the service demotes the
   prior primary on promote. (Run on **real PG** — SQLite cannot validate partial/expression indexes;
   gate this test PG-only, per the migration-drop PG-vs-SQLite lesson.)
3. **Upload service** — image → `UPLOADED`→dispatch `GenerateRenditions`; **non-image MIME rejected**
   (request + service); checksum computed.
4. **`GenerateRenditions` tenancy** — job carries `tenantId`, binds tenant context, writes THUMBNAIL/
   SMALL/WEB (WebP) rows, sets `READY`; EXTERNAL_URL asset gets **no** renditions, `READY` immediately.
5. **Serve** — disk-aware: `s3`/`public` stream rendition; `url` redirects to `external_url`;
   `sm`/`md` aliases resolve; unknown variant → original.
6. **Contract query** — `forProduct/forProducts` returns primary + gallery (READY only); **no N+1**
   (assert query count for a batched page).
7. **ProductData parity** — `primary_image_url` identical-shaped for UPLOAD (rendition URL) and
   EXTERNAL_URL (raw URL); null when none; populated on index + show + store + update.
8. **Façade** — auth `images` index/download/store/reorder behave as before; `{image}` resolves an
   attachment id; company-scoped authz unchanged.
9. **Public façade** — `/api/v1/public/products/{product}/images` returns the preserved shape, guarded
   by `is_active_for_ecommerce`.
10. **POS sync contract** — `SyncController` emits the same primary-image URL shape (`variant=sm`) as
    before (regression-guards the POS cache).
11. **Import** — `ProductImageImportService` over `MediaUploadService` ingests a ZIP entry into an
    asset + PRIMARY attachment.
12. **Seeder** — re-seeding yields READY EXTERNAL_URL primaries for demo products; re-runnable.

Quality gates (scoped): `composer test -- --filter=<...>`; **the PG-only partial-index test** runs in
the real-PG lane (workflow_dispatch / dev→main gate), not the SQLite PR lane. `./vendor/bin/phpstan`
(L8, new code), `./vendor/bin/pint`, **Deptrac** (Product → Catalog only via the contract;
`apps/pos` untouched). Re-run `php artisan typescript:transform` (Rule 7).

## 10. Definition of done

- New tables + enums live; `product_images` dropped (post-guard); `ProductImage` model/relations
  removed; **every §5 consumer** re-pointed (auth + public + POS sync + import + command + types) with
  parity; POS cache unaffected (URL contract preserved).
- Upload → rendition → serve at image parity (WebP THUMBNAIL/SMALL/WEB); EXTERNAL_URL rendition-free.
- Single-primary enforced by the partial unique index (PG test green).
- `GenerateRenditions` tenant-aware; `ProductData.primary_image_url` parity on all paths; `media[]`
  present; composition in the controller, not the DTO.
- Product reads Catalog media only through the contract (Deptrac green). Only `PRODUCT` owner-type
  delivered; category/variant strings untouched.
- Scoped PHPUnit + PG-only index test + PHPStan L8 + Pint + Deptrac green. No frontend rendering
  changes. No full-suite run.

## 11. Open questions for the plan

- Exact rendition pixel targets (confirm THUMBNAIL/SMALL match current `sm`/`md`; pick WEB).
- Confirm the current resizing library used by `ImageVariantService`; reuse it in
  `ImageRenditionGenerator`.
- Whether `ProductData.media[]` ships now (leaning: yes, unused) or defers to Stage 3.
- Exact migration ordering to keep the app compiling while `product_images`/`ProductImage` are
  removed (introduce new model + façade, switch consumers, then drop) — sequence belongs in the plan.
