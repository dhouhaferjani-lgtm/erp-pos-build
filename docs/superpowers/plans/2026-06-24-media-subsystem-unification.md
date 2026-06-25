# Media Subsystem Unification — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing `media_assets` engine a generic, owner-agnostic, org-wide media subsystem; migrate document attachments onto it; retire the legacy local-disk `DocumentAttachment` stack; and physically promote the engine out of `Catalog` into the `Media` module.

**Architecture:** The engine (`MediaAsset`/`MediaAttachment`/`MediaRendition` + services) is already owner-type-agnostic at its core (only the upload path/signature and one POS URL helper are product-shaped). We (1) generalize the upload path + expose an owner-agnostic write/read seam in `Shared/Contracts`, (2) migrate the `documents/{document}/attachments` consumer onto that seam while preserving its exact HTTP/JSON contract, (3) drop the legacy table + code (no production data — owner approved discard), and (4) move the now-generic engine `Catalog → Media` as a pure refactor. Cross-module consumers depend only on `Shared/Contracts\MediaServiceInterface`, so the Phase-4 namespace move is invisible to them.

**Tech Stack:** Laravel 12, PHP 8.2 strict types, hexagonal modules, PostgreSQL 16 db-per-tenant (Stancl), MinIO via the `s3` disk, PHPUnit, PHPStan level 8, Pint.

## Decisions locked (owner, 2026-06-24)

- **Module home:** reuse the existing `Media` module as the promoted home (engine extracted out of Catalog; product-specific façade controllers stay in Catalog/Product).
- **Existing data:** NOT in production → **discard** all existing `document_attachments` rows/files. No backfill, no local→MinIO byte copy, unconditional table drop.
- **Frontend:** **backend-only** — the existing React components (`ProductImageUpload`, `DocumentAttachments`) stay; their endpoints keep their exact request/response contracts. No shared `<MediaUpload>` in this effort.
- **Supplier invoices:** redirected to this system now (`docs/superpowers/coordination/2026-06-24-supplier-invoice-media-redirect.md`); `MediaOwnerType::Document` + the rewired documents-attachments endpoint must land in Phase 2 to unblock them.

## Review revisions (r1 — Codex review 2026-06-24, BINDING amendments)

> Source: `docs/superpowers/reviews/2026-06-24-media-unification-plan-codex-review.md` (verdict NEEDS-REVISION: 2 BLOCKER, 3 HIGH, 3 MED — all verified valid). The items below **override** the original task bodies where they conflict. Execute tasks WITH these amendments.

**R-B1 (BLOCKER — non-image uploads never become READY → vanish from the list).** The read repo `EloquentMediaAttachmentRepository::forOwners()` returns READY-only (`…:33-36`); only `GenerateRenditions` promotes `Uploaded→Ready`, and we skip that job for non-images. **Fix in Task 1.2:** the generic `upload()` MUST create non-image assets with `status = MediaStatus::Ready` **inside the upload transaction** (images stay `Uploaded` and are promoted by the rendition job, unchanged). Add tests: (a) a `MediaAssetType::Document` upload has `status === Ready`; (b) after `MediaService::attachUpload(...Document...)`, `listForOwner(...)` returns it; (c) Task 2.2 `store` then `index` returns the new attachment (proves end-to-end visibility).

**R-B2 (BLOCKER — Shared seam must not reference Catalog types).** Resolve by **moving the media/rendition enums to `Media` FIRST** — new **Task 1.0** (below), executed before Task 1.1. After Task 1.0 the enums live at `App\Modules\Media\Domain\Enums\*`; the Shared contract (Task 1.3), `MediaService`, and the document consumer import them from `Media`, never from `Catalog`. The Catalog-resident `MediaService` impl (until Phase 4) depends on `Media` enums (Catalog→Media is the correct consumer→provider direction). **Add an architecture test** (`tests/Feature/Architecture/MediaBoundaryTest.php`): assert `grep -rl 'App\\Modules\\Catalog\\Domain\\Enums\\(Media|Rendition)' app/Modules/Media app/Modules/Document` returns nothing after Task 1.0, and that `app/Shared/Contracts/MediaServiceInterface.php` contains no `App\Modules\Catalog` import.

**R-H1 (HIGH — preserve legacy download headers).** Legacy uses `Storage::download($path, $original_filename, ['Content-Type'=>$mime])` (`AttachmentService.php:93-105`); `MediaStorageAdapter::serve()` uses `response($path)` (inline, no filename) (`…:48,78`). **Fix:** add `download(string $disk, string $path, string $filename, string $mimeType): StreamedResponse` to `MediaStorageInterface` (impl: `Storage::disk($disk)->download($path,$filename,['Content-Type'=>$mimeType])`); `MediaServiceInterface::download(...)` uses it for non-external assets (external → redirect). **Task 2.2 download test MUST assert** `Content-Disposition` contains the original filename, `Content-Type` equals the uploaded MIME, and the streamed bytes equal the stored bytes. (Note: the SPA sets `link.download` client-side, so the SPA path is resilient; we still preserve server headers for direct/non-SPA access.)

**R-H2 (HIGH — Phase 3 must stay build-green).** The legacy `tests/Feature/Media/AttachmentTenantIsolationTest.php` instantiates `DocumentAttachment` (dropped table) → suite goes red between drop (3.1) and deletion (3.2). **Fix — reorder:** (1) In **Task 2.2**, the new `DocumentAttachmentApiContractTest` MUST absorb ALL isolation cases from the legacy test: cross-tenant document → 404, cross-company same-tenant document → 404, mixed document/attachment IDs → 404, destroy-scoping (`AttachmentTenantIsolationTest.php:108,115,122,129,136`). (2) **Combine drop + legacy deletion into ONE atomic task/commit** (new Task 3.1): drop migration + delete the 4 legacy files + delete the legacy isolation test together, so no intermediate red state exists. (Original Tasks 3.1/3.2 merge.)

**R-H3 (HIGH — Phase 4 importer completeness + TS).** Add to the Task 4.5 move-checklist (these reference MOVED enums/services/models, not just the staying DTOs): `database/seeders/ProductImagePlaceholderSeeder.php` (imports media services+enums+model `…:7,11`); audit `Product/Presentation/Controllers/ProductController.php` and `Product/Application/DTOs/ProductData.php` — they import the *staying* `ProductMediaData`/`MediaAttachmentData` Catalog DTOs (no change needed) BUT `ProductMediaData`/`MediaAttachmentData` themselves import the *moved* enums (update those). **Required final gate:** `grep -rE 'App\\Modules\\Catalog\\(Domain\\Media|Domain\\Contracts\\Media|Application\\Services\\Media|Application\\Jobs\\GenerateRenditions|Infrastructure\\(Storage|Rendition|Persistence)\\\w*Media)' apps/api packages apps/web` → zero hits; then `php artisan typescript:transform` AND `pnpm --dir ../web typecheck` (generated `packages/shared/types/generated.d.ts:408,410,1465,1490` embeds Catalog media DTO namespaces — regen + typecheck + eyeball the diff). NOTE: enums already moved in Task 1.0, so Phase 4 moves only models/contracts/services/jobs/infra (original Task 4.1 enum-move is removed).

**R-M1 (MED — list ordering + value locking).** `forOwners()` orders by `sort_order` (`…:44`); legacy document list is `created_at desc` (`AttachmentService.php:83-86`). **Fix:** `MediaService::listForOwner()` for the document path MUST order **`created_at desc`** (do a direct tenant+owner-scoped query ordered by `created_at desc` rather than relying on `forOwners` sort, OR add an ordered variant). Task 2.2 tests assert exact values for `filename` (== `original_filename`), `original_filename`, `description`, `uploaded_by.id`, `uploaded_by.name` (non-null), `is_image`, `is_pdf`, `formatted_file_size`, and **ordering after two uploads** (newest first).

**R-M2 (MED — cascade bypass).** `isForceDeleting()` is correct parity (Document SoftDeletes; UI deletes are soft → media intentionally retained). **Fix in Task 2.3:** add an architecture grep test flagging hard document deletes outside model `forceDelete()` (query-builder `->delete()` on `documents`, `withoutEvents`, mass-delete), and document the rule: any future hard-delete/deprovision path MUST call `MediaServiceInterface::purgeOwner(MediaOwnerType::Document, …)` before deleting. (No such path exists today — grep `documents.*->delete()` is clean except soft-delete controllers.)

**R-M3 (MED — i18n keys are MISSING today; preserve the fallback).** `messages.attachment.uploaded|deleted` and `validation.attachment.*` are **not defined** in `lang/{en,fr}/{messages,validation}.php` — the legacy controller already returns the literal key string (Laravel's missing-key fallback). To keep the response **byte-identical** (backend-only, contract-frozen), the new controller/request KEEP the same `__('messages.attachment.*')` / `__('validation.attachment.*')` calls → identical output. Do **not** add the keys in this effort (adding them would change the emitted message and thus the contract). Task 2.2/2.1 note this explicitly; tests assert the current literal-fallback value.

**R-M4 (MED — test depth + required artifacts).** Replace `assertJsonStructure`-only checks with value/byte/header assertions (per R-H1/R-M1). For the drop migration (Task 3.1), a **real-PG `php artisan tenants:migrate` run against a staging tenant DB is a REQUIRED completion artifact** (capture output in the PR), not just a note — SQLite cannot validate the PG drop. Phase 4 completion REQUIRES `pnpm typecheck` green (per R-H3).

### Task 1.0: Move media + rendition enums `Catalog → Media` (precedes all Phase 1 work)

**Files:** `git mv` `Catalog/Domain/Enums/{MediaAssetType,MediaOwnerType,MediaRole,MediaSource,MediaStatus,RenditionFormat,RenditionName}.php` → `apps/api/app/Modules/Media/Domain/Enums/`. Test: `tests/Feature/Architecture/MediaBoundaryTest.php`.

- [ ] **Step 1:** Write the boundary test (red): assert no file under `app/Modules/Media`, `app/Modules/Document`, or `app/Shared/Contracts` imports `App\Modules\Catalog\Domain\Enums\Media*`/`Rendition*`; and that the 7 enums exist under `App\Modules\Media\Domain\Enums`.
- [ ] **Step 2: Run — expect fail.**
- [ ] **Step 3:** `git mv` the 7 enum files; rewrite `namespace` to `App\Modules\Media\Domain\Enums`. Update EVERY importer (`grep -rl 'Catalog\\Domain\\Enums\\\(Media\|Rendition\)' apps/api` — includes `MediaAsset`/`MediaAttachment`/`MediaRendition` models, all 5 media services, `GenerateRenditions`, repos, `MediaStorageAdapter`, the product façade controllers, `CatalogMediaQuery`, `ProductMediaData`/`MediaAttachmentData` DTOs, `ProductImagePlaceholderSeeder`, `GenerateProductImageVariants`, and all existing media tests).
- [ ] **Step 4: Run** `./vendor/bin/phpunit --filter 'Media|Catalog.*Media|Product|MediaBoundary'` → green. phpstan on touched dirs; pint.
- [ ] **Step 5: Commit** `refactor(media): relocate media/rendition enums to Media module (boundary-clean foundation)`

## Global Constraints

- **Worktree + branch:** all work in `/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/media-unification`, branch `feat/media-subsystem-unification` (off `origin/dev`). Every implementation agent MUST `cd` there and assert `git rev-parse --abbrev-ref HEAD` == `feat/media-subsystem-unification` BEFORE editing (sub-agent cwd is not guaranteed; a stale sibling checkout exists 496 commits behind).
- **Backend root:** `apps/api`. Run all PHP commands from `apps/api`.
- **TDD:** failing test first (red) → minimal code (green) → refactor → commit. PHPUnit backend.
- **NEVER run the full PHPUnit suite** (`php artisan test` no-filter / `scripts/preflight.sh`) — it crashes the laptop. Always `--filter` or a path. Ask before any full run.
- **Strict typing:** no `mixed` (use DTOs); enums for all status/type columns; constructor injection only (`private readonly`, never `app()`).
- **PHPStan level 8** zero errors on touched files: `./vendor/bin/phpstan analyse <path>`. **Pint:** `./vendor/bin/pint <path>`.
- **db-per-tenant:** new migrations go in `apps/api/database/migrations/tenant/`. A `DROP`/constraint change behaves differently on PG vs SQLite — the SQLite test suite CANNOT validate the drop; a real-PG migrate check is required (note in the task).
- **Frozen contracts — do NOT change:**
  - POS sync URL shape via `MediaUrlResolver::forPosSync()` → `route('products.images.download', …)` (Tauri SQLite cache key).
  - The `documents/{document}/attachments` HTTP routes, names, permissions, and JSON response shapes (frontend `useAttachments.ts` / `DocumentAttachments.tsx` consume them verbatim).
  - The `products/{product}/images` façade routes/contract (product image UI).
- **Cross-module rule:** consumers reach media only via `App\Shared\Contracts\MediaServiceInterface` / `CatalogMediaQueryInterface` — never by importing media models/services across module boundaries.
- **i18n:** reuse existing keys `messages.attachment.uploaded|deleted`, `validation.attachment.*`. No hardcoded user-facing strings.
- **Reference:** audit `docs/superpowers/audits/2026-06-24-media-unification-audit.md`.

---

## File Structure (what gets created / moved)

**Phase 1 (generic seam; engine stays in Catalog):**
- Modify `Catalog/Domain/Enums/MediaOwnerType.php` (+`Document` case, +`storageSegment()`).
- Modify `Catalog/Application/Services/MediaUploadService.php` (generic `upload()`, `uploadForProduct()` delegates, rendition dispatch only for images).
- Modify `Catalog/Application/Jobs/GenerateRenditions.php` (skip non-image asset types).
- Create `App/Shared/Contracts/MediaServiceInterface.php` (owner-agnostic write/read/serve).
- Create `App/Shared/DTOs/Media/MediaAttachmentView.php` (owner-agnostic read DTO).
- Create `Catalog/Application/Services/MediaService.php` (implements `MediaServiceInterface`, composes upload+attachment+storage).
- Modify `Catalog/Providers/CatalogServiceProvider.php` (bind `MediaServiceInterface`).

**Phase 2 (document consumer onto the seam):**
- Create `config/media.php` (document allow-list + max size, owned by the subsystem, not the legacy service).
- Create `Media/Presentation/Controllers/DocumentAttachmentController.php` (consumes `MediaServiceInterface`; reproduces legacy JSON shapes).
- Create `Media/Presentation/Requests/UploadDocumentMediaRequest.php`.
- Rewrite `Media/routes.php` to point at the new controller (same URLs/names/permissions).
- Create `Document/Application/Observers/DocumentMediaCascadeObserver.php` + register in the Document module provider.

**Phase 3 (retire legacy):**
- Create `database/migrations/tenant/2026_06_24_120000_drop_document_attachments_table.php`.
- Delete `Media/Domain/DocumentAttachment.php`, `Media/Application/Services/AttachmentService.php`, `Media/Presentation/Controllers/AttachmentController.php`, `Media/Presentation/Requests/UploadAttachmentRequest.php`, and their obsolete tests.

**Phase 4 (promote engine Catalog → Media; pure refactor):**
- Move `Catalog/Domain/Enums/Media*`, `RenditionFormat`, `RenditionName` → `Media/Domain/Enums/`.
- Move `Catalog/Domain/Media/*`, `Catalog/Domain/Contracts/Media*|Rendition*` → `Media/Domain/`.
- Move `Catalog/Application/Services/{MediaUploadService,MediaAttachmentService,MediaUrlResolver,RenditionService,MediaService}`, `Catalog/Application/Jobs/GenerateRenditions`, `Catalog/Infrastructure/{Storage,Rendition,Persistence}/*Media*` → `Media/…`.
- Move the 4 bindings + `MediaServiceInterface` binding from `CatalogServiceProvider` → `MediaServiceProvider`.
- Update importers: `Product` façade controllers, `CatalogMediaQuery`, `ProductImageImportService`, `Console/Commands/GenerateProductImageVariants`, `Product/routes.php` imports. (POS `SyncController` already uses the Shared contract — unaffected.)

---

## Phase 1 — Generic, owner-agnostic media seam

**Outcome:** the engine can upload + attach + list + serve + detach + purge for ANY owner type via `Shared/Contracts\MediaServiceInterface`. Product images unchanged. Engine still physically in Catalog. Independently shippable.

### Task 1.1: Add `MediaOwnerType::Document` + storage segment

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Domain/Enums/MediaOwnerType.php`
- Test: `apps/api/tests/Unit/Modules/Catalog/Media/MediaOwnerTypeTest.php`

**Interfaces:**
- Produces: `MediaOwnerType::Document` (value `'DOCUMENT'`); `MediaOwnerType::storageSegment(): string` (`Product`→`'products'`, `ProductVariant`→`'product-variants'`, `Category`→`'categories'`, `Document`→`'documents'`).

- [ ] **Step 1: Write the failing test**
```php
public function test_document_case_and_storage_segments(): void
{
    $this->assertSame('DOCUMENT', MediaOwnerType::Document->value);
    $this->assertSame('products', MediaOwnerType::Product->storageSegment());
    $this->assertSame('documents', MediaOwnerType::Document->storageSegment());
    $this->assertSame('product-variants', MediaOwnerType::ProductVariant->storageSegment());
    $this->assertSame('categories', MediaOwnerType::Category->storageSegment());
}
```
- [ ] **Step 2: Run — expect fail** `cd apps/api && ./vendor/bin/phpunit --filter MediaOwnerTypeTest` → FAIL (undefined `Document` / `storageSegment`).
- [ ] **Step 3: Implement**
```php
enum MediaOwnerType: string
{
    case Product = 'PRODUCT';
    case ProductVariant = 'PRODUCT_VARIANT';
    case Category = 'CATEGORY';
    case Document = 'DOCUMENT';

    public function storageSegment(): string
    {
        return match ($this) {
            self::Product => 'products',
            self::ProductVariant => 'product-variants',
            self::Category => 'categories',
            self::Document => 'documents',
        };
    }
}
```
- [ ] **Step 4: Run — expect pass.**
- [ ] **Step 5: Commit** `feat(media): add Document owner type + storage segment mapping`

### Task 1.2: Generalize `MediaUploadService::upload()`

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Application/Services/MediaUploadService.php`
- Modify: `apps/api/app/Modules/Catalog/Application/Jobs/GenerateRenditions.php`
- Test: `apps/api/tests/Feature/Modules/Catalog/Media/MediaUploadServiceTest.php` (extend)

**Interfaces:**
- Produces: `MediaUploadService::upload(string $tenantId, MediaOwnerType $ownerType, string $ownerId, UploadedFile $file, ?string $userId, MediaAssetType $assetType, array $allowedMime): MediaAsset`. Path = `{ownerType->storageSegment()}/{tenant}/{owner}/{slot}/original.{ext}`. Renditions dispatched ONLY when `$assetType === MediaAssetType::Image`.
- Consumes (refactor): `uploadForProduct(...)` now calls `upload(..., MediaOwnerType::Product, $productId, ..., MediaAssetType::Image, self::ALLOWED_IMAGE_MIME)`.

- [ ] **Step 1: Write failing tests** (add to existing test class; use `Storage::fake('s3')`)
```php
public function test_upload_for_document_stores_pdf_under_documents_path_without_renditions(): void
{
    Storage::fake('s3');
    Queue::fake();
    $service = app(MediaUploadService::class);
    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    $asset = $service->upload('tenant-1', MediaOwnerType::Document, 'doc-1', $file, 'user-1',
        MediaAssetType::Document, ['application/pdf']);

    $this->assertSame(MediaAssetType::Document, $asset->type);
    $this->assertSame('s3', $asset->storage_disk);
    $this->assertStringStartsWith('documents/tenant-1/doc-1/', $asset->storage_path);
    Storage::disk('s3')->assertExists($asset->storage_path);
    Queue::assertNotPushed(GenerateRenditions::class); // non-image → no renditions
}

public function test_upload_rejects_mime_not_in_allow_list(): void
{
    Storage::fake('s3');
    $this->expectException(\Illuminate\Validation\ValidationException::class);
    app(MediaUploadService::class)->upload('t', MediaOwnerType::Document, 'd',
        UploadedFile::fake()->create('x.exe', 1, 'application/x-msdownload'), null,
        MediaAssetType::Document, ['application/pdf']);
}

public function test_uploadForProduct_still_dispatches_renditions(): void
{
    Storage::fake('s3');
    Queue::fake();
    $asset = app(MediaUploadService::class)->uploadForProduct('t', 'p',
        UploadedFile::fake()->image('p.jpg', 10, 10), 'u');
    $this->assertSame(MediaAssetType::Image, $asset->type);
    Queue::assertPushed(GenerateRenditions::class);
}
```
- [ ] **Step 2: Run — expect fail** (`upload` undefined; product test still passes pre-change — keep it as the regression guard).
- [ ] **Step 3: Implement** — extract a private `store(...)` from the current `uploadForProduct` body, parameterized by `$ownerType`, `$ownerId`, `$assetType`, `$allowedMime`. Path via `sprintf('%s/%s/%s/%s/original.%s', $ownerType->storageSegment(), $tenantId, $ownerId, $slotId, $extension)`. `guardMimeType` takes the allow-list arg. Only `getimagesize`/dimensions when `$assetType === Image` (PDFs have no dimensions). Dispatch `GenerateRenditions` in `afterCommit` ONLY when `$assetType === Image`. Add `private const ALLOWED_IMAGE_MIME = self::ALLOWED_MIME;`. `uploadForProduct` delegates.
- [ ] **Step 4:** In `GenerateRenditions::handle()` add a defensive early-return when the loaded asset's `type !== MediaAssetType::Image` (mirror the existing EXTERNAL_URL skip). Add a unit test asserting a Document asset is skipped (marked READY, no renditions).
- [ ] **Step 5: Run — expect all pass.** `./vendor/bin/phpunit --filter 'MediaUploadServiceTest|GenerateRenditions'`
- [ ] **Step 6: phpstan + pint on the two files. Commit** `feat(media): owner-agnostic upload() + skip renditions for non-images`

### Task 1.3: Define the owner-agnostic Shared seam (contract + DTO)

**Files:**
- Create: `apps/api/app/Shared/Contracts/MediaServiceInterface.php`
- Create: `apps/api/app/Shared/DTOs/Media/MediaAttachmentView.php`
- Test: covered by Task 1.4 (interface has no behavior).

**Interfaces:**
- Produces:
```php
namespace App\Shared\DTOs\Media;
final class MediaAttachmentView
{
    public function __construct(
        public readonly string $id,            // media_attachments.id
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly int $fileSize,
        public readonly ?string $caption,
        public readonly ?string $uploadedById,
        public readonly ?string $uploadedByName,
        public readonly ?string $createdAt,    // ISO-8601
        public readonly bool $isImage,
        public readonly bool $isPdf,
    ) {}
}
```
```php
namespace App\Shared\Contracts;
use App\Modules\Media\Domain\Enums\MediaOwnerType; // relocated in Task 1.0 — Shared contract references Media, never Catalog (R-B2)
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Shared\DTOs\Media\MediaAttachmentView;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\RedirectResponse;

interface MediaServiceInterface
{
    /** Upload a file, create the asset, attach it to the owner, return the view row. */
    public function attachUpload(MediaOwnerType $ownerType, string $ownerId, string $tenantId,
        UploadedFile $file, ?string $userId, MediaRole $role, ?string $caption,
        array $allowedMime, \App\Modules\Media\Domain\Enums\MediaAssetType $assetType): MediaAttachmentView;

    /** @return array<int, MediaAttachmentView> newest-first */
    public function listForOwner(MediaOwnerType $ownerType, string $ownerId, string $tenantId): array;

    /** Stream/redirect the attachment's file for download. 404s if missing/foreign. */
    public function download(MediaOwnerType $ownerType, string $ownerId, string $attachmentId,
        string $tenantId): StreamedResponse|RedirectResponse;

    /** Detach one link; deletes the asset when it becomes orphaned. */
    public function detach(MediaOwnerType $ownerType, string $ownerId, string $attachmentId, string $tenantId): void;

    /** Remove ALL media for an owner (used by delete-cascade). */
    public function purgeOwner(MediaOwnerType $ownerType, string $ownerId, string $tenantId): void;
}
```
- [ ] **Step 1:** Create the DTO file. Create the interface file. (No test — declarations only.)
- [ ] **Step 2:** `./vendor/bin/phpstan analyse app/Shared/Contracts/MediaServiceInterface.php app/Shared/DTOs/Media/MediaAttachmentView.php` → 0 errors.
- [ ] **Step 3: Commit** `feat(media): owner-agnostic MediaServiceInterface + MediaAttachmentView DTO`

### Task 1.4: Implement `MediaService` + bind it

**Files:**
- Create: `apps/api/app/Modules/Catalog/Application/Services/MediaService.php`
- Modify: `apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php` (bind interface)
- Test: `apps/api/tests/Feature/Modules/Media/MediaServiceTest.php`

**Interfaces:**
- Consumes: `MediaUploadService` (1.2), `MediaAttachmentService` (`attach`/`detachLink`/`deleteAsset`), `MediaAttachmentRepositoryInterface::forOwners`, `MediaStorageInterface::serve`, `MediaUrlResolver` not needed here.
- Produces: `MediaService implements MediaServiceInterface`.

- [ ] **Step 1: Write failing tests** (real models, `RefreshDatabase`, `Storage::fake('s3')`, seeded permissions not needed — service-level)
```php
public function test_attach_list_download_detach_for_document_owner(): void
{
    Storage::fake('s3'); Queue::fake();
    $svc = app(MediaServiceInterface::class);
    $view = $svc->attachUpload(MediaOwnerType::Document, 'doc-1', 'tenant-1',
        UploadedFile::fake()->create('inv.pdf', 50, 'application/pdf'), 'user-1',
        MediaRole::Datasheet, 'Supplier invoice', ['application/pdf'], MediaAssetType::Document);

    $this->assertTrue($view->isPdf);
    $this->assertSame('inv.pdf', $view->originalFilename);

    $list = $svc->listForOwner(MediaOwnerType::Document, 'doc-1', 'tenant-1');
    $this->assertCount(1, $list);

    $resp = $svc->download(MediaOwnerType::Document, 'doc-1', $view->id, 'tenant-1');
    $this->assertNotNull($resp);

    $svc->detach(MediaOwnerType::Document, 'doc-1', $view->id, 'tenant-1');
    $this->assertCount(0, $svc->listForOwner(MediaOwnerType::Document, 'doc-1', 'tenant-1'));
    // asset orphaned → soft-deleted
    $this->assertSoftDeleted('media_assets', ['tenant_id' => 'tenant-1']);
}

public function test_purge_owner_removes_all(): void { /* attach 2, purgeOwner, assert empty + assets gone */ }

public function test_download_rejects_foreign_owner(): void { /* attach to doc-1, download with owner doc-2 → 404/RuntimeException */ }
```
- [ ] **Step 2: Run — expect fail** (`MediaServiceInterface` unbound).
- [ ] **Step 3: Implement `MediaService`** — constructor-inject `MediaUploadService`, `MediaAttachmentService`, `MediaAttachmentRepositoryInterface`, `MediaStorageInterface`. `attachUpload`: `upload()` → `attach()` (role/sort 0) → build `MediaAttachmentView` from asset+attachment+uploader user lookup. `listForOwner`: `forOwners(type,[ownerId],tenant)[ownerId] ?? []` → map to views (load `mediaAsset`). `download`: resolve the attachment scoped to `(tenant, owner_type, owner_id, id)`; 404 (`abort(404)`) if not found; `storage->serve($attachment, null)`. `detach`: `detachLink()` then `deleteAsset()` if the asset has no remaining links (catch the guard RuntimeException → skip). `purgeOwner`: list attachments for owner, `detach` each (which orphans→deletes assets). Helper to map uploaded_by id → name via `User::find()` (nullable).
- [ ] **Step 4:** Bind in `CatalogServiceProvider::register()`: `$this->app->bind(MediaServiceInterface::class, MediaService::class);`
- [ ] **Step 5: Run — expect pass.** `./vendor/bin/phpunit --filter MediaServiceTest`
- [ ] **Step 6:** phpstan + pint. **Commit** `feat(media): MediaService implements owner-agnostic seam (closes owner-type genericity test gap)`

---

## Phase 2 — Migrate the document-attachment consumer onto the seam

**Outcome:** `documents/{document}/attachments` writes/reads through the unified engine on MinIO, with the legacy JSON contract preserved byte-for-byte. Unblocks supplier invoices. Legacy table/code still present (deleted in Phase 3).

### Task 2.1: Document-media config + upload FormRequest

**Files:**
- Create: `apps/api/config/media.php`
- Create: `apps/api/app/Modules/Media/Presentation/Requests/UploadDocumentMediaRequest.php`
- Test: `apps/api/tests/Feature/Modules/Media/UploadDocumentMediaRequestTest.php`

**Interfaces:**
- Produces: `config('media.documents.max_file_size')` (int bytes, `10485760`), `config('media.documents.allowed_mime_types')` (the legacy list), `config('media.documents.allowed_extensions')`. `UploadDocumentMediaRequest` rules: `file` required|file|max:(size/1024)|mimetypes:<list>; `description` nullable|string|max:500. Same `messages()` keys as legacy.

- [ ] **Step 1:** Write a test asserting the request validates a pdf and rejects an exe + oversize, and that `config('media.documents.max_file_size') === 10485760`.
- [ ] **Step 2: Run — expect fail.**
- [ ] **Step 3:** Create `config/media.php` returning the document allow-list (copy `AttachmentService::ALLOWED_MIME_TYPES` + `getAllowedExtensions()` values verbatim) and max size. Implement the request reading from config.
- [ ] **Step 4: Run — expect pass.** phpstan + pint.
- [ ] **Step 5: Commit** `feat(media): document-media config + upload request (config-owned limits)`

### Task 2.2: New `DocumentAttachmentController` (contract-preserving) + routes

**Files:**
- Create: `apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php`
- Modify: `apps/api/app/Modules/Media/routes.php` (point at new controller; keep URLs/names/permissions/middleware identical)
- Test: `apps/api/tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php`

**Interfaces:**
- Consumes: `MediaServiceInterface` (1.3/1.4), `CompanyContext::requireCompany()`, `config('media.documents.*')`.
- Produces: identical JSON to legacy `AttachmentController` — `index`: `{data:[{id,filename,original_filename,mime_type,file_size,formatted_file_size,description,is_image,is_pdf,uploaded_by:{id,name},created_at}]}`; `store` 201 same object + `message: __('messages.attachment.uploaded')`; `download` streamed; `destroy` `{message: __('messages.attachment.deleted')}`; `config` `{data:{max_file_size,max_file_size_mb,allowed_extensions,allowed_mime_types}}`. `filename` (no longer stored separately) = `MediaAttachmentView::originalFilename` (acceptable: frontend displays `original_filename`; `filename` retained only for shape parity). `formatted_file_size` via a small humanizer helper.

- [ ] **Step 1: Write failing API contract tests** (real backend, seeded `RolesAndPermissionsSeeder`, a user with `documents.update`, a real `Document` row; `Storage::fake('s3')`). Assert each endpoint's status + JSON keys exactly. Include a tenant/company-isolation case mirroring `AttachmentTenantIsolationTest` (foreign company → 404).
```php
public function test_store_then_index_then_download_then_destroy_pdf(): void
{
    Storage::fake('s3'); Queue::fake();
    [$user, $document] = $this->seedUserWithDocument(['documents.view','documents.update']);
    $this->actingAs($user);

    $store = $this->postJson("/api/v1/documents/{$document->id}/attachments",
        ['file' => UploadedFile::fake()->create('inv.pdf', 100, 'application/pdf'), 'description' => 'Inv']);
    $store->assertCreated()->assertJsonStructure(['data' => [
        'id','filename','original_filename','mime_type','file_size','formatted_file_size',
        'description','is_image','is_pdf','uploaded_by' => ['id','name'],'created_at'], 'message']);
    $id = $store->json('data.id');

    $this->getJson("/api/v1/documents/{$document->id}/attachments")
        ->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.is_pdf', true);

    $this->get("/api/v1/documents/{$document->id}/attachments/{$id}/download")->assertOk();

    $this->deleteJson("/api/v1/documents/{$document->id}/attachments/{$id}")
        ->assertOk()->assertJsonPath('message', __('messages.attachment.deleted'));
}
```
- [ ] **Step 2: Run — expect fail** (route still points at legacy controller; assert which controller via the test, or temporarily repoint and watch the new controller 500).
- [ ] **Step 3: Implement** `DocumentAttachmentController` consuming `MediaServiceInterface`. Reuse the legacy `resolveDocument()` company-scoping logic verbatim. Map `MediaAttachmentView` → the legacy JSON. `attachUpload(..., MediaRole::Datasheet, $description, config allow-list, MediaAssetType::Document)` — Datasheet (NOT Primary; documents must not collide on the partial-unique PRIMARY index). `config()` reads `config('media.documents.*')`, keeps `#[CrossTenantRoute]`.
- [ ] **Step 4:** Rewrite `Media/routes.php` to bind the same 5 route definitions to `DocumentAttachmentController` (identical prefixes, names, `can:documents.view|update`, middleware stack). Keep `attachments/config` route + name.
- [ ] **Step 5: Run — expect pass.** `./vendor/bin/phpunit --filter DocumentAttachmentApiContractTest`
- [ ] **Step 6:** phpstan + pint. **Commit** `feat(media): rewire documents/{document}/attachments onto unified MediaService (contract-preserving)`

### Task 2.3: Document delete-cascade observer

**Files:**
- Create: `apps/api/app/Modules/Document/Application/Observers/DocumentMediaCascadeObserver.php`
- Modify: the Document module service provider (register `Document::observe(...)`)
- Test: `apps/api/tests/Feature/Modules/Document/DocumentMediaCascadeTest.php`

**Interfaces:**
- Consumes: `MediaServiceInterface::purgeOwner`. Observer fires on hard delete only (`$document->isForceDeleting()`), mirroring the legacy FK `cascadeOnDelete` which fired only on real DELETE (Document uses SoftDeletes — soft delete left attachments intact).

- [ ] **Step 1: Write failing test** — attach a doc media, `Document::find($id)->forceDelete()`, assert `purgeOwner` removed the attachment + asset; AND a soft `->delete()` leaves them intact (parity).
- [ ] **Step 2: Run — expect fail.**
- [ ] **Step 3: Implement** observer `deleting(Document $d)`: `if ($d->isForceDeleting()) { $this->media->purgeOwner(MediaOwnerType::Document, $d->id, $d->tenant_id); }`. Constructor-inject `MediaServiceInterface`. Register via `Document::observe(DocumentMediaCascadeObserver::class)` in the Document provider's `boot()`.
- [ ] **Step 4: Run — expect pass.** phpstan + pint.
- [ ] **Step 5: Commit** `feat(media): cascade document media on hard-delete (parity with legacy FK)`

---

## Phase 3 — Retire the legacy DocumentAttachment stack

**Outcome:** legacy table + code gone; `Media` module now contains only the new document-attachment presentation layer + (still) the engine in Catalog.

### Task 3.1: Drop `document_attachments` (discard data — owner approved)

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_06_24_120000_drop_document_attachments_table.php`
- Test: `apps/api/tests/Feature/Modules/Media/DropDocumentAttachmentsMigrationTest.php` (asserts table absent after migrate)

- [ ] **Step 1:** Write a test that runs migrations and asserts `Schema::hasTable('document_attachments') === false`.
- [ ] **Step 2: Run — expect fail.**
- [ ] **Step 3:** Implement `up()` = `Schema::dropIfExists('document_attachments')` (unconditional — no row-count guard; owner approved discard). `down()` = recreate the table exactly per `2025_12_14_000001_create_document_attachments_table.php` (copy its `up()` body) so the migration is reversible.
- [ ] **Step 4: Run — expect pass (SQLite).** ⚠️ **Real-PG check required** — note in the task: a maintainer must run the drop against a real PG tenant DB (`php artisan tenants:migrate` on a staging tenant) because SQLite cannot validate PG `DROP`/constraint behavior.
- [ ] **Step 5: Commit** `feat(media): drop document_attachments table (data discarded, owner-approved)`

### Task 3.2: Delete legacy code + prove no references remain

**Files:**
- Delete: `Media/Domain/DocumentAttachment.php`, `Media/Application/Services/AttachmentService.php`, `Media/Presentation/Controllers/AttachmentController.php`, `Media/Presentation/Requests/UploadAttachmentRequest.php`
- Delete/replace: legacy tests `tests/Feature/Media/AttachmentTenantIsolationTest.php` (superseded by 2.2's isolation case — fold any missing assertion into the new test first).

- [ ] **Step 1: Cross-app deprecation grep** (per the deprecation-check discipline): `grep -rn "DocumentAttachment\|AttachmentService\|UploadAttachmentRequest" apps/api apps/web apps/pos packages` → expect only the files about to be deleted + the new code's none. Resolve any stray reference FIRST.
- [ ] **Step 2:** Delete the 4 legacy files + the superseded test (after confirming its isolation coverage exists in 2.2).
- [ ] **Step 3:** Run the Media + Document test subsets: `./vendor/bin/phpunit --filter 'Media|DocumentMediaCascade|DocumentAttachmentApiContract'` → green.
- [ ] **Step 4:** phpstan on `app/Modules/Media` + `app/Modules/Document`; pint. **Commit** `refactor(media): remove legacy DocumentAttachment stack`

---

## Phase 4 — Promote the engine `Catalog → Media` (pure refactor, behavior-preserving)

**Outcome:** the generic engine physically lives in `App\Modules\Media`; Catalog/Product retains only product-specific façades. No behavior change — every moved class keeps its logic; only namespaces + bindings change. Consumers using `Shared/Contracts` are untouched.

> **Execution note:** do this as mechanical move-then-fix-imports, ONE logical group per task, running the relevant `--filter` subset after each so a regression is caught immediately. Use `git mv` to preserve history.

### Task 4.1: Move the enums
**Files:** move `Catalog/Domain/Enums/{MediaAssetType,MediaOwnerType,MediaRole,MediaSource,MediaStatus,RenditionFormat,RenditionName}.php` → `Media/Domain/Enums/`. Update namespace headers + every importer (grep `Catalog\\Domain\\Enums\\Media`, `Catalog\Domain\Enums\Rendition`).
- [ ] git mv the 7 files; rewrite `namespace` to `App\Modules\Media\Domain\Enums`.
- [ ] `grep -rln 'Catalog\\Domain\\Enums\\\(Media\|Rendition\)' apps/api` → update each `use` (includes `Shared/Contracts/MediaServiceInterface`, `Shared/DTOs`, Product façades, `CatalogMediaQuery`, services, the Phase-1/2 code).
- [ ] `./vendor/bin/phpunit --filter 'Media|Catalog.*Media|Product'` green; phpstan; pint. **Commit** `refactor(media): move media/rendition enums to Media module`.

### Task 4.2: Move Domain models + Contracts
**Files:** `Catalog/Domain/Media/{MediaAsset,MediaAttachment,MediaRendition}.php` → `Media/Domain/Media/`; `Catalog/Domain/Contracts/{MediaAssetRepositoryInterface,MediaAttachmentRepositoryInterface,MediaStorageInterface,RenditionGeneratorInterface}.php` → `Media/Domain/Contracts/`.
- [ ] git mv + namespace rewrite + importer update (grep both old namespaces). The models' `$table` stays (`media_assets` etc.).
- [ ] Test subset green; phpstan; pint. **Commit** `refactor(media): move media models + contracts to Media module`.

### Task 4.3: Move Application + Infrastructure
**Files:** `Catalog/Application/Services/{MediaUploadService,MediaAttachmentService,MediaUrlResolver,RenditionService,MediaService}.php`, `Catalog/Application/Jobs/GenerateRenditions.php`, `Catalog/Infrastructure/Storage/MediaStorageAdapter.php`, `Catalog/Infrastructure/Rendition/ImageRenditionGenerator.php`, `Catalog/Infrastructure/Persistence/{EloquentMediaAssetRepository,EloquentMediaAttachmentRepository}.php` → `Media/…` equivalents.
- [ ] git mv + namespace rewrite + importer update. `MediaUrlResolver::forPosSync` keeps `route('products.images.download', …)` (frozen).
- [ ] Test subset green; phpstan; pint. **Commit** `refactor(media): move media services/jobs/infra to Media module`.

### Task 4.4: Move bindings to `MediaServiceProvider`
**Files:** `Media/MediaServiceProvider.php` (add the 5 binds: 2 repos, storage, rendition generator, `MediaServiceInterface`); `Catalog/Providers/CatalogServiceProvider.php` (remove those binds; keep `CatalogMediaQueryInterface` binding — that read seam stays in Catalog).
- [ ] Move binds; update `use` imports. `bootstrap/providers.php` already lists `MediaServiceProvider`. 
- [ ] Test subset green; phpstan; pint. **Commit** `refactor(media): bind media engine in MediaServiceProvider`.

### Task 4.5: Repoint product façade importers + final sweep
**Files:** `Catalog/Presentation/Controllers/{ProductMediaController,PublicProductMediaController,SignedMediaController}.php`, `Catalog/Application/Queries/CatalogMediaQuery.php`, `Catalog/Application/DTOs/{ProductMediaData,MediaAttachmentData,MediaRenditionData,MediaAssetData}.php`, `Product/Application/Services/ProductImageImportService.php`, `Console/Commands/GenerateProductImageVariants.php`, `Product/routes.php` (the 3 controller `use` lines — controllers MAY stay in Catalog namespace but now import engine from Media).
- [ ] Final grep `grep -rn 'Catalog\\\(Domain\\Media\|Domain\\Contracts\\Media\|Application\\Services\\Media\|Infrastructure\\.*Media\)' apps/api` → zero hits.
- [ ] Decide `SignedMediaController` home: it is generic → optionally `git mv` to `Media/Presentation/Controllers/` (keep route name `media.serve` in `Product/routes.php` or move that route to `Media/routes.php`). Keep route NAME unchanged either way.
- [ ] Run the broad-but-scoped subset `./vendor/bin/phpunit --filter 'Media|Catalog|Product|POS.*Sync|ProductData'` green.
- [ ] `php artisan typescript:transform` and confirm `packages/shared/types/generated.d.ts` still builds (DTOs moved namespace → regenerate; frontend uses hand-written types so no runtime break, but keep generated types valid). 
- [ ] phpstan on `app/Modules/Media`, `app/Modules/Catalog`, `app/Modules/Product`; pint. **Commit** `refactor(media): repoint product façade importers to promoted Media engine`.

---

## Self-Review (run before requesting code review)

- **Spec coverage:** ✅ owner-agnostic engine (P1) · Document owner type (1.1) · generic upload path + non-image handling (1.2) · Shared seam (1.3/1.4) · document consumer migration preserving contract (2.2) · cascade (2.3) · drop + discard data (3.1) · legacy deletion (3.2) · physical promotion to Media (P4) · supplier-invoice unblock (coordination note + 1.1/2.2 sequencing). Frontend untouched per decision.
- **Frozen contracts honored:** POS `forPosSync` route, product images façade, documents-attachments HTTP+JSON shapes — each named in Global Constraints and asserted by tests.
- **db-per-tenant / PG caveat:** drop migration flagged for real-PG check; partial-unique PRIMARY index is PG-only (documents attach as `Datasheet`, never `Primary`, so they never touch that index).
- **Type consistency:** `MediaAttachmentView` fields are used identically in 1.3/1.4/2.2; `MediaServiceInterface` signatures match between contract and `MediaService` impl and all callers.
- **No placeholders:** every code step shows the real delta or signature; existing 200-line files are referenced by path:line rather than re-pasted.

## Open risks to flag in code review

1. `download()` serving: legacy streamed inline via `Storage::download(... original_filename ...)`; the unified `MediaStorageInterface::serve()` may set different headers (inline vs attachment, filename). Verify the frontend `fetch(...).blob()` download path still yields the right filename, or add a `download` mode to `serve()`.
2. `filename` field parity: legacy stored a separate UUID `filename`; the new view reuses `original_filename`. Confirm no frontend code reads `filename` distinctly (audit says it displays `original_filename`).
3. Phase 4 is large/mechanical; if priorities shift it is independently deferrable — Phases 1-3 already deliver the functional unification (consumers depend on `Shared/Contracts`, not Catalog internals).
