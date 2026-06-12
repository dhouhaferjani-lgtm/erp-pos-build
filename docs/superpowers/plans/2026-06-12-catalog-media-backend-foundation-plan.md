# Catalog Media Backend Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the image-only `product_images` table with a PIM-grade asset/rendition/link model in the `Catalog` module, at full parity with today's image behavior across every consumer, with `type`/`source`/`role` seams for future media types.

**Architecture:** Three tenant-DB tables — `media_assets` (binary + intrinsic metadata), `media_renditions` (pre-generated WebP derivatives), `media_attachments` (owner link with role/sort/channel/locale). Hexagonal layout under `app/Modules/Catalog/{Domain,Application,Infrastructure,Presentation}`. The `Product` module reads media only through `Shared/Contracts/CatalogMediaQueryInterface`; media composition happens in the Product controller, not the `ProductData` DTO. The existing `/products/{product}/images[...]` + public routes are preserved as a façade over the new model (`{image}` = `media_attachments.id`). No backfill — `product_images` is dropped and demo data re-seeded.

**Tech Stack:** Laravel 12 / PHP 8.2 strict types, PostgreSQL 16 (db-per-tenant via Stancl), Spatie Laravel-Data DTOs, GD image lib (reuse `ImageVariantService::resizeAndEncode`), PHPUnit (`RefreshDatabase`), Deptrac, PHPStan L8, Pint.

**Spec:** `docs/superpowers/specs/2026-06-12-catalog-media-backend-foundation-design.md` (3-round Codex-reviewed, ACCEPT-WITH-MINOR-EDITS).

**Conventions (hard rules):** enums for all type/status columns (no magic strings); constructor injection only (no `app()`); no `mixed` — DTOs for structured data; strict typing; TDD; **never run the full PHPUnit suite** — always `--filter`/path. Run all `php`/`composer` commands from `apps/api/`.

---

## File structure (what gets created/modified)

**Created — `apps/api/app/Modules/Catalog/`:**
- `Domain/Media/MediaAsset.php`, `MediaRendition.php`, `MediaAttachment.php`
- `Domain/Enums/{MediaAssetType,MediaSource,MediaStatus,RenditionName,RenditionFormat,MediaRole,MediaOwnerType}.php`
- `Domain/Contracts/{MediaAssetRepositoryInterface,MediaAttachmentRepositoryInterface,RenditionGeneratorInterface,MediaStorageInterface}.php`
- `Application/Services/{MediaUploadService,MediaAttachmentService,RenditionService,MediaServeService}.php`
- `Application/DTOs/{MediaAssetData,MediaRenditionData,MediaAttachmentData,ProductMediaData}.php`
- `Application/Jobs/GenerateRenditions.php`
- `Application/Queries/CatalogMediaQuery.php`
- `Infrastructure/Persistence/{EloquentMediaAssetRepository,EloquentMediaAttachmentRepository}.php`
- `Infrastructure/Storage/MediaStorageAdapter.php`
- `Infrastructure/Rendition/ImageRenditionGenerator.php`
- `Presentation/Controllers/{ProductMediaController,PublicProductMediaController,MediaServeController}.php`
- `Presentation/Requests/{UploadMediaRequest,AttachMediaRequest,ReorderMediaRequest}.php`

**Created — migrations (`apps/api/database/migrations/tenant/`):**
- `2026_06_12_100001_create_media_assets_table.php`
- `2026_06_12_100002_create_media_renditions_table.php`
- `2026_06_12_100003_create_media_attachments_table.php`
- `2026_06_12_100004_drop_product_images_table.php`

**Created — `apps/api/app/Shared/Contracts/CatalogMediaQueryInterface.php`**

**Modified:**
- `app/Modules/Catalog/Providers/CatalogServiceProvider.php` (bind interfaces; existing provider, `register()` ~line 24-33)
- `app/Modules/Product/Application/DTOs/ProductData.php` (accept `ProductMediaData`)
- `app/Modules/Product/Presentation/Controllers/ProductController.php` (inject query, compose media)
- `app/Modules/Product/Domain/Product.php` (remove `images()`/`primaryImage()`)
- `app/Modules/Product/Presentation/Controllers/{ProductImageController,PublicProductImageController}.php` (re-point or delete; routes → new controllers)
- `app/Modules/Product/routes.php` (point image routes at `ProductMediaController`/`PublicProductMediaController`)
- `app/Modules/POS/Presentation/Controllers/SyncController.php` (primary image URL via query)
- `app/Modules/Product/Application/Services/ProductImageImportService.php` (use `MediaUploadService`)
- `app/Console/Commands/GenerateProductImageVariants.php` (regenerate renditions)
- `database/seeders/ProductImagePlaceholderSeeder.php` (write new model)

**Deleted (in Task 15, only after their replacements land):** `app/Modules/Product/Domain/ProductImage.php`, `Application/Services/ProductImageService.php`, `Application/Jobs/GenerateImageVariants.php`, `Application/Services/ImageVariantService.php`. `ImageVariantService::resizeAndEncode()` must be copied into `ImageRenditionGenerator` in **Task 8** first, and **Task 14** must stop importing `GenerateImageVariants`, before Task 15 deletes them.

---

## Phase A — Enums & schema

### Task 1: Media enums

**Files:**
- Create: `app/Modules/Catalog/Domain/Enums/MediaAssetType.php` (+ 6 sibling enums)
- Test: `tests/Unit/Modules/Catalog/Media/MediaEnumsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog\Media;

use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Enums\RenditionFormat;
use App\Modules\Catalog\Domain\Enums\RenditionName;
use PHPUnit\Framework\TestCase;

final class MediaEnumsTest extends TestCase
{
    public function test_enum_values_are_stable_strings(): void
    {
        self::assertSame('IMAGE', MediaAssetType::Image->value);
        self::assertSame('EXTERNAL_URL', MediaSource::ExternalUrl->value);
        self::assertSame('READY', MediaStatus::Ready->value);
        self::assertSame('THUMBNAIL', RenditionName::Thumbnail->value);
        self::assertSame('WEBP', RenditionFormat::Webp->value);
        self::assertSame('PRIMARY', MediaRole::Primary->value);
        self::assertSame('PRODUCT', MediaOwnerType::Product->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Catalog/Media/MediaEnumsTest.php`
Expected: FAIL — class `MediaAssetType` not found.

- [ ] **Step 3: Create the enums**

`MediaAssetType.php`:
```php
<?php
declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum MediaAssetType: string
{
    case Image = 'IMAGE';
    case Document = 'DOCUMENT';
    case Video = 'VIDEO';
    case ExternalVideo = 'EXTERNAL_VIDEO';
    case Spin360 = 'SPIN_360';
}
```
`MediaSource.php`: `enum MediaSource: string { case Upload = 'UPLOAD'; case ExternalUrl = 'EXTERNAL_URL'; }`
`MediaStatus.php`: `cases Uploaded='UPLOADED', Processing='PROCESSING', Ready='READY', Failed='FAILED'`
`RenditionName.php`: `cases Thumbnail='THUMBNAIL', Small='SMALL', Web='WEB', Zoom='ZOOM'`
`RenditionFormat.php`: `cases Webp='WEBP', Jpeg='JPEG'`
`MediaRole.php`: `cases Primary='PRIMARY', Gallery='GALLERY', Datasheet='DATASHEET', Manual='MANUAL', VideoPoster='VIDEO_POSTER', Spin='SPIN', Swatch='SWATCH'`
`MediaOwnerType.php`: `cases Product='PRODUCT', ProductVariant='PRODUCT_VARIANT', Category='CATEGORY'`

(All in namespace `App\Modules\Catalog\Domain\Enums`, `declare(strict_types=1)`, backed `string`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Catalog/Media/MediaEnumsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Catalog/Domain/Enums tests/Unit/Modules/Catalog/Media/MediaEnumsTest.php
git commit -m "feat(catalog-media): media enums (asset type/source/status/rendition/role/owner)"
```

---

### Task 2: `media_assets` + `media_renditions` migrations & models

**Files:**
- Create: `database/migrations/tenant/2026_06_12_100001_create_media_assets_table.php`
- Create: `database/migrations/tenant/2026_06_12_100002_create_media_renditions_table.php`
- Create: `app/Modules/Catalog/Domain/Media/MediaAsset.php`, `MediaRendition.php`
- Test: `tests/Feature/Modules/Catalog/Media/MediaAssetModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Enums\RenditionFormat;
use App\Modules\Catalog\Domain\Enums\RenditionName;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaRendition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaAssetModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_asset_with_enum_casts_and_renditions(): void
    {
        $tenantId = (string) Str::uuid();
        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Processing,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/a/original.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1234,
            'checksum' => str_repeat('a', 64),
            'width' => 800,
            'height' => 600,
        ]);

        $rendition = MediaRendition::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'name' => RenditionName::Thumbnail,
            'format' => RenditionFormat::Webp,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/a/thumbnail.webp',
            'width' => 150,
            'height' => 113,
            'file_size' => 321,
        ]);

        $fresh = MediaAsset::with('renditions')->find($asset->id);
        self::assertInstanceOf(MediaAssetType::class, $fresh->type);
        self::assertSame(MediaStatus::Processing, $fresh->status);
        self::assertCount(1, $fresh->renditions);
        self::assertSame(RenditionName::Thumbnail, $fresh->renditions->first()->name);
        self::assertSame($tenantId, $rendition->tenant_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Catalog/Media/MediaAssetModelTest.php`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Create migrations**

`2026_06_12_100001_create_media_assets_table.php`:
```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('type');                 // MediaAssetType
            $table->string('source');               // MediaSource
            $table->string('status');               // MediaStatus
            $table->string('storage_disk');         // s3 | public | url
            $table->string('storage_path')->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('frame_count')->nullable();
            $table->string('title')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
```

`2026_06_12_100002_create_media_renditions_table.php`:
```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_renditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('media_asset_id');
            $table->string('name');     // RenditionName
            $table->string('format');   // RenditionFormat
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->foreign('media_asset_id')->references('id')->on('media_assets')->cascadeOnDelete();
            $table->unique(['media_asset_id', 'name', 'format']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_renditions');
    }
};
```

- [ ] **Step 4: Create the models**

`app/Modules/Catalog/Domain/Media/MediaAsset.php`:
```php
<?php
declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Media;

use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class MediaAsset extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'media_assets';

    protected $fillable = [
        'tenant_id', 'type', 'source', 'status', 'storage_disk', 'storage_path',
        'external_url', 'original_filename', 'mime_type', 'file_size', 'checksum',
        'width', 'height', 'duration_ms', 'frame_count', 'title', 'uploaded_by',
    ];

    protected $casts = [
        'type' => MediaAssetType::class,
        'source' => MediaSource::class,
        'status' => MediaStatus::class,
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'frame_count' => 'integer',
    ];

    /** @return HasMany<MediaRendition> */
    public function renditions(): HasMany
    {
        return $this->hasMany(MediaRendition::class, 'media_asset_id');
    }

    /** @return HasMany<MediaAttachment> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class, 'media_asset_id');
    }
}
```

`MediaRendition.php` (same package): `HasUuids`, `$table='media_renditions'`, fillable = the columns above, casts `name => RenditionName::class`, `format => RenditionFormat::class`, ints; `belongsTo(MediaAsset::class, 'media_asset_id')`.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Catalog/Media/MediaAssetModelTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/tenant/2026_06_12_100001_create_media_assets_table.php database/migrations/tenant/2026_06_12_100002_create_media_renditions_table.php app/Modules/Catalog/Domain/Media tests/Feature/Modules/Catalog/Media/MediaAssetModelTest.php
git commit -m "feat(catalog-media): media_assets + media_renditions tables and models"
```

---

### Task 3: `media_attachments` migration, model & single-primary partial index

**Files:**
- Create: `database/migrations/tenant/2026_06_12_100003_create_media_attachments_table.php`
- Create: `app/Modules/Catalog/Domain/Media/MediaAttachment.php`
- Test: `tests/Feature/Modules/Catalog/Media/MediaAttachmentPrimaryIndexTest.php`

- [ ] **Step 1: Write the failing test (PG-only — partial/expression indexes are not validated by SQLite)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaAttachmentPrimaryIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Partial unique index is PG-only.');
        }
    }

    public function test_second_primary_for_same_global_slot_violates_unique_index(): void
    {
        $tenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();
        $asset = fn () => MediaAsset::create([
            'tenant_id' => $tenantId, 'type' => MediaAssetType::Image, 'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready, 'storage_disk' => 'url', 'external_url' => 'https://x/y.jpg',
        ]);

        MediaAttachment::create([
            'tenant_id' => $tenantId, 'media_asset_id' => $asset()->id,
            'owner_type' => MediaOwnerType::Product, 'owner_id' => $ownerId,
            'role' => MediaRole::Primary, 'sort_order' => 0,
        ]);

        $this->expectException(QueryException::class);
        MediaAttachment::create([
            'tenant_id' => $tenantId, 'media_asset_id' => $asset()->id,
            'owner_type' => MediaOwnerType::Product, 'owner_id' => $ownerId,
            'role' => MediaRole::Primary, 'sort_order' => 1,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Catalog/Media/MediaAttachmentPrimaryIndexTest.php`
Expected: FAIL (table/model missing) — or SKIP if the local default driver is SQLite. **Run against PG**: set `DB_CONNECTION=pgsql` per the project's real-PG test recipe before asserting failure→pass.

- [ ] **Step 3: Create the migration (note: NO `deleted_at` — links are hard-deleted)**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('media_asset_id');
            $table->string('owner_type');   // MediaOwnerType
            $table->uuid('owner_id');
            $table->string('role');          // MediaRole
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('channel')->nullable();
            $table->string('locale')->nullable();
            $table->string('alt')->nullable();
            $table->string('caption')->nullable();
            $table->timestamps();

            $table->foreign('media_asset_id')->references('id')->on('media_assets')->cascadeOnDelete();
            $table->index(['tenant_id', 'owner_type', 'owner_id', 'sort_order']);
        });

        // Single-primary invariant — PG partial unique index (race-safe).
        // COALESCE so a NULL channel/locale collapses to the single "global" primary slot.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX media_attachments_one_primary
                ON media_attachments (owner_type, owner_id, COALESCE(channel, ''), COALESCE(locale, ''))
                WHERE role = 'PRIMARY'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
    }
};
```

- [ ] **Step 4: Create the model**

`MediaAttachment.php`: `HasUuids`, `$table='media_attachments'`, fillable = `tenant_id, media_asset_id, owner_type, owner_id, role, sort_order, channel, locale, alt, caption`, casts `owner_type => MediaOwnerType::class`, `role => MediaRole::class`, `sort_order => 'integer'`. **No `SoftDeletes`.** Define the belongsTo relation with the **canonical name `mediaAsset`** (used verbatim in Tasks 4/5/6/13/14 — do not introduce an `asset()` alias):

```php
/** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<MediaAsset, $this> */
public function mediaAsset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(MediaAsset::class, 'media_asset_id');
}
```

- [ ] **Step 5: Run test to verify it passes (PG)**

Run (PG): `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Modules/Catalog/Media/MediaAttachmentPrimaryIndexTest.php`
Expected: PASS — second insert raises `QueryException` (unique violation).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/tenant/2026_06_12_100003_create_media_attachments_table.php app/Modules/Catalog/Domain/Media/MediaAttachment.php tests/Feature/Modules/Catalog/Media/MediaAttachmentPrimaryIndexTest.php
git commit -m "feat(catalog-media): media_attachments table + single-primary partial index (PG)"
```

> **CI note:** gate `MediaAttachmentPrimaryIndexTest` into the real-PG lane (workflow_dispatch / dev→main), not the SQLite PR lane — SQLite cannot enforce partial/expression unique indexes (see `feedback_migration_drop_unique_pg_vs_sqlite`).

---

## Phase B — Repositories, DTOs, contract

### Task 4: Repository interfaces + Eloquent implementations

**Files:**
- Create: `app/Modules/Catalog/Domain/Contracts/MediaAssetRepositoryInterface.php`, `MediaAttachmentRepositoryInterface.php`
- Create: `app/Modules/Catalog/Infrastructure/Persistence/EloquentMediaAssetRepository.php`, `EloquentMediaAttachmentRepository.php`
- Modify: `app/Modules/Catalog/Providers/CatalogServiceProvider.php` (the actual provider path — `register()` at ~line 24-33; bind interfaces here)
- Test: `tests/Feature/Modules/Catalog/Media/EloquentMediaRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_attachment_repo_returns_product_attachments_ordered_with_ready_assets(): void
{
    $tenantId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $ready = MediaAsset::create([/* ...Ready, Upload, s3... */]);
    $attachment = MediaAttachment::create([
        'tenant_id' => $tenantId, 'media_asset_id' => $ready->id,
        'owner_type' => MediaOwnerType::Product, 'owner_id' => $productId,
        'role' => MediaRole::Primary, 'sort_order' => 0,
    ]);

    $repo = app(\App\Modules\Catalog\Domain\Contracts\MediaAttachmentRepositoryInterface::class);
    $rows = $repo->forOwners(MediaOwnerType::Product, [$productId], $tenantId);

    self::assertArrayHasKey($productId, $rows);
    self::assertSame($attachment->id, $rows[$productId][0]->id);
}
```
(Use `app(...)` only in the test for resolution; production code uses constructor injection.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Catalog/Media/EloquentMediaRepositoryTest.php`
Expected: FAIL — interface not bound.

- [ ] **Step 3: Define the interfaces & implementations**

```php
// Domain/Contracts/MediaAttachmentRepositoryInterface.php
interface MediaAttachmentRepositoryInterface
{
    /** @param array<string> $ownerIds @return array<string, array<int, MediaAttachment>> keyed by owner_id, READY assets, ordered by sort_order */
    public function forOwners(MediaOwnerType $type, array $ownerIds, string $tenantId): array;
}
```

```php
// Infrastructure/Persistence/EloquentMediaAttachmentRepository.php
final class EloquentMediaAttachmentRepository implements MediaAttachmentRepositoryInterface
{
    public function forOwners(MediaOwnerType $type, array $ownerIds, string $tenantId): array
    {
        if ($ownerIds === []) {
            return [];
        }

        // Defense-in-depth: tenant-scope EVERY tenant-scoped table in the query
        // (the link, the asset via whereHas, and both eager-loaded relations).
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

        return $rows->groupBy('owner_id')
            ->map(fn ($g) => $g->values()->all())
            ->all();
    }
}
```
`MediaAssetRepositoryInterface` exposes `save(MediaAsset): void`, `find(string $id, string $tenantId): ?MediaAsset` (**scopes by BOTH `id` and `tenant_id`**), and `markProcessing`/`markReady`/`markFailed` helpers used by the job. The relation name is `mediaAsset` everywhere (Task 3) — no `asset()` alias.

- [ ] **Step 4: Bind in the service provider**

In the Catalog module provider `register()`:
```php
$this->app->bind(MediaAttachmentRepositoryInterface::class, EloquentMediaAttachmentRepository::class);
$this->app->bind(MediaAssetRepositoryInterface::class, EloquentMediaAssetRepository::class);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Catalog/Media/EloquentMediaRepositoryTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Catalog/Domain/Contracts app/Modules/Catalog/Infrastructure/Persistence app/Modules/Catalog/Providers/CatalogServiceProvider.php tests/Feature/Modules/Catalog/Media/EloquentMediaRepositoryTest.php
git commit -m "feat(catalog-media): media repositories + bindings"
```

---

### Task 5: DTOs (`MediaAssetData`, `MediaRenditionData`, `MediaAttachmentData`, `ProductMediaData`)

**Files:**
- Create: `app/Modules/Catalog/Application/DTOs/{MediaAssetData,MediaRenditionData,MediaAttachmentData,ProductMediaData}.php`
- Test: `tests/Unit/Modules/Catalog/Media/MediaAttachmentDataTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_attachment_data_exposes_url_and_role_from_model(): void
{
    // Build a MediaAttachment + READY MediaAsset (EXTERNAL_URL) in memory or via factory,
    // then assert MediaAttachmentData::fromModel($attachment, $url) is a PURE mapping.
    $data = MediaAttachmentData::fromModel($attachment, 'https://x/y.jpg');
    self::assertSame('https://x/y.jpg', $data->url);
    self::assertSame('PRIMARY', $data->role);
}
```

- [ ] **Step 2: Run** — `./vendor/bin/phpunit tests/Unit/Modules/Catalog/Media/MediaAttachmentDataTest.php` → FAIL (class missing).

- [ ] **Step 3: Implement the DTOs (Spatie Laravel-Data, `#[TypeScript]` where consumed by the frontend)**

```php
#[TypeScript]
final class MediaAttachmentData extends Data
{
    public function __construct(
        public string $id,            // media_attachments.id (façade {image} identity)
        public string $asset_id,
        public string $type,          // MediaAssetType value
        public string $role,          // MediaRole value
        public int $sort_order,
        public ?string $url,          // resolved display URL (rendition or external_url)
        public ?string $alt,
        public ?string $caption,
    ) {}

    /** PURE mapping — caller resolves $url via MediaUrlResolver (no service-location in a DTO). */
    public static function fromModel(MediaAttachment $a, ?string $url): self
    {
        return new self(
            id: $a->id, asset_id: $a->media_asset_id, type: $a->mediaAsset->type->value,
            role: $a->role->value, sort_order: $a->sort_order, url: $url,
            alt: $a->alt, caption: $a->caption,
        );
    }
}
```
> **Why a `$url` parameter (Codex plan review HIGH):** resolving URLs *inside* a static DTO factory would re-introduce the exact service-boundary smell we removed from `ProductData`. `MediaUrlResolver` is injected into `CatalogMediaQuery` (Task 6), which computes the URL and passes it in. DTOs never call `app()`.
`ProductMediaData`:
```php
#[TypeScript]
final class ProductMediaData extends Data
{
    /** @param array<int, MediaAttachmentData> $media */
    public function __construct(
        public ?string $primary_image_url,
        public array $media,
    ) {}

    public static function empty(): self { return new self(null, []); }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(catalog-media): media DTOs"`.

---

### Task 5b: `MediaUrlResolver` (pure URL builder — created BEFORE the query that injects it)

> **Ordering fix (Codex plan review r2, HIGH):** `CatalogMediaQuery` (Task 6) constructor-injects
> `MediaUrlResolver`, so the resolver must exist first. It is a pure builder (no storage adapter
> dependency); the storage *serve* adapter follows in Task 7.

**Files:**
- Create: `app/Modules/Catalog/Application/Services/MediaUrlResolver.php`
- Test: `tests/Unit/Modules/Catalog/Media/MediaUrlResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_resolves_rendition_for_upload_and_external_url_for_link(): void
{
    $resolver = new MediaUrlResolver();
    // UPLOAD attachment: forAttachment($a, 'sm') -> route('products.images.download',
    //   ['product'=>productId, 'image'=>$a->id, 'variant'=>'sm']) ; unknown variant -> original (no variant).
    // EXTERNAL_URL attachment: forAttachment($a, 'sm') -> $a->mediaAsset->external_url (no rendition).
    self::assertStringContainsString('/images/'.$a->id.'/download', $resolver->forAttachment($a, 'sm'));
    self::assertStringContainsString('variant=sm', $resolver->forAttachment($a, 'sm'));
    self::assertSame('https://x/y.jpg', $resolver->forAttachment($ext, 'sm'));
}
```

- [ ] **Step 2: Run** → `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Catalog/Media/MediaUrlResolverTest.php` → FAIL (class missing).

- [ ] **Step 3: Implement** — `MediaUrlResolver::forAttachment(MediaAttachment $a, ?string $variant): ?string`:
  - if `$a->mediaAsset->source === MediaSource::ExternalUrl` → return `$a->mediaAsset->external_url`;
  - else → `route('products.images.download', ['product' => $a->owner_id, 'image' => $a->id, 'variant' => $variant])` (the route name already exists; preserves the legacy `variant=sm|md` URL shape — `sm`→THUMBNAIL, `md`→SMALL, unknown→original handled in the serve adapter, Task 7). Pure: no `app()`, no storage I/O.

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(catalog-media): MediaUrlResolver (pure URL builder)"`.

---

### Task 6: `CatalogMediaQueryInterface` (Shared) + `CatalogMediaQuery`

**Files:**
- Create: `app/Shared/Contracts/CatalogMediaQueryInterface.php`
- Create: `app/Modules/Catalog/Application/Queries/CatalogMediaQuery.php`
- Modify: service provider binding
- Test: `tests/Feature/Modules/Catalog/Media/CatalogMediaQueryTest.php`

- [ ] **Step 1: Write the failing test (incl. NO N+1 assertion)**

```php
public function test_for_products_returns_primary_and_gallery_without_n_plus_one(): void
{
    $tenantId = (string) Str::uuid();
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $pid = (string) Str::uuid(); $ids[] = $pid;
        $asset = MediaAsset::create([/* Ready EXTERNAL_URL */]);
        MediaAttachment::create([
            'tenant_id' => $tenantId, 'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product, 'owner_id' => $pid,
            'role' => MediaRole::Primary, 'sort_order' => 0,
        ]);
    }

    $query = app(CatalogMediaQueryInterface::class);
    DB::enableQueryLog();
    $result = $query->forProducts($ids, $tenantId);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    self::assertCount(3, $result);
    self::assertNotNull($result[$ids[0]]->primary_image_url);
    self::assertLessThanOrEqual(2, $count, 'forProducts must batch (no N+1)');
}
```

- [ ] **Step 2: Run** → FAIL (interface missing).

- [ ] **Step 3: Define the contract & implementation**

```php
// app/Shared/Contracts/CatalogMediaQueryInterface.php
interface CatalogMediaQueryInterface
{
    public function forProduct(string $productId, string $tenantId): ProductMediaData;
    /** @param array<string> $productIds @return array<string, ProductMediaData> keyed by product_id */
    public function forProducts(array $productIds, string $tenantId): array;
}
```
```php
// CatalogMediaQuery.php
final class CatalogMediaQuery implements CatalogMediaQueryInterface
{
    public function __construct(
        private readonly MediaAttachmentRepositoryInterface $attachments,
        private readonly MediaUrlResolver $urls,        // injected — DTO stays pure
    ) {}

    public function forProducts(array $productIds, string $tenantId): array
    {
        $byOwner = $this->attachments->forOwners(MediaOwnerType::Product, $productIds, $tenantId);
        $out = [];
        foreach ($productIds as $id) {
            $rows = $byOwner[$id] ?? [];
            $dtos = array_map(
                fn ($a) => MediaAttachmentData::fromModel($a, $this->urls->forAttachment($a, 'sm')),
                $rows,
            );
            $primary = null;
            foreach ($dtos as $d) { if ($d->role === MediaRole::Primary->value) { $primary = $d->url; break; } }
            $out[$id] = new ProductMediaData($primary, $dtos);
        }
        return $out;
    }

    public function forProduct(string $productId, string $tenantId): ProductMediaData
    {
        return $this->forProducts([$productId], $tenantId)[$productId] ?? ProductMediaData::empty();
    }
}
```
Bind `CatalogMediaQueryInterface::class => CatalogMediaQuery::class` in the Catalog provider.

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(catalog-media): CatalogMediaQuery contract + impl"`.

> **Deptrac:** add a rule allowing `Product` → `Shared\Contracts\CatalogMediaQueryInterface` only; forbid `Product` → `Modules\Catalog\*`. Run `./vendor/bin/deptrac` and confirm green before commit.

---

## Phase C — Storage, rendition, upload, attach

### Task 7: `MediaStorageInterface` + `MediaStorageAdapter` (disk-aware serve)

> `MediaUrlResolver` was created in **Task 5b** — this task is the serve adapter only.

**Files:**
- Create: `app/Modules/Catalog/Domain/Contracts/MediaStorageInterface.php`
- Create: `app/Modules/Catalog/Infrastructure/Storage/MediaStorageAdapter.php`
- Test: `tests/Feature/Modules/Catalog/Media/MediaServeTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_serve_streams_uploaded_and_redirects_url_disk(): void
{
    Storage::fake('s3');
    // UPLOAD asset with a fake rendition file (?variant=sm → THUMBNAIL; unknown → original) →
    //   serve returns a streamed/binary response (200).
    // EXTERNAL_URL asset → serve returns a redirect (302) to external_url.
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** — `MediaStorageInterface` exposes `serve(MediaAttachment $a, ?string $variant): StreamedResponse|RedirectResponse` and `put/get/delete`. `MediaStorageAdapter` wraps `Storage::disk($disk)`: for an EXTERNAL_URL asset (`url` disk) → `redirect()->away($asset->external_url)`; else resolve the rendition for `$variant` (legacy `sm→THUMBNAIL`, `md→SMALL`, unknown→original) and `Storage::disk($disk)->response($renditionOrOriginalPath)`. (URL *building* lives in `MediaUrlResolver`, Task 5b; this adapter does the byte *serving*.)

- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat(catalog-media): disk-aware storage adapter + URL resolver"`.

---

### Task 8: `ImageRenditionGenerator` (reuse GD) + `RenditionService`

**Files:**
- Create: `app/Modules/Catalog/Domain/Contracts/RenditionGeneratorInterface.php`
- Create: `app/Modules/Catalog/Infrastructure/Rendition/ImageRenditionGenerator.php`
- Create: `app/Modules/Catalog/Application/Services/RenditionService.php`
- Test: `tests/Feature/Modules/Catalog/Media/RenditionServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_generates_thumbnail_small_web_webp_rows_for_image_asset(): void
{
    Storage::fake('s3');
    // seed a small real JPEG at the asset's storage_path on the fake disk
    Storage::disk('s3')->put($asset->storage_path, base64_decode(self::TINY_JPEG_BASE64));

    app(RenditionService::class)->generate($asset);

    $names = $asset->fresh()->renditions->pluck('name')->map->value->sort()->values()->all();
    self::assertSame(['SMALL', 'THUMBNAIL', 'WEB'], $names);
    self::assertTrue($asset->renditions->every(fn ($r) => $r->format === RenditionFormat::Webp));
}
```
(Provide a tiny valid JPEG as a base64 constant in the test.)

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** — `RenditionGeneratorInterface::generate(string $bytes, int $maxWidth): string` (WebP). `ImageRenditionGenerator` delegates to the existing GD routine (lift `ImageVariantService::resizeAndEncode` into this class verbatim — same algorithm; do NOT keep two copies once Task 15 deletes the old service). `RenditionService::generate(MediaAsset)`:
```php
public const TARGETS = [
    RenditionName::Thumbnail->value => 150,
    RenditionName::Small->value => 400,
    RenditionName::Web->value => 1000,
];
```
Reads the original via the storage adapter, loops TARGETS, encodes WebP, writes to `dirname(original)/{name}.webp`, creates a `MediaRendition` row per target (`format=WEBP`, dims, file_size). `ZOOM` is not generated (references the original).

- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat(catalog-media): image rendition generator (GD/WebP) + RenditionService"`.

---

### Task 9: `GenerateRenditions` job — tenant-aware

**Files:**
- Create: `app/Modules/Catalog/Application/Jobs/GenerateRenditions.php`
- Test: `tests/Feature/Modules/Catalog/Media/GenerateRenditionsJobTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_job_marks_ready_and_does_not_touch_another_tenants_asset(): void
{
    Storage::fake('s3');
    // create a real Tenant row (central) for tenant A so BindsTenantContext can resolve it; seed
    // original bytes for asset A (status=UPLOADED). Also seed asset B under a DIFFERENT tenant_id.
    (new GenerateRenditions($tenantA, $assetA->id))->handle(app(RenditionService::class), app(MediaAssetRepositoryInterface::class));

    $freshA = MediaAsset::withoutGlobalScopes()->find($assetA->id);
    self::assertSame(MediaStatus::Ready, $freshA->status);
    self::assertGreaterThan(0, $freshA->renditions()->count());

    // Tenant isolation: B is untouched (still UPLOADED, no renditions).
    $freshB = MediaAsset::withoutGlobalScopes()->find($assetB->id);
    self::assertSame(MediaStatus::Uploaded, $freshB->status);
    self::assertSame(0, $freshB->renditions()->count());
}
```
> The job's `$assets->find($id, $tenantId)` must filter on BOTH columns; this test fails if the lookup ignores `tenant_id`. (Codex plan review.)

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement (mirror `ProcessProductImageImport` tenancy pattern)**

```php
final class GenerateRenditions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use \App\Jobs\Concerns\BindsTenantContext;

    public int $tries = 3;
    public int $timeout = 120;
    /** @var array<int,int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $tenantId,   // REQUIRED by BindsTenantContext
        public readonly string $mediaAssetId,
    ) {
        $this->onQueue('images');
    }

    public function handle(RenditionService $renditions, MediaAssetRepositoryInterface $assets): void
    {
        $this->withTenantContext(function () use ($renditions, $assets): void {
            $asset = $assets->find($this->mediaAssetId, $this->tenantId); // tenant-scoped query
            if ($asset === null || $asset->source === MediaSource::ExternalUrl) {
                return;
            }
            $assets->markProcessing($asset);
            try {
                $renditions->generate($asset);
                $assets->markReady($asset);
            } catch (\Throwable $e) {
                $assets->markFailed($asset);
                Log::error('GenerateRenditions failed', ['asset' => $this->mediaAssetId, 'error' => $e->getMessage()]);
                throw $e;
            }
        });
    }
}
```

- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat(catalog-media): tenant-aware GenerateRenditions job"`.

---

### Task 10: `MediaUploadService` + `MediaAttachmentService`

**Files:**
- Create: `app/Modules/Catalog/Application/Services/MediaUploadService.php`, `MediaAttachmentService.php`
- Test: `tests/Feature/Modules/Catalog/Media/MediaUploadServiceTest.php`, `MediaAttachmentServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
// upload
public function test_upload_image_creates_processing_asset_dispatches_job_and_rejects_non_image(): void
{
    Storage::fake('s3'); Bus::fake();
    $asset = app(MediaUploadService::class)->uploadForProduct($tenantId, $productId, UploadedFile::fake()->image('p.jpg', 800, 600), $userId);
    self::assertSame(MediaStatus::Uploaded, $asset->status);   // upload creates UPLOADED; the job flips PROCESSING→READY
    self::assertNotNull($asset->checksum);
    Bus::assertDispatched(GenerateRenditions::class);

    $this->expectException(ValidationException::class);
    app(MediaUploadService::class)->uploadForProduct($tenantId, $productId, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), $userId);
}

// attach single-primary demote
public function test_attaching_new_primary_demotes_prior_primary(): void
{
    // attach A primary; attach B primary → A becomes GALLERY, B is the only PRIMARY.
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** — `MediaUploadService::uploadForProduct(tenantId, productId, UploadedFile, userId): MediaAsset` mirrors the current `ProductImageService::upload()` flow. **Image-only validation in BOTH layers, exact current allow-list:**
  - `UploadMediaRequest` rules: `['image' => ['required','image','mimes:jpeg,png,webp,gif','max:5120']]`.
  - Service guard constant: `private const ALLOWED_MIME = ['image/jpeg','image/png','image/webp','image/gif'];` — throw `ValidationException` if `$file->getMimeType()` not in it.

  Then: compute `checksum = hash('sha256', (string) file_get_contents($file->getRealPath()))`; path `products/{tenant}/{product}/{assetUuid}/original.{ext}`; `Storage::disk('s3')->putFileAs(...)` before the transaction; `DB::transaction` → create `MediaAsset` (**`status=MediaStatus::Uploaded`**, dims via `getimagesize`); `DB::afterCommit` → `GenerateRenditions::dispatch($tenantId, $asset->id)`; on throw, delete the orphaned S3 object. The job (Task 9) flips `Uploaded`→`Processing`→`Ready`.

  `MediaAttachmentService`: `attach(assetId, owner, role, sort)` — if `role=PRIMARY`, demote the current primary to `GALLERY` in the same transaction (the partial index is the backstop); `reorder(owner, ids)`; `detachLink(attachmentId)` (hard delete; promote next by sort if it was primary); `deleteAsset(assetId)` (only if no links remain; soft-delete asset, cascade renditions, delete files after commit).

- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat(catalog-media): upload + attachment services (image-only, single-primary)"`.

---

## Phase D — Presentation façade

### Task 11: `ProductMediaController` + serve, preserving `/products/{product}/images`

**Files:**
- Create: `app/Modules/Catalog/Presentation/Controllers/ProductMediaController.php`, `MediaServeController.php`
- Create: `app/Modules/Catalog/Presentation/Requests/{UploadMediaRequest,AttachMediaRequest,ReorderMediaRequest}.php`
- Modify: `app/Modules/Product/routes.php` (point the existing `products/{product}/images` group at the new controllers — **reuse the existing route group exactly**: `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim,'module:Inventory']` + per-route `can:products.*`, `throttle:image-upload`)
- Test: `tests/Feature/Modules/Catalog/Media/ProductImageFacadeTest.php` **and** `ProductImageFacadeTenantIsolationTest.php` (port the hard-won cases from the existing `tests/Feature/Product/ProductImageTenantIsolationTest.php`)

- [ ] **Step 1: Write the failing tests — full CRUD parity + isolation regressions**

```php
public function test_facade_index_download_store_update_delete_reorder_behave_as_before(): void
{
    // auth as a user with products.* on a seeded tenant/company/product.
    $list = $this->getJson("/api/v1/products/{$productId}/images")->assertOk()->json('data');
    $imageId = $list[0]['id'];               // == media_attachments.id
    $this->get("/api/v1/products/{$productId}/images/{$imageId}/download?variant=sm")->assertSuccessful();
    $this->postJson("/api/v1/products/{$productId}/images", ['image' => UploadedFile::fake()->image('n.jpg')])->assertCreated();
    $this->patchJson("/api/v1/products/{$productId}/images/{$imageId}", ['is_primary' => true])->assertOk(); // PATCH sets PRIMARY
    $this->postJson("/api/v1/products/{$productId}/images/reorder", ['image_ids' => [$imageId]])->assertOk();
    $this->deleteJson("/api/v1/products/{$productId}/images/{$imageId}")->assertNoContent();                 // DELETE
}

public function test_isolation_and_validation_regressions(): void
{
    // Port from ProductImageTenantIsolationTest:
    // - index is scoped by company/tenant (foreign product's images never appear)
    // - download/update/destroy with an attachment id from ANOTHER product → 404
    // - reorder with an attachment id outside the product → 422
    // - cross-company / cross-tenant access → 404 (indistinguishable from missing)
    // - deleting the PRIMARY link promotes the next by sort_order
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement the controllers** — constructor-inject `MediaUploadService`, `MediaAttachmentService`, `MediaServeService`, `CompanyContext`. `index()` returns the **same JSON shape** today's `ProductImageController::index` returns (id, url, is_primary, sort_order, …) built from `MediaAttachmentData` (note `is_primary = role === PRIMARY`). `{image}` resolves a `media_attachments` row scoped to the product + tenant (validate UUID first — `Str::isUuid`; foreign/mixed ids → 404, outside-product reorder ids → 422). `download()` → `MediaServeService::serve($attachment, $variant)`. `store/update/destroy/reorder` delegate to the services. Keep company-scoped authz identical (chained `->where('tenant_id', ...)` then owner/company scope).

- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat(catalog-media): product image façade over media model"`.

---

### Task 12: `PublicProductMediaController` — preserve public storefront API

**Files:**
- Create: `app/Modules/Catalog/Presentation/Controllers/PublicProductMediaController.php`
- Modify: `app/Modules/Product/routes.php` (`/api/v1/public/products/{product}/images[...]` → new controller; same `['api','throttle:public-product-images']` group, no auth, `is_active_for_ecommerce` guard)
- Test: `tests/Feature/Modules/Catalog/Media/PublicProductImageFacadeTest.php`

- [ ] **Step 1: Write the failing test** — public index returns the preserved shape for an e-commerce-active product; **empty/404 for a product where `is_active_for_ecommerce = false`**. The gate is the **product flag `is_active_for_ecommerce`** directly (do NOT carry forward `ProductImage::canBeAccessedPublicly()` — that helper dies with the model in Task 15).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — replicate the public shape from `media_attachments` (READY image assets), gating on the product's `is_active_for_ecommerce` flag and resolving public URLs. If a media-façade helper is wanted, add a new explicit one; don't depend on the deleted model.
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "feat(catalog-media): preserve public product-image API over media model"`.

---

## Phase E — Product integration + consumer migration

### Task 13: `ProductData` + `ProductController` media composition

**Files:**
- Modify: `app/Modules/Product/Application/DTOs/ProductData.php`, `app/Modules/Product/Presentation/Controllers/ProductController.php`
- Test: `tests/Feature/Modules/Product/ProductDataMediaParityTest.php`

- [ ] **Step 1: Write the failing test (parity on index + show, UPLOAD vs EXTERNAL_URL vs none)**

```php
public function test_primary_image_url_parity_across_sources_and_endpoints(): void
{
    // product A: EXTERNAL_URL primary -> primary_image_url == external_url
    // product B: UPLOAD primary (READY) -> primary_image_url == download route url
    // product C: no media -> primary_image_url null
    foreach (["/api/v1/products", "/api/v1/products/{$a}"] as $url) { /* assert shapes */ }
}

public function test_index_resolves_media_in_one_batched_call_no_n_plus_one(): void
{
    // seed N (>=5) products each with a primary attachment.
    $spy = $this->mock(CatalogMediaQueryInterface::class);
    $spy->shouldReceive('forProducts')->once()->andReturn(/* map of ProductMediaData */);
    $spy->shouldNotReceive('forProduct');
    $this->getJson('/api/v1/products?per_page=50')->assertOk();
    // forProducts() called exactly ONCE for the page (the formatter must not call forProduct per row).
}
```

- [ ] **Step 2: Run** → FAIL (after the eager-load removal in Step 3 the old path is gone).

- [ ] **Step 3: Implement** — change `ProductData::fromModel(Product $product)` → `fromModel(Product $product, ?ProductMediaData $media = null)` (**optional with a null default** so the existing `formatOffsetPaginatedResponse()` — which calls `ProductData::fromModel($item)` at `app/Support/Traits/PaginatesResults.php:70-74` — keeps compiling). When `$media === null`, set `primary_image_url = null, media = []`. Add the field with a TS-emitting docblock:

```php
/** @param array<int, \App\Modules\Catalog\Application\DTOs\MediaAttachmentData> $media */
public function __construct(/* ...existing... */, public array $media = []) {}
```

In `ProductController`: constructor-inject `CatalogMediaQueryInterface`. **`index()`** — do NOT rely on the generic formatter to inject media (it has no media arg). Instead: build the paginated payload, then in ONE call `forProducts($paginator->pluck('id')->all(), $company->tenant_id)` and map each row's `data` to `ProductData::fromModel($product, $mediaMap[$product->id])` (override the formatter output, or replace the `formatOffsetPaginatedResponse` call with an explicit map that passes media). **`show/store/update`** call `forProduct($id, $company->tenant_id)`. Remove `'primaryImage'` from every `with([...])` array. Re-run `php artisan typescript:transform` and **grep `packages/shared/types/generated.d.ts` for `media:` under `ProductData`** to confirm the type emitted as `Array<MediaAttachmentData>` (not `Array<any>`).

- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "refactor(product): compose media via CatalogMediaQuery (out of the DTO)"`.

---

### Task 14: Migrate POS sync, image import, and the variants command

**Files:**
- Modify: `app/Modules/POS/Presentation/Controllers/SyncController.php`
- Modify: `app/Modules/Product/Application/Services/ProductImageImportService.php`
- Modify: `app/Console/Commands/GenerateProductImageVariants.php`
- Test: `tests/Feature/Modules/POS/SyncImageContractTest.php`, `tests/Feature/Modules/Catalog/Media/ProductImageImportServiceMediaTest.php`

- [ ] **Step 1: Write the failing tests**

```php
// POS sync: the payload field stays `product.image_url` (one scalar, NOT a media array) and
// keeps the exact download-URL shape so the POS SQLite cache (keyed by product_id + remote_url) is untouched.
public function test_sync_emits_same_primary_image_url_field_and_shape(): void
{
    // seed a product with an UPLOAD primary; hit the sync endpoint.
    $payload = $this->getJson('/api/v1/pos/sync/...')->json('...');
    $url = $payload['image_url'];
    self::assertTrue($url === null || str_contains($url, '/images/') && str_contains($url, 'variant=sm'));
    self::assertArrayNotHasKey('media', $payload); // POS contract: single image_url, not an array
}

// ZIP import behavior over the new model
public function test_zip_import_creates_one_asset_and_primary_attachment(): void
{
    // process a ZIP with one SKU-matched image; assert exactly one media_assets row,
    // one media_attachments row with role=PRIMARY, and the returned image_id == the ATTACHMENT id.
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** — `SyncController`: obtain the tenant via `$this->companyContext->requireCompany()`, **batch** all product ids and call `forProducts($products->pluck('id')->all(), $company->tenant_id)` **once** (never `forProduct()` inside the `map()` loop), emitting the **same single `image_url`** field with the `variant=sm` download shape; remove the `primaryImage` eager-load. `ProductImageImportService`: replace `ProductImageService` calls with `MediaUploadService::uploadForProduct(...)` + `MediaAttachmentService::attach(... first→PRIMARY)`; the returned `image_id` is the **attachment id** (façade identity). `GenerateProductImageVariants` command: iterate `media_assets` (UPLOAD, IMAGE) and dispatch `GenerateRenditions($tenantId, $asset->id)`; keep the command signature/name.

- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `git commit -m "refactor: migrate POS sync, image import, variants command to media model"`.

---

### Task 15: Re-seed demo data, remove `ProductImage`, drop `product_images`

**Files:**
- Modify: `database/seeders/ProductImagePlaceholderSeeder.php` (+ any product-image seeding in demo/coffee-shop/parapharmacy seeders)
- Create: `database/migrations/tenant/2026_06_12_100004_drop_product_images_table.php`
- Delete: `app/Modules/Product/Domain/ProductImage.php`, `Product::images()/primaryImage()`, `ProductImageService.php`, `GenerateImageVariants.php`, `ImageVariantService.php`, old `ProductImageController.php`/`PublicProductImageController.php`
- Test: `tests/Feature/Modules/Catalog/Media/PlaceholderSeederTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_seeder_creates_external_url_primary_attachments_and_is_rerunnable(): void
{
    $this->seed(\Database\Seeders\ProductImagePlaceholderSeeder::class);
    $this->seed(\Database\Seeders\ProductImagePlaceholderSeeder::class); // idempotent
    $asset = MediaAsset::where('source', MediaSource::ExternalUrl)->first();
    self::assertSame(MediaStatus::Ready, $asset->status);
    self::assertTrue(MediaAttachment::where('role', MediaRole::Primary)->exists());
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Rewrite the seeder** to create `MediaAsset` (`source=EXTERNAL_URL`, `status=READY`, `storage_disk='url'`, `external_url=<placeholder>`) + a `MediaAttachment` (`owner_type=PRODUCT`, `role=PRIMARY`) per demo product (incl. the 24 Tunisia PB- hero images). Keep the existing idempotency guard. **Route external-URL creation through a single validated path** — `MediaUploadService::registerExternalUrl($tenantId, $productId, string $url): MediaAsset` — which validates `https` scheme, length ≤ 2048, and rejects non-URL/`http://`/localhost/private-IP literals (basic SSRF-safe, display-only). The seeder calls this helper; do not insert `external_url` rows raw.

- [ ] **Step 3b: Test the external-URL validation** (`tests/Feature/Modules/Catalog/Media/ExternalUrlValidationTest.php`): `registerExternalUrl` accepts a valid `https://…` and **rejects** `http://x`, `not-a-url`, a `>2048`-char string, and `https://localhost/…`/`https://127.0.0.1/…`. Run → it must drive the validation code (red→green).

- [ ] **Step 3c: Migrate/replace the existing image tests that import to-be-deleted classes** (they will break when Task 15 Step 5 deletes the classes — migrate them BEFORE deletion):
  - `tests/Feature/Product/ProductImageControllerTest.php` → port to the façade (`ProductImageFacadeTest`, Task 11) or delete if fully superseded.
  - `tests/Feature/Product/ProductImageTenantIsolationTest.php` → port to `ProductImageFacadeTenantIsolationTest` (Task 11).
  - `tests/Unit/Modules/Product/Application/Services/ProductImageServiceTest.php` → replace with `MediaUploadServiceTest`/`MediaAttachmentServiceTest` (Task 10).
  - `tests/Unit/Modules/Product/Application/Services/ImageVariantServiceTest.php` → replace with `RenditionServiceTest` (Task 8).
  - `tests/Unit/Modules/Product/Application/Jobs/GenerateImageVariantsTest.php` → replace with `GenerateRenditionsJobTest` (Task 9).
  Delete the originals in the same commit as the class deletions so nothing imports a removed symbol.

- [ ] **Step 4: Write the drop migration with a pre-drop row-count guard**

```php
public function up(): void
{
    if (Schema::hasTable('product_images')) {
        $count = DB::table('product_images')->count();
        \Illuminate\Support\Facades\Log::info('Dropping product_images', ['discarded_rows' => $count]);
        Schema::drop('product_images');
    }
}
public function down(): void { /* no-op: table replaced by media_* model; restore via re-seed */ }
```

- [ ] **Step 5: Delete the old classes & relations**, then run the targeted suites + static analysis to prove nothing references the removed symbols.

Run (the historical create-migration and the new drop-migration legitimately contain `product_images`, so exclude them; search ALL code surfaces, not just `app/ database/`):
```bash
cd ../..   # repo root
# NOTE: apps/pos is intentionally EXCLUDED — it has its own local SQLite `product_images` cache table
# (apps/pos/src/lib/db/migrations.ts) that legitimately stays. The POS *sync URL contract* is the
# real cross-app coupling and is regression-guarded by SyncImageContractTest (Task 14), not by this grep.
rg -n "ProductImage\b|ProductImageService|ImageVariantService|GenerateImageVariants|primaryImage|product_images" \
  apps/api/app apps/api/tests apps/api/database/seeders packages/shared apps/web \
  -g '!apps/api/database/migrations/tenant/2025_12_29_155412_create_product_images_table.php' \
  -g '!apps/api/database/migrations/tenant/2026_06_12_100004_drop_product_images_table.php' \
  && echo "STILL REFERENCED — fix before commit" || echo "clean"
cd apps/api
./vendor/bin/phpstan analyse app/Modules/Catalog app/Modules/Product --level=8
./vendor/bin/pint app/Modules/Catalog
./vendor/bin/deptrac
php artisan typescript:transform
```
Expected: `clean` (no matches in the searched scope), PHPStan 0 errors on new code, Pint clean, Deptrac green. The POS cache + its `product_images` SQLite table remain untouched (verified separately via the Task 14 sync-contract test).

- [ ] **Step 6: Run the full set of media/product feature tests added in this plan (scoped)**

Run:
```bash
cd apps/api && ./vendor/bin/phpunit --filter 'Catalog\\Media|ProductDataMediaParity|SyncImageContract|ProductImageFacade|ProductImageImportServiceMedia|ExternalUrlValidation'
# Confirm the OLD image test files are gone (deleted/migrated in Step 3c) — these must NOT exist post-Task-15:
test ! -f tests/Feature/Product/ProductImageControllerTest.php && test ! -f tests/Unit/Modules/Product/Application/Jobs/GenerateImageVariantsTest.php && echo "old tests migrated" || echo "OLD TESTS STILL PRESENT — migrate them (Step 3c)"
```
Expected: PASS (run the PG-only index test in the real-PG lane).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(catalog-media): re-seed demo to media model, drop product_images, remove ProductImage"
```

---

## Self-review checklist (done while writing — recorded here)

- **Spec coverage:** §2 model → Tasks 1-3; §3 layout → all; §4 contract/composition → Tasks 5,6,13; §5 consumers → Tasks 11,12,14,15; §6 ingestion/serve/tenancy/soft-delete → Tasks 7,8,9,10; §7 façade + ID contract → Tasks 11,12; §8 PRODUCT-only scope → owner_type used only with `Product` across tasks; §9 tests → each task is TDD. ✅
- **Single-primary** is both a DB partial index (Task 3) and service demote (Task 10). ✅
- **Tenancy:** `GenerateRenditions` carries `tenantId` + `BindsTenantContext` (Task 9). ✅
- **No orphaned symbols:** Task 15 greps for every removed symbol before commit. ✅
- **Type consistency:** `ProductMediaData{primary_image_url, media[]}`, `MediaAttachmentData{id=attachment id}`, `forProducts(ids,tenantId)` used identically in Tasks 5/6/13. ✅

## Risks / watch-items for the executor
- The plan **deletes** `ImageVariantService` in Task 15 — its GD routine must be copied into `ImageRenditionGenerator` in Task 8 first (don't reference the old class after deletion).
- Run the **PG-only** partial-index + façade tests against real Postgres; SQLite will skip the index test and cannot catch a malformed partial index.
- Keep the POS sync image-URL shape byte-identical (Task 14) — a change silently breaks the Tauri POS image cache (cross-app contract).
- Do **not** run the full PHPUnit suite; scope with `--filter`.
