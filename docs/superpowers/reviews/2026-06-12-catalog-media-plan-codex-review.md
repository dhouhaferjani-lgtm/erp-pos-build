# Catalog Media Backend Foundation Plan Review

Date: 2026-06-12  
Reviewer: Codex adversarial review  
Plan reviewed: `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md`  
Spec reviewed: `docs/superpowers/specs/2026-06-12-catalog-media-backend-foundation-design.md`  
Prior reviews read in full:
- `docs/superpowers/reviews/2026-06-12-media-subsystem-spec-codex-review.md`
- `docs/superpowers/reviews/2026-06-12-media-subsystem-spec-codex-review-r2.md`
- `docs/superpowers/reviews/2026-06-12-media-subsystem-spec-codex-review-r3.md`

## Grounding Checks

- The route middleware statement in the plan is accurate: product image routes inherit `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim`, and `module:Inventory` from `apps/api/app/Modules/Product/routes.php:42`, with image routes at `apps/api/app/Modules/Product/routes.php:119-142`.
- `BindsTenantContext` really requires a using class to carry `public readonly string $tenantId` and to call `withTenantContext()`; the trait doc states the tenant-id requirement at `apps/api/app/Jobs/Concerns/BindsTenantContext.php:37-39`, wraps at `apps/api/app/Jobs/Concerns/BindsTenantContext.php:62-75`, and requires explicit `where('tenant_id', ...)` on tenant-scoped queries at `apps/api/app/Jobs/Concerns/BindsTenantContext.php:27-35`.
- The current partial-index idiom exists in tenant migrations, e.g. `product_variants_default_unique` at `apps/api/database/migrations/tenant/2026_06_02_100003_create_product_variants_table.php:37-43`.
- The current image resizing code is GD/WebP, not Intervention Image: `imagecreatefromstring`, `imagecopyresampled`, and `imagewebp` are used in `apps/api/app/Modules/Product/Application/Services/ImageVariantService.php:47-83`.
- `PublicProductImageController` uses `is_active_for_ecommerce` as the public access gate at `apps/api/app/Modules/Product/Presentation/Controllers/PublicProductImageController.php:23-35`; `ProductImage::canBeAccessedPublicly()` delegates to the product flag at `apps/api/app/Modules/Product/Domain/ProductImage.php:143-145`.
- `SyncController` currently builds POS image URLs as `/products/{product}/images/{image}/download?variant=sm` at `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:71-78`.
- `ProductImageImportService` exists and currently injects `ProductImageService` at `apps/api/app/Modules/Product/Application/Services/ProductImageImportService.php:16-26`.
- The exact command class is `GenerateProductImageVariants` at `apps/api/app/Console/Commands/GenerateProductImageVariants.php:16`, and it currently imports old image classes at `apps/api/app/Console/Commands/GenerateProductImageVariants.php:7-9`.
- `ProductController::index()` currently uses `formatOffsetPaginatedResponse()` at `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:101`; that formatter maps by calling `ProductData::fromModel($item)` directly at `apps/api/app/Support/Traits/PaginatesResults.php:63-74`.
- POS SQLite image cache is product-url based, not backend-table based: schema at `apps/pos/src/lib/db/migrations.ts:242-250`, cache lookup at `apps/pos/src/lib/images/imageCache.ts:38-42`, and write path at `apps/pos/src/lib/images/imageCache.ts:188-192`. No POS schema change is needed if `SyncController.image_url` remains stable.

## 1. Task Sequencing / Compile Safety

### HIGH - Task 15 final reference check is both too narrow and impossible to pass

Plan task: Task 15, Step 5 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1082-1093`).  
Real file: current historical migration still contains `product_images` at `apps/api/database/migrations/tenant/2025_12_29_155412_create_product_images_table.php:14` and `apps/api/database/migrations/tenant/2025_12_29_155412_create_product_images_table.php:52`.

The plan's grep command searches `app/ database/` for `product_images`, so it will always print `STILL REFERENCED` after Task 15 because historical migrations must remain in `database/migrations/tenant/`, and the new drop migration itself necessarily contains `product_images` (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1073-1077`). At the same time, the command is too narrow: it does not search `tests/`, `packages/shared`, `apps/web`, or `apps/pos`.

Concrete fix: replace the grep step with an `rg` allow-list that excludes known historical/drop migrations and searches all relevant code surfaces:

```bash
rg -n "ProductImage\\b|ProductImageService|ImageVariantService|GenerateImageVariants|primaryImage|product_images" \
  app tests database/seeders packages/shared apps/web apps/pos \
  -g '!database/migrations/tenant/2025_12_29_155412_create_product_images_table.php' \
  -g '!database/migrations/tenant/2026_06_12_100004_drop_product_images_table.php'
```

Then state expected output as no matches except intentional POS local-cache names if those are deliberately retained.

### HIGH - Existing tests that import deleted classes are not assigned to any task or run after deletion

Plan task: Task 15 deletes the old model/services/jobs/controllers at `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1043-1049`, but its final test filter only runs newly named media/product tests at `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1095-1101`.  
Real files:
- `apps/api/tests/Feature/Product/ProductImageControllerTest.php:16` imports `ProductImage`.
- `apps/api/tests/Feature/Product/ProductImageControllerTest.php:116` asserts `product_images`.
- `apps/api/tests/Feature/Product/ProductImageTenantIsolationTest.php:14` imports `ProductImage`.
- `apps/api/tests/Feature/Product/ProductImageTenantIsolationTest.php:306` asserts soft-deleted `product_images`.
- `apps/api/tests/Unit/Modules/Product/Application/Services/ImageVariantServiceTest.php:7` imports `ImageVariantService`.
- `apps/api/tests/Unit/Modules/Product/Application/Jobs/GenerateImageVariantsTest.php:7` imports `GenerateImageVariants`.

The spec's consumer table requires existing tests to be migrated/replaced, and prior review R3 verified that requirement. The plan mentions this in the self-review and spec table, but no concrete task modifies these test files. After Task 15 deletes the classes, these tests will break outside the plan's scoped filter.

Concrete fix: add explicit subtasks before deletion to migrate or delete/replace:
- `apps/api/tests/Feature/Product/ProductImageControllerTest.php`
- `apps/api/tests/Feature/Product/ProductImageTenantIsolationTest.php`
- `apps/api/tests/Unit/Modules/Product/Application/Services/ProductImageServiceTest.php`
- `apps/api/tests/Unit/Modules/Product/Application/Services/ImageVariantServiceTest.php`
- `apps/api/tests/Unit/Modules/Product/Application/Jobs/GenerateImageVariantsTest.php`

Also include those paths in Task 15 verification, not only `Catalog\\Media|ProductDataMediaParity|SyncImageContract|ProductImageFacade`.

### LOW - The plan references a non-existent Task 17

Plan task: File-structure deletion note at `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:53`; risk note at `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1121`.  
Real file: old service exists at `apps/api/app/Modules/Product/Application/Services/ImageVariantService.php:9`.

The plan says `ImageVariantService` is deleted "only after Task 17", but the plan has only 15 tasks. The intended sequencing is Task 8 copies the GD routine, Task 15 deletes the old class.

Concrete fix: change both references to "Task 15" and explicitly state "delete only after Task 8 has copied `resizeAndEncode()` into `ImageRenditionGenerator` and Task 14 no longer imports `GenerateImageVariants`."

## 2. Signature / Type Consistency

### HIGH - `MediaAttachmentData::fromModel()` reintroduces a static DTO service-boundary problem

Plan tasks: Task 5 and Task 7 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:620-653`, `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:789`).  
Real file: the existing problem being avoided is visible in `ProductData::fromModel(Product $product)` at `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:48-89`, which computes route URLs inside a static DTO factory.

The spec correctly moved media composition out of `ProductData` because a static DTO factory is not a DI boundary. The plan repeats the same mistake one layer down: `MediaAttachmentData::fromModel($attachment)` is static but says it resolves URLs via `MediaUrlResolver` (Task 7). That requires service location, a hidden global, or coupling the DTO to routing/storage. Task 6 then calls `MediaAttachmentData::fromModel($a)` in a loop at `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:735-740`.

Concrete fix: inject `MediaUrlResolver` into `CatalogMediaQuery` and make DTO construction pure:

```php
$url = $this->urls->forAttachment($attachment, 'sm');
$dtos[] = MediaAttachmentData::fromModel($attachment, $url);
```

or remove `fromModel()` entirely and instantiate `MediaAttachmentData` inside `CatalogMediaQuery`. Do not call `app()` from DTOs.

### MEDIUM - `MediaAttachment` relation name is not explicit where the repository depends on it

Plan tasks: Task 3 and Task 4 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:496-498`, `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:575-580`).  
Real file: there is no existing `MediaAttachment` model; the current analogous product relation is explicit (`Product::images()` and `Product::primaryImage()`) at `apps/api/app/Modules/Product/Domain/Product.php:292-305`.

Task 4's repository uses `whereHas('mediaAsset')` and `with(['mediaAsset.renditions'])`, but Task 3 only says "`belongsTo(MediaAsset::class, 'media_asset_id')`" and then adds "if you prefer it over `asset()`" in Task 4. This is exactly the kind of relation-name drift the review prompt calls out.

Concrete fix: Task 3 must define the relation name explicitly:

```php
/** @return BelongsTo<MediaAsset, $this> */
public function mediaAsset(): BelongsTo
{
    return $this->belongsTo(MediaAsset::class, 'media_asset_id');
}
```

Then use only `mediaAsset` in Tasks 4, 5, 6, 13, and 14.

### MEDIUM - `CatalogMediaQueryInterface` signature is consistent in Task 6/13, but Task 14 omits the tenant argument at the POS call site

Plan tasks: Task 6 and Task 14 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:717-723`, `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1037`).  
Real file: current `SyncController` only pulls `$companyId` via `CompanyContext::getCompanyId()` and eager-loads `primaryImage` at `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:54-65`.

The final contract is `forProduct(string $productId, string $tenantId)` / `forProducts(array $productIds, string $tenantId)`. Task 13 correctly says `show/store/update` call `forProduct($id, $tenantId)`, but Task 14 only says "resolve the primary image URL through `CatalogMediaQueryInterface`" and does not show how POS obtains/passes `tenantId`.

Concrete fix: in Task 14, require `SyncController` to call `requireCompany()` or otherwise obtain the current tenant, batch the product IDs, and call:

```php
$mediaByProduct = $this->catalogMedia->forProducts(
    $products->pluck('id')->all(),
    $company->tenant_id,
);
```

Do not call `forProduct()` inside the `map()` loop.

### MEDIUM - Upload lifecycle status contradicts the spec

Plan task: Task 10 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:916-925`, `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:937`).  
Spec: `MediaUploadService` creates the asset `UPLOADED` and dispatches `GenerateRenditions` at `docs/superpowers/specs/2026-06-12-catalog-media-backend-foundation-design.md:167-170`; the job handles `PROCESSING` to `READY`/`FAILED` at `docs/superpowers/specs/2026-06-12-catalog-media-backend-foundation-design.md:171-174`.

Task 10's failing test asserts `MediaStatus::Processing` immediately after upload, and the implementation prose says create `MediaAsset` with `status=Processing`. That skips the `UPLOADED` state the spec says exists.

Concrete fix: either update the spec to remove `UPLOADED`, or better, change Task 10 so upload creates `UPLOADED`, dispatches the job, and Task 9 marks `PROCESSING` before generating renditions.

## 3. Codebase Accuracy

### MEDIUM - The Catalog service-provider path/glob is inaccurate

Plan tasks: file structure and Task 4 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:42`, `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:592-609`).  
Real file: the provider is `apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php:24-33`, not `app/Modules/Catalog/CatalogServiceProvider.php`.

The plan says `app/Modules/Catalog/CatalogServiceProvider.php`, and the commit command uses `app/Modules/Catalog/*ServiceProvider.php`, which will not match the actual provider under `Providers/`.

Concrete fix: use the exact path `app/Modules/Catalog/Providers/CatalogServiceProvider.php` throughout, and make the commit command add that path directly.

### LOW - Public façade wording names a method that disappears with `ProductImage`

Plan task: Task 12 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:982-984`).  
Real file: `canBeAccessedPublicly()` exists only on the old `ProductImage` model at `apps/api/app/Modules/Product/Domain/ProductImage.php:143-145`; the public controller directly checks `is_active_for_ecommerce` at `apps/api/app/Modules/Product/Presentation/Controllers/PublicProductImageController.php:23-35`.

Task 12 says to mirror `canBeAccessedPublicly()` even though Task 15 deletes `ProductImage`. The real invariant is the product's `is_active_for_ecommerce` field.

Concrete fix: change Task 12 to say "preserve the `is_active_for_ecommerce` gate; do not carry `canBeAccessedPublicly()` forward unless a new helper is deliberately added to the media façade."

## 4. DB-Per-Tenant

### HIGH - Repository eager loads are not fully tenant-scoped

Plan task: Task 4 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:575-580`).  
Real policy: `BindsTenantContext` requires explicit `where('tenant_id', $this->tenantId)` on every query against tenant-scoped tables at `apps/api/app/Jobs/Concerns/BindsTenantContext.php:27-35`. The spec states all three new tables carry `tenant_id` at `docs/superpowers/specs/2026-06-12-catalog-media-backend-foundation-design.md:35-38`.

`EloquentMediaAttachmentRepository::forOwners()` scopes `media_attachments.tenant_id`, but its `whereHas('mediaAsset')` checks only `status`, and `with(['mediaAsset.renditions'])` does not explicitly constrain `media_assets.tenant_id` or `media_renditions.tenant_id`. In a db-per-tenant world this is usually protected by the physical database, but the plan's own defense-in-depth rule says every tenant-scoped table query must carry tenant filtering.

Concrete fix:

```php
$rows = MediaAttachment::query()
    ->where('tenant_id', $tenantId)
    ->where('owner_type', $type)
    ->whereIn('owner_id', $ownerIds)
    ->whereHas('mediaAsset', fn ($q) => $q
        ->where('tenant_id', $tenantId)
        ->where('status', MediaStatus::Ready))
    ->with([
        'mediaAsset' => fn ($q) => $q->where('tenant_id', $tenantId),
        'mediaAsset.renditions' => fn ($q) => $q->where('tenant_id', $tenantId),
    ])
    ->orderBy('sort_order')
    ->get();
```

Also require `MediaAssetRepositoryInterface::find($id, $tenantId)` to use both `id` and `tenant_id`.

### MEDIUM - The `GenerateRenditions` tenancy test does not prove explicit tenant scoping

Plan task: Task 9 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:843-856`).  
Real pattern: `ProcessProductImageImport` carries tenant id and explicitly scopes its `ImportJob` lookup at `apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php:53-72`.

Task 9's test verifies that a job can mark an asset ready and uses `MediaAsset::withoutGlobalScopes()->find($asset->id)` afterward. It does not prove that the repository lookup inside the job used `where('tenant_id', $tenantId)`, nor that rendition writes carry `tenant_id`.

Concrete fix: add a structural or query-log assertion similar to the existing scheduled-job tenant tests. At minimum, seed two assets with different `tenant_id` values, run the job for tenant A, and assert tenant B's asset/renditions are untouched. Prefer a SQL-log assertion that the asset SELECT includes `tenant_id`.

## 5. TDD Quality

### HIGH - The façade test omits update/delete and the existing tenant-isolation regressions

Plan task: Task 11 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:953-969`).  
Real files:
- Current controller supports update at `apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php:72-90`.
- Current controller supports destroy at `apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php:96-103`.
- Current tenant-isolation tests cover mixed-id and cross-company cases at `apps/api/tests/Feature/Product/ProductImageTenantIsolationTest.php:137-241`.

Task 11's "parity" test covers index, download, store, and reorder, but not PATCH primary/sort updates, DELETE, 422 validation for foreign image IDs, or cross-company/cross-tenant authorization regressions. Those were the hard-won failure modes in the old controller tests.

Concrete fix: expand Task 11 or add a separate task to port `ProductImageTenantIsolationTest` to attachment IDs. Required assertions: index scoped by company/tenant, update/destroy/download reject mixed IDs, reorder rejects attachment IDs outside the product, delete promotes the next primary link, and update sets `PRIMARY`.

### MEDIUM - The no-N+1 assertion does not exercise the controller/formatter path that can regress

Plan tasks: Task 6 and Task 13 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:683-709`, `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1011`).  
Real files: `ProductController::index()` delegates DTO mapping to `formatOffsetPaginatedResponse()` at `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:97-101`; the formatter currently calls `ProductData::fromModel($item)` with no media argument at `apps/api/app/Support/Traits/PaginatesResults.php:70-74`.

Task 6 proves `CatalogMediaQuery::forProducts()` can batch. It does not prove `ProductController::index()` actually calls it once or that the formatter change works after `ProductData::fromModel(Product, ProductMediaData)` becomes mandatory.

Concrete fix: Task 13 needs a controller-level test. Either mock/fake `CatalogMediaQueryInterface` and assert `forProducts()` is called once for the paginated page, or enable DB query logging around `GET /api/v1/products` with multiple products and assert media queries remain constant.

### MEDIUM - Product-image ZIP import lacks a concrete behavior test

Plan task: Task 14 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1017-1039`).  
Real files:
- ZIP import upload path exists at `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:78-105` and `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:536-561`.
- `ProductImageImportService` returns `image_id` from the old `ProductImageService::upload()` at `apps/api/app/Modules/Product/Application/Services/ProductImageImportService.php:236-244`.
- I found no existing `apps/api/tests/**/ProductImageImportServiceTest.php`; the only direct test coverage is scheduled-job structural coverage around `ProcessProductImageImport`.

Task 14 says "(+ adjust the import service test)" but no such test file exists. Without a new behavior test, it is easy to leave `image_id` semantics ambiguous after switching to assets/attachments.

Concrete fix: create `tests/Feature/Modules/Catalog/Media/ProductImageImportServiceMediaTest.php` or similar. It should process a ZIP with one SKU-matched image and assert one `media_assets` row, one `media_attachments` row with `role=PRIMARY`, and the returned result's `image_id` is the façade attachment id, not the asset id.

## 6. Spec Coverage / Missed Consumers

### MEDIUM - Generated TypeScript is run, but the new `ProductData.media` type is not asserted

Plan task: Task 13 and Task 15 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1011`, `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1091`).  
Real file: current generated `ProductData` has no `media` field at `packages/shared/types/generated.d.ts:1400-1426`.

The plan adds `public array $media = []` to `ProductData`, but does not specify the constructor docblock/type needed for `spatie/typescript-transformer` to emit `Array<MediaAttachmentData>` rather than `Array<any>`.

Concrete fix: in Task 13, require a constructor docblock for the new field:

```php
/** @param array<int, \App\Modules\Catalog\Application\DTOs\MediaAttachmentData> $media */
```

After `php artisan typescript:transform`, add a verification grep for `media:` under `ProductData` in `packages/shared/types/generated.d.ts`.

### LOW - POS cache coverage is correctly scoped, but the plan should name the verified cache invariant

Plan task: Task 14 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1025-1037`).  
Real files: POS cache table is `product_id`, `remote_url`, `local_path`, `etag`, `downloaded_at` at `apps/pos/src/lib/db/migrations.ts:242-250`; `useProductImage()` keys by `productId` and `remoteUrl` at `apps/pos/src/lib/images/useProductImage.ts:11-27`.

No issue with the plan's "POS untouched if URL contract is preserved" conclusion. The missing detail is an explicit assertion that the POS sync payload keeps one `image_url` per product and does not switch to a media array.

Concrete fix: in `SyncImageContractTest`, assert the payload field remains `product.image_url` and that it is either `null`, an external URL, or a `/products/{product}/images/{attachment}/download?variant=sm` URL.

## 7. Security / Over-Under-Engineering

### MEDIUM - External URL validation is specified but not tied to a tested service path

Plan tasks: Task 7, Task 10, Task 15 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:770-789`, `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:912-937`, `docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:1066`).  
Spec: external URLs must be https, length-checked, and basic SSRF-safe at `docs/superpowers/specs/2026-06-12-catalog-media-backend-foundation-design.md:175-176`.

The plan has tests for redirecting URL-disk assets and for seeding EXTERNAL_URL placeholders, but no failing test for rejecting invalid external URLs. Since Stage 1 creates `MediaAsset` rows with `source=EXTERNAL_URL`, validation should be in a single creation path rather than scattered in seeders/tests.

Concrete fix: add a `MediaUploadService::registerExternalUrlForProduct(...)` or `MediaAssetRepository::createExternalUrl(...)` path used by the seeder, and test rejection of `http://`, localhost/private IP literals, over-2048 URLs, and non-URL strings. If external URL creation is deliberately seeder-only in Stage 1, state that and validate in the seeder helper.

### LOW - Image-only MIME enforcement is correct in prose, but request/service examples should use the exact current allow-list

Plan task: Task 10 (`docs/superpowers/plans/2026-06-12-catalog-media-backend-foundation-plan.md:916-937`).  
Real file: current service allow-list is `['image/jpeg', 'image/png', 'image/webp', 'image/gif']` at `apps/api/app/Modules/Product/Application/Services/ProductImageService.php:20-27`, and current request validation uses `image|mimes:jpeg,png,webp,gif|max:5120` at `apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php:55-57`.

The plan prose says `image/jpeg,png,webp,gif`, which is shorthand, but the implementation should carry both exact layers: Laravel request rule and service MIME array. This is not a blocker because the security intent is present.

Concrete fix: Task 10 should show the exact `UploadMediaRequest` rules and the exact service allow-list constant.

## Verdict

VERDICT: REJECT

Confidence: 88%

Counts: 0 BLOCKERs, 5 HIGH, 8 MEDIUM, 4 LOW
