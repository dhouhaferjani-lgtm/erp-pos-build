# Product Image Pipeline: WebP Conversion + Thumbnails

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix critical image serving bugs and add server-side WebP conversion + thumbnail generation so both Web POS and Tauri POS display optimized product images.

**Architecture:** Images are uploaded to S3 (MinIO). A queued job generates WebP variants (small thumbnail 150w, medium 400w) using PHP GD. Variant paths are derived by convention from the original storage path — no schema changes. The download endpoint serves variants with cache headers and inline Content-Disposition. `ProductData` DTO includes a URL pointing to the small thumbnail for POS grids.

**Auth model:** The Web POS uses **Sanctum cookie-based SPA auth** (`withCredentials: true`, httpOnly session cookies). Browser `<img src>` tags to authenticated endpoints work because the browser sends session cookies on same-origin requests. This is confirmed in `apps/web/src/lib/api.ts` (lines 87-104) and `config/sanctum.php` (stateful domains include localhost:5173). No signed URLs or special auth handling needed.

**Tech Stack:** Laravel 12 + PHP GD (already installed in Docker), S3/MinIO, database queue, Vitest (frontend tests), PHPUnit (backend tests).

**Conventions reference:** [docs/conventions/README.md](../../conventions/README.md), [docs/conventions/07-DEPENDENCY-INJECTION.md](../../conventions/07-DEPENDENCY-INJECTION.md)

---

## File Structure

### Backend — New Files
| File | Responsibility |
|------|---------------|
| `app/Modules/Product/Application/Jobs/GenerateImageVariants.php` | Queue job: resizes + converts to WebP, stores variants to S3 |
| `app/Modules/Product/Application/Services/ImageVariantService.php` | Pure logic: resize with GD, WebP encode, variant path derivation |
| `tests/Unit/Modules/Product/Application/Services/ImageVariantServiceTest.php` | Unit tests for variant path derivation and image processing |
| `tests/Unit/Modules/Product/Application/Jobs/GenerateImageVariantsTest.php` | Unit tests for job dispatch and execution |
| `app/Console/Commands/GenerateProductImageVariants.php` | Artisan command to backfill variants for existing images |

### Backend — Modified Files
| File | Change |
|------|--------|
| `app/Modules/Product/Application/Services/ProductImageService.php` | 1. Fix `download()` → `response()` (inline serving). 2. Add `serve()` method with variant support + cache headers. 3. Dispatch `GenerateImageVariants` job after upload. 4. Delete variants on image delete. |
| `app/Modules/Product/Application/DTOs/ProductData.php` | Already has `primary_image_url` — update to append `?variant=sm` query param |
| `app/Modules/Product/Presentation/Controllers/ProductImageController.php` | Update `download()` to accept `?variant=sm\|md` and delegate to `serve()` |
| `app/Modules/Product/Presentation/Controllers/ProductController.php` | Load `primaryImage` on `show()`, `store()`, `update()` |
| `app/Modules/POS/Presentation/Controllers/SyncController.php` | Already returns `image_url` — update to append `?variant=sm` |
| `app/Modules/Product/Application/Services/ProductImageImportService.php` | No change needed — already calls `ProductImageService::upload()` which will dispatch the job |

### Frontend — Modified Files
| File | Change |
|------|--------|
| `apps/web/src/features/products/components/ProductImageGallery.tsx` | No change — uses download URL which now serves inline |

### Variant Path Convention
Original: `products/{tid}/{pid}/{uuid}.jpg`
Small thumbnail: `products/{tid}/{pid}/{uuid}_sm.webp` (150px wide)
Medium thumbnail: `products/{tid}/{pid}/{uuid}_md.webp` (400px wide)

No database schema changes. Paths are derived deterministically from the original `storage_path`.

---

## Task 1: Fix Content-Disposition Bug (Critical)

**Why:** The `download()` method uses `$disk->download()` which sets `Content-Disposition: attachment`. Browser `<img src>` tags will trigger a file download instead of rendering the image inline. This means product images cannot display in the Web POS.

**Files:**
- Modify: `apps/api/app/Modules/Product/Application/Services/ProductImageService.php:155-160`
- Test: `tests/Unit/Modules/Product/Application/Services/ProductImageServiceTest.php` (create if not exists, or add test)

- [ ] **Step 1: Write the failing test**

Create or update `apps/api/tests/Unit/Modules/Product/Application/Services/ProductImageServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product\Application\Services;

use App\Modules\Product\Application\Services\ProductImageService;
use App\Modules\Product\Domain\ProductImage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageServiceTest extends TestCase
{
    private ProductImageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProductImageService();
    }

    public function test_serve_returns_inline_content_disposition(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('products/tid/pid/test.jpg', 'fake-image-content');

        $image = new ProductImage();
        $image->storage_path = 'products/tid/pid/test.jpg';
        $image->storage_disk = 's3';
        $image->original_filename = 'photo.jpg';
        $image->mime_type = 'image/jpeg';

        $response = $this->service->serve($image);

        $this->assertEquals(200, $response->getStatusCode());
        $contentDisposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('inline', $contentDisposition);
        $this->assertStringNotContainsString('attachment', $contentDisposition);
    }

    public function test_serve_includes_cache_headers(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('products/tid/pid/test.jpg', 'fake-image-content');

        $image = new ProductImage();
        $image->storage_path = 'products/tid/pid/test.jpg';
        $image->storage_disk = 's3';
        $image->original_filename = 'photo.jpg';
        $image->mime_type = 'image/jpeg';

        $response = $this->service->serve($image);

        $this->assertEquals('public, max-age=86400', $response->headers->get('Cache-Control'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test --filter=ProductImageServiceTest -v`
Expected: FAIL — `serve()` method doesn't exist yet.

- [ ] **Step 3: Implement the `serve()` method (without variant support — added in Task 5)**

In `apps/api/app/Modules/Product/Application/Services/ProductImageService.php`, add a new `serve()` method (keep the existing `download()` for backward compat):

```php
/**
 * Serve an image inline (for <img> tags) with cache headers.
 * Variant support is added in Task 5 after ImageVariantService is created.
 */
public function serve(ProductImage $image): StreamedResponse
{
    $disk = Storage::disk($image->storage_disk);

    /** @var StreamedResponse $response */
    $response = $disk->response($image->storage_path, $image->original_filename, [
        'Content-Type' => $image->mime_type,
        'Cache-Control' => 'public, max-age=86400',
    ]);

    return $response;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && php artisan test --filter=ProductImageServiceTest -v`
Expected: PASS

- [ ] **Step 5: Update `ProductImageController::download()` to use `serve()`**

In `apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php`, update the `download` method:

```php
/**
 * Serve an image file inline (for <img> tags).
 * Variant support (?variant=sm|md) is added in Task 5.
 */
public function download(Product $product, ProductImage $image): StreamedResponse
{
    return $this->imageService->serve($image);
}
```

- [ ] **Step 6: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Product/ --no-progress`
Expected: No errors

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Product/Application/Services/ProductImageService.php \
       apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php \
       apps/api/tests/Unit/Modules/Product/Application/Services/ProductImageServiceTest.php
git commit -m "fix(images): serve images inline with cache headers instead of Content-Disposition: attachment"
```

---

## Task 2: Load `primaryImage` on All Product Endpoints

**Why:** `ProductController::show()`, `store()`, and `update()` don't eager-load `primaryImage`, so `ProductData::fromModel()` always returns `primary_image_url: null` for these endpoints. The `ProductInfoModal` fetches via `show()` and will never display an image.

**Files:**
- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:200-202, 270, 428-429`

- [ ] **Step 1: Update `show()` to load `primaryImage`**

In `ProductController::show()`, after the product is loaded (around line 200), add `primaryImage` to the conditional loads. Change:

```php
$productModel = Product::where('company_id', $this->companyContext->requireCompanyId())
    ->where('id', $product)
    ->first();
```

to:

```php
$productModel = Product::where('company_id', $this->companyContext->requireCompanyId())
    ->where('id', $product)
    ->with('primaryImage')
    ->first();
```

- [ ] **Step 2: Update `store()` to load `primaryImage` before response**

After product creation (after all metadata loading, before the return), add:

```php
$product->load('primaryImage');
```

Add this just before the `return response()->json(...)` at the end of `store()` (around line 328).

- [ ] **Step 3: Update `update()` to load `primaryImage` on fresh model**

After `$freshProduct = $productModel->fresh();` (around line 429), add `primaryImage` to the subsequent loads. Add:

```php
$freshProduct->load('primaryImage');
```

just before the `return response()->json(...)` at the end of `update()`.

- [ ] **Step 4: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Product/Presentation/Controllers/ProductController.php --no-progress`
Expected: No errors

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php
git commit -m "fix(images): eager-load primaryImage on show/store/update endpoints"
```

---

## Task 3: Create ImageVariantService (Core Logic)

**Why:** Centralized service for variant path derivation, image resizing, and WebP conversion. Pure logic — no I/O — making it highly testable.

**Files:**
- Create: `apps/api/app/Modules/Product/Application/Services/ImageVariantService.php`
- Create: `apps/api/tests/Unit/Modules/Product/Application/Services/ImageVariantServiceTest.php`

- [ ] **Step 1: Write failing tests for variant path derivation**

Create `apps/api/tests/Unit/Modules/Product/Application/Services/ImageVariantServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product\Application\Services;

use App\Modules\Product\Application\Services\ImageVariantService;
use PHPUnit\Framework\TestCase;

class ImageVariantServiceTest extends TestCase
{
    public function test_variant_path_appends_suffix_and_changes_extension_to_webp(): void
    {
        $original = 'products/tid/pid/abc123.jpg';

        $this->assertSame(
            'products/tid/pid/abc123_sm.webp',
            ImageVariantService::variantPath($original, 'sm')
        );
    }

    public function test_variant_path_works_with_png(): void
    {
        $original = 'products/tid/pid/abc123.png';

        $this->assertSame(
            'products/tid/pid/abc123_md.webp',
            ImageVariantService::variantPath($original, 'md')
        );
    }

    public function test_variant_path_works_with_webp_original(): void
    {
        $original = 'products/tid/pid/abc123.webp';

        $this->assertSame(
            'products/tid/pid/abc123_sm.webp',
            ImageVariantService::variantPath($original, 'sm')
        );
    }

    public function test_all_variant_paths_returns_both_sizes(): void
    {
        $original = 'products/tid/pid/abc123.jpg';
        $paths = ImageVariantService::allVariantPaths($original);

        $this->assertSame([
            'sm' => 'products/tid/pid/abc123_sm.webp',
            'md' => 'products/tid/pid/abc123_md.webp',
        ], $paths);
    }

    public function test_resize_and_encode_produces_valid_webp(): void
    {
        // Create a 800x600 test image in memory
        $source = imagecreatetruecolor(800, 600);
        $red = imagecolorallocate($source, 255, 0, 0);
        imagefill($source, 0, 0, $red);

        ob_start();
        imagejpeg($source, null, 90);
        $jpegData = ob_get_clean();
        imagedestroy($source);

        $service = new ImageVariantService();
        $webpData = $service->resizeAndEncode($jpegData, 150);

        // Verify it's valid WebP (starts with RIFF...WEBP)
        $this->assertStringStartsWith('RIFF', $webpData);
        $this->assertStringContainsString('WEBP', substr($webpData, 0, 12));

        // Verify dimensions
        $img = imagecreatefromstring($webpData);
        $this->assertSame(150, imagesx($img));
        // Height should be proportionally scaled: 600 * (150/800) = 112.5 → 113
        $this->assertEqualsWithDelta(113, imagesy($img), 1);
        imagedestroy($img);
    }

    public function test_resize_and_encode_does_not_upscale(): void
    {
        // Create a 100x80 image — smaller than target 150px
        $source = imagecreatetruecolor(100, 80);
        ob_start();
        imagejpeg($source, null, 90);
        $jpegData = ob_get_clean();
        imagedestroy($source);

        $service = new ImageVariantService();
        $webpData = $service->resizeAndEncode($jpegData, 150);

        $img = imagecreatefromstring($webpData);
        // Should keep original dimensions, not upscale
        $this->assertSame(100, imagesx($img));
        $this->assertSame(80, imagesy($img));
        imagedestroy($img);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/api && php artisan test --filter=ImageVariantServiceTest -v`
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Implement ImageVariantService**

Create `apps/api/app/Modules/Product/Application/Services/ImageVariantService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use RuntimeException;

class ImageVariantService
{
    /** @var array<string, int> Variant name → max width in pixels */
    public const VARIANTS = [
        'sm' => 150,
        'md' => 400,
    ];

    public const WEBP_QUALITY = 80;

    /**
     * Derive the S3 path for a variant from the original storage path.
     *
     * Example: products/tid/pid/abc.jpg + 'sm' → products/tid/pid/abc_sm.webp
     */
    public static function variantPath(string $originalPath, string $variant): string
    {
        $info = pathinfo($originalPath);

        return $info['dirname'] . '/' . $info['filename'] . '_' . $variant . '.webp';
    }

    /**
     * Get all variant paths for an original image.
     *
     * @return array<string, string>
     */
    public static function allVariantPaths(string $originalPath): array
    {
        $paths = [];
        foreach (array_keys(self::VARIANTS) as $variant) {
            $paths[$variant] = self::variantPath($originalPath, $variant);
        }

        return $paths;
    }

    /**
     * Resize image data to a max width and encode as WebP.
     *
     * Does not upscale — if the image is smaller than maxWidth, it converts to WebP at original size.
     *
     * @param  string  $imageData  Raw image file contents (JPEG, PNG, WebP, GIF)
     * @param  int  $maxWidth  Maximum width in pixels
     * @return string Raw WebP file contents
     */
    public function resizeAndEncode(string $imageData, int $maxWidth): string
    {
        $source = @imagecreatefromstring($imageData);
        if ($source === false) {
            throw new RuntimeException('Failed to create image from data — unsupported or corrupt format');
        }

        $origWidth = imagesx($source);
        $origHeight = imagesy($source);

        // Don't upscale
        if ($origWidth <= $maxWidth) {
            $newWidth = $origWidth;
            $newHeight = $origHeight;
        } else {
            $newWidth = $maxWidth;
            $newHeight = (int) round($origHeight * ($maxWidth / $origWidth));
        }

        // Resize
        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG/WebP/GIF
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($source);

        // Encode as WebP
        ob_start();
        imagewebp($resized, null, self::WEBP_QUALITY);
        $webpData = ob_get_clean();
        imagedestroy($resized);

        if ($webpData === false || $webpData === '') {
            throw new RuntimeException('Failed to encode image as WebP');
        }

        return $webpData;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test --filter=ImageVariantServiceTest -v`
Expected: All 5 tests PASS

- [ ] **Step 5: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Product/Application/Services/ImageVariantService.php --no-progress`
Expected: No errors

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Product/Application/Services/ImageVariantService.php \
       apps/api/tests/Unit/Modules/Product/Application/Services/ImageVariantServiceTest.php
git commit -m "feat(images): add ImageVariantService for WebP conversion and thumbnail generation"
```

---

## Task 4: Create GenerateImageVariants Job

**Why:** Async job that runs after image upload. Downloads the original from S3, generates sm/md WebP variants, uploads them back to S3. Follows the established job pattern from `ProcessProductImageImport`.

**Files:**
- Create: `apps/api/app/Modules/Product/Application/Jobs/GenerateImageVariants.php`
- Create: `apps/api/tests/Unit/Modules/Product/Application/Jobs/GenerateImageVariantsTest.php`

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Unit/Modules/Product/Application/Jobs/GenerateImageVariantsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product\Application\Jobs;

use App\Modules\Product\Application\Jobs\GenerateImageVariants;
use App\Modules\Product\Application\Services\ImageVariantService;
use App\Modules\Product\Domain\ProductImage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateImageVariantsTest extends TestCase
{
    public function test_job_generates_sm_and_md_variants_on_s3(): void
    {
        Storage::fake('s3');

        // Create a real JPEG test image
        $source = imagecreatetruecolor(800, 600);
        $color = imagecolorallocate($source, 100, 150, 200);
        imagefill($source, 0, 0, $color);
        ob_start();
        imagejpeg($source, null, 90);
        $jpegData = ob_get_clean();
        imagedestroy($source);

        $storagePath = 'products/tid/pid/test-uuid.jpg';
        Storage::disk('s3')->put($storagePath, $jpegData);

        $image = new ProductImage();
        $image->id = 'img-001';
        $image->storage_path = $storagePath;
        $image->storage_disk = 's3';
        $image->mime_type = 'image/jpeg';

        $job = new GenerateImageVariants($image->id, $image->storage_path, $image->storage_disk);
        $job->handle(new ImageVariantService());

        // Verify variants were created
        Storage::disk('s3')->assertExists('products/tid/pid/test-uuid_sm.webp');
        Storage::disk('s3')->assertExists('products/tid/pid/test-uuid_md.webp');

        // Verify small variant dimensions
        $smData = Storage::disk('s3')->get('products/tid/pid/test-uuid_sm.webp');
        $smImg = imagecreatefromstring($smData);
        $this->assertSame(150, imagesx($smImg));
        imagedestroy($smImg);

        // Verify medium variant dimensions
        $mdData = Storage::disk('s3')->get('products/tid/pid/test-uuid_md.webp');
        $mdImg = imagecreatefromstring($mdData);
        $this->assertSame(400, imagesx($mdImg));
        imagedestroy($mdImg);
    }

    public function test_job_is_idempotent_does_not_fail_if_variants_exist(): void
    {
        Storage::fake('s3');

        $source = imagecreatetruecolor(200, 100);
        ob_start();
        imagejpeg($source, null, 90);
        $jpegData = ob_get_clean();
        imagedestroy($source);

        $storagePath = 'products/tid/pid/existing.jpg';
        Storage::disk('s3')->put($storagePath, $jpegData);
        // Pre-existing variant
        Storage::disk('s3')->put('products/tid/pid/existing_sm.webp', 'old-data');

        $job = new GenerateImageVariants('img-002', $storagePath, 's3');
        $job->handle(new ImageVariantService());

        // Should overwrite with new data
        Storage::disk('s3')->assertExists('products/tid/pid/existing_sm.webp');
        $newData = Storage::disk('s3')->get('products/tid/pid/existing_sm.webp');
        $this->assertNotSame('old-data', $newData);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test --filter=GenerateImageVariantsTest -v`
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Implement the job**

Create `apps/api/app/Modules/Product/Application/Jobs/GenerateImageVariants.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Jobs;

use App\Modules\Product\Application\Services\ImageVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class GenerateImageVariants implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120; // 2 minutes per image

    /** @var array<int, int> Backoff in seconds between retries */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $imageId,
        public readonly string $storagePath,
        public readonly string $storageDisk,
    ) {
        $this->onQueue('images');
    }

    public function handle(ImageVariantService $variantService): void
    {
        $disk = Storage::disk($this->storageDisk);

        if (! $disk->exists($this->storagePath)) {
            Log::warning('GenerateImageVariants: original image not found', [
                'image_id' => $this->imageId,
                'path' => $this->storagePath,
            ]);

            return;
        }

        /** @var string $originalData */
        $originalData = $disk->get($this->storagePath);

        $generated = 0;
        foreach (ImageVariantService::VARIANTS as $variant => $maxWidth) {
            $variantPath = ImageVariantService::variantPath($this->storagePath, $variant);

            try {
                $webpData = $variantService->resizeAndEncode($originalData, $maxWidth);
                $disk->put($variantPath, $webpData);
                $generated++;
            } catch (\Throwable $e) {
                Log::error('GenerateImageVariants: failed to generate variant', [
                    'image_id' => $this->imageId,
                    'variant' => $variant,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('GenerateImageVariants: completed', [
            'image_id' => $this->imageId,
            'generated' => $generated,
            'total' => count(ImageVariantService::VARIANTS),
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test --filter=GenerateImageVariantsTest -v`
Expected: All 2 tests PASS

- [ ] **Step 5: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Product/Application/Jobs/GenerateImageVariants.php --no-progress`
Expected: No errors

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Product/Application/Jobs/GenerateImageVariants.php \
       apps/api/tests/Unit/Modules/Product/Application/Jobs/GenerateImageVariantsTest.php
git commit -m "feat(images): add GenerateImageVariants queue job for async WebP conversion"
```

---

## Task 5: Wire Up the Pipeline — Dispatch on Upload + Delete Variants

**Why:** Connect the job to the upload flow so every new image automatically gets WebP variants. Also clean up variants when an image is deleted.

**Files:**
- Modify: `apps/api/app/Modules/Product/Application/Services/ProductImageService.php`
- Update: `apps/api/tests/Unit/Modules/Product/Application/Services/ProductImageServiceTest.php`

- [ ] **Step 1: Write the failing test for dispatch on upload**

Add to `ProductImageServiceTest.php`:

```php
use App\Modules\Product\Application\Jobs\GenerateImageVariants;
use Illuminate\Support\Facades\Bus;
use Illuminate\Http\UploadedFile;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Add RefreshDatabase trait to the class

public function test_upload_dispatches_variant_generation_job(): void
{
    Bus::fake([GenerateImageVariants::class]);
    Storage::fake('s3');

    // Create a real product in the database (requires seeded tenant/company)
    // Use a simplified approach: mock the DB transaction
    $product = Product::factory()->create();

    $file = UploadedFile::fake()->image('product.jpg', 800, 600);

    $image = $this->service->upload($product, $file);

    Bus::assertDispatched(GenerateImageVariants::class, function ($job) use ($image) {
        return $job->imageId === $image->id
            && $job->storagePath === $image->storage_path
            && $job->storageDisk === 's3';
    });
}
```

Note: If `Product::factory()` requires complex setup (tenant, company, etc.), use whatever factory pattern exists in the test suite. Check existing tests for reference patterns. If factories aren't set up, create the product record manually with the required FK fields using valid UUIDs.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test --filter=test_upload_dispatches_variant_generation_job -v`
Expected: FAIL — no job dispatched.

- [ ] **Step 3: Add dispatch to `upload()` and variant cleanup to `delete()`**

In `ProductImageService.php`:

Add import at top:
```php
use App\Modules\Product\Application\Jobs\GenerateImageVariants;
use App\Modules\Product\Application\Services\ImageVariantService;
```

Update `upload()` — after `$image = ProductImage::create(...)` and before `return $image;`, add:

```php
// Dispatch async WebP variant generation
GenerateImageVariants::dispatch(
    $image->id,
    $image->storage_path,
    $image->storage_disk,
);
```

Update `delete()` — after `Storage::disk($image->storage_disk)->delete($image->storage_path);`, add:

```php
// Delete WebP variants
$variantPaths = ImageVariantService::allVariantPaths($image->storage_path);
foreach ($variantPaths as $variantPath) {
    $disk->delete($variantPath);
}
```

Note: `$disk` is not available in the current `delete()` scope. Assign it before the delete line:
```php
$disk = Storage::disk($image->storage_disk);
$disk->delete($image->storage_path);

// Delete WebP variants
$variantPaths = ImageVariantService::allVariantPaths($image->storage_path);
foreach ($variantPaths as $variantPath) {
    $disk->delete($variantPath);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && php artisan test --filter=ProductImageServiceTest -v`
Expected: All tests PASS

- [ ] **Step 5: Add variant support to `serve()` and controller**

Update `ProductImageService::serve()` to accept an optional variant parameter:

```php
/**
 * Serve an image inline (for <img> tags) with cache headers.
 * Optionally serves a WebP variant (sm, md) if it exists, falling back to original.
 */
public function serve(ProductImage $image, ?string $variant = null): StreamedResponse
{
    $disk = Storage::disk($image->storage_disk);
    $path = $image->storage_path;
    $mimeType = $image->mime_type;

    if ($variant !== null) {
        $variantPath = ImageVariantService::variantPath($image->storage_path, $variant);
        if ($disk->exists($variantPath)) {
            $path = $variantPath;
            $mimeType = 'image/webp';
        }
    }

    /** @var StreamedResponse $response */
    $response = $disk->response($path, $image->original_filename, [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=86400',
    ]);

    return $response;
}
```

Update `ProductImageController::download()` to pass the variant:

```php
public function download(Request $request, Product $product, ProductImage $image): StreamedResponse
{
    $variant = $request->query('variant');
    $validVariants = ['sm', 'md'];
    $resolvedVariant = is_string($variant) && in_array($variant, $validVariants, true) ? $variant : null;

    return $this->imageService->serve($image, $resolvedVariant);
}
```

Add import to `ProductImageController.php`:
```php
use Illuminate\Http\Request;
```
(Note: `Request` is already imported in this controller.)

- [ ] **Step 6: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Product/Application/Services/ProductImageService.php --no-progress`
Expected: No errors

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Product/Application/Services/ProductImageService.php \
       apps/api/tests/Unit/Modules/Product/Application/Services/ProductImageServiceTest.php
git commit -m "feat(images): dispatch variant generation on upload, clean up variants on delete"
```

---

## Task 6: Update URLs to Request Thumbnail Variants

**Why:** `ProductData` and `SyncController` should point to the small WebP thumbnail for POS grids, not the full original image.

**Files:**
- Modify: `apps/api/app/Modules/Product/Application/DTOs/ProductData.php`
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php`

- [ ] **Step 1: Update `ProductData::fromModel()` to append `?variant=sm`**

In `apps/api/app/Modules/Product/Application/DTOs/ProductData.php`, change the `primary_image_url` line:

```php
primary_image_url: $product->relationLoaded('primaryImage') && $product->primaryImage !== null
    ? URL::route('products.images.download', [
        'product' => $product->id,
        'image' => $product->primaryImage->id,
        'variant' => 'sm',
    ])
    : null,
```

- [ ] **Step 2: Update `SyncController::pull()` to append `?variant=sm`**

In `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php`, update the `image_url` generation:

```php
$attributes['image_url'] = $product->primaryImage !== null
    ? route('products.images.download', [
        'product' => $product->id,
        'image' => $product->primaryImage->id,
        'variant' => 'sm',
    ])
    : null;
```

- [ ] **Step 3: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Product/Application/DTOs/ProductData.php app/Modules/POS/Presentation/Controllers/SyncController.php --no-progress`
Expected: No errors

- [ ] **Step 4: Run frontend TypeScript check**

Run: `cd apps/web && npx tsc --noEmit`
Expected: No errors (frontend unchanged, just URL content changes)

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Product/Application/DTOs/ProductData.php \
       apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php
git commit -m "feat(images): serve small WebP thumbnails for POS product images"
```

---

## Task 7: Backfill Command for Existing Images

**Why:** Existing product images don't have WebP variants yet. An artisan command dispatches `GenerateImageVariants` for all images that are missing variants.

**Files:**
- Create: `apps/api/app/Console/Commands/GenerateProductImageVariants.php`

- [ ] **Step 1: Create the command**

Create `apps/api/app/Console/Commands/GenerateProductImageVariants.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Product\Application\Jobs\GenerateImageVariants;
use App\Modules\Product\Application\Services\ImageVariantService;
use App\Modules\Product\Domain\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateProductImageVariants extends Command
{
    protected $signature = 'products:generate-image-variants
        {--force : Regenerate even if variants already exist}
        {--product= : Process only images for a specific product ID}';

    protected $description = 'Generate WebP thumbnail variants for existing product images';

    public function handle(): int
    {
        $query = ProductImage::query();

        if ($this->option('product')) {
            $query->where('product_id', $this->option('product'));
        }

        $total = $query->count();
        $dispatched = 0;
        $skipped = 0;

        $this->info("Processing {$total} product images...");

        $query->chunkById(100, function ($images) use (&$dispatched, &$skipped): void {
            /** @var ProductImage $image */
            foreach ($images as $image) {
                if (! $this->option('force')) {
                    $smPath = ImageVariantService::variantPath($image->storage_path, 'sm');
                    if (Storage::disk($image->storage_disk)->exists($smPath)) {
                        $skipped++;
                        continue;
                    }
                }

                GenerateImageVariants::dispatch(
                    $image->id,
                    $image->storage_path,
                    $image->storage_disk,
                );
                $dispatched++;
            }
        });

        $this->info("Dispatched: {$dispatched} jobs. Skipped: {$skipped} (variants exist).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Verify the command registers**

Run: `cd apps/api && php artisan products:generate-image-variants --help`
Expected: Shows command help with `--force` and `--product` options.

- [ ] **Step 3: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Console/Commands/GenerateProductImageVariants.php --no-progress`
Expected: No errors

- [ ] **Step 4: Commit**

```bash
git add apps/api/app/Console/Commands/GenerateProductImageVariants.php
git commit -m "feat(images): add artisan command to backfill WebP variants for existing images"
```

---

## Task 8: Final Verification — End-to-End

**Why:** Verify the full flow works: upload → job dispatched → variants generated → download endpoint serves variant → URL in product response works.

- [ ] **Step 1: Run all backend tests**

Run: `cd apps/api && php artisan test --filter=ImageVariant -v && php artisan test --filter=ProductImageService -v`
Expected: All tests pass

- [ ] **Step 2: Run PHPStan on all modified files**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Product/ app/Modules/POS/ app/Console/Commands/GenerateProductImageVariants.php --no-progress`
Expected: No errors

- [ ] **Step 3: Run frontend tests**

Run: `cd apps/web && npx vitest run src/features/pos/molecules/ProductCard/ProductCard.test.tsx src/features/pos/organisms/ProductInfoModal/ProductInfoModal.test.tsx --reporter=verbose`
Expected: All image-related tests pass

- [ ] **Step 4: Run TypeScript check**

Run: `cd apps/web && npx tsc --noEmit`
Expected: No errors

- [ ] **Step 5: Run code style**

Run: `cd apps/api && ./vendor/bin/pint --test`
Expected: No style issues (or fix with `./vendor/bin/pint`)

- [ ] **Step 6: Final commit if any fixups needed**

```bash
git add -A
git commit -m "chore: fix style/lint issues from image pipeline implementation"
```

---

## Post-Implementation Notes

### Queue Worker
The `images` queue must be processed. In development:
```bash
cd apps/api && php artisan queue:work --queue=images
```

In production (Horizon or supervisor), add `images` to the queue list.

### Backfill
After deploying, run:
```bash
php artisan products:generate-image-variants
```

This dispatches jobs for all existing product images. Monitor with `php artisan queue:work --queue=images`.

### How Tauri POS Benefits
The Tauri POS sync endpoint (`/pos/sync/pull`) now returns `image_url` pointing to the `?variant=sm` download URL. The Tauri image cache (`imageCache.ts`) will download this URL, which serves a small WebP thumbnail instead of the full original JPEG. This means:
- Faster initial downloads (smaller files)
- Less disk space used in the local cache
- WebP is natively supported by the Tauri webview

### Future Enhancements (not in scope)
- **CDN layer**: Put CloudFront/nginx in front of S3 for direct serving without going through Laravel
- **Responsive `srcset`**: Serve `sm` for grid, `md` for detail views using `<img srcset>`
- **Progressive loading**: Blur-up placeholder from base64-encoded tiny thumbnail
- **PDF thumbnails**: Generate preview images from PDF uploads (requires `imagick` extension)
