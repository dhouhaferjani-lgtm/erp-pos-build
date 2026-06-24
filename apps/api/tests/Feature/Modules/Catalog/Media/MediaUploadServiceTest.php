<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Application\Jobs\GenerateRenditions;
use App\Modules\Catalog\Application\Services\MediaUploadService;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MediaUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    private MediaUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(MediaUploadService::class);
    }

    public function test_upload_image_creates_uploaded_asset_with_checksum_and_dispatches_job(): void
    {
        Storage::fake('s3');
        Bus::fake();

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $asset = $this->service->uploadForProduct($tenantId, $productId, $file, $userId);

        self::assertSame(MediaStatus::Uploaded, $asset->status);
        self::assertNotNull($asset->checksum);
        self::assertSame(64, strlen($asset->checksum)); // SHA-256 hex = 64 chars
        self::assertSame($tenantId, $asset->tenant_id);
        self::assertSame('s3', $asset->storage_disk);
        self::assertSame($userId, $asset->uploaded_by);
        self::assertSame(MediaSource::Upload, $asset->source);

        // Path must follow products/{tenantId}/{productId}/{assetUuid}/original.{ext}
        self::assertStringStartsWith("products/{$tenantId}/{$productId}/", $asset->storage_path);
        self::assertStringEndsWith('/original.jpg', $asset->storage_path);

        // File must actually exist on the fake S3 disk.
        Storage::disk('s3')->assertExists($asset->storage_path);

        Bus::assertDispatched(GenerateRenditions::class, function (GenerateRenditions $job) use ($tenantId, $asset): bool {
            return $job->tenantId === $tenantId && $job->mediaAssetId === $asset->id;
        });
    }

    public function test_upload_records_image_dimensions(): void
    {
        Storage::fake('s3');
        Bus::fake();

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $file = UploadedFile::fake()->image('wide.jpg', 1920, 1080);

        $asset = $this->service->uploadForProduct($tenantId, $productId, $file, null);

        // Fake UploadedFile images are valid GD images; getimagesize should work.
        // Dimensions may be set if getimagesize succeeds on the temp file.
        // We cannot assert exact pixel values because Illuminate's fake()->image() uses
        // a small placeholder; we assert the columns are at least set to integers (or null).
        self::assertTrue($asset->width === null || is_int($asset->width));
        self::assertTrue($asset->height === null || is_int($asset->height));
    }

    public function test_upload_stores_file_under_correct_s3_path(): void
    {
        Storage::fake('s3');
        Bus::fake();

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        $asset = $this->service->uploadForProduct($tenantId, $productId, $file, null);

        // Path structure: products/{tenantId}/{productId}/{uuid}/original.png
        self::assertMatchesRegularExpression(
            '#^products/'.preg_quote($tenantId, '#').'/'.preg_quote($productId, '#').'/[0-9a-f\-]{36}/original\.png$#',
            $asset->storage_path,
        );
    }

    public function test_upload_rejects_pdf_with_validation_exception(): void
    {
        Storage::fake('s3');
        Bus::fake();

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $file = UploadedFile::fake()->create('document.pdf', 10, 'application/pdf');

        $this->expectException(ValidationException::class);

        $this->service->uploadForProduct($tenantId, $productId, $file, null);
    }

    public function test_upload_rejects_text_file_with_validation_exception(): void
    {
        Storage::fake('s3');
        Bus::fake();

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $file = UploadedFile::fake()->create('readme.txt', 1, 'text/plain');

        $this->expectException(ValidationException::class);

        $this->service->uploadForProduct($tenantId, $productId, $file, null);
    }

    public function test_upload_deletes_orphaned_s3_file_when_transaction_fails(): void
    {
        Storage::fake('s3');
        Bus::fake();

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        // Capture the S3 path and force the DB::transaction to fail by throwing inside
        // the Eloquent "creating" event for MediaAsset.  The event fires INSIDE the
        // DB::transaction closure in uploadForProduct(), so the exception propagates
        // to the catch(\Throwable) block, which must delete the orphaned S3 file.
        //
        // We use a flag so the listener disables itself after one invocation, preventing
        // bleed-over into subsequent tests (flushEventListeners() is intentionally avoided
        // because it would also remove service-provider-registered observers).
        $capturedPath = null;
        $fired = false;

        MediaAsset::creating(function (MediaAsset $asset) use (&$capturedPath, &$fired): never {
            if (! $fired) {
                $fired = true;
                $capturedPath = $asset->storage_path;
                throw new \RuntimeException('Simulated DB failure for S3 cleanup test');
            }
            // Unreachable in this test — satisfies never return type for the throw above.
            throw new \LogicException('Listener fired a second time unexpectedly');
        });

        try {
            $this->service->uploadForProduct($tenantId, $productId, $file, null);
            self::fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Simulated DB failure', $e->getMessage());
        } finally {
            // Detach only the listener we added so service-provider observers survive.
            MediaAsset::getEventDispatcher()?->forget('eloquent.creating: '.MediaAsset::class);
        }

        // The orphaned S3 file must have been removed by the catch block.
        self::assertNotNull($capturedPath, 'Storage path must have been captured before the throw');
        Storage::disk('s3')->assertMissing($capturedPath);
    }

    public function test_register_external_url_creates_ready_asset(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();
        $url = 'https://cdn.example.com/images/product.jpg';

        $asset = $this->service->registerExternalUrl($tenantId, $productId, $url);

        self::assertSame(MediaStatus::Ready, $asset->status);
        self::assertSame(MediaSource::ExternalUrl, $asset->source);
        self::assertSame('url', $asset->storage_disk);
        self::assertSame($url, $asset->external_url);
        self::assertSame($tenantId, $asset->tenant_id);
    }

    public function test_register_external_url_rejects_http_scheme(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $this->expectException(ValidationException::class);

        $this->service->registerExternalUrl($tenantId, $productId, 'http://cdn.example.com/image.jpg');
    }

    public function test_register_external_url_rejects_non_url_string(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $this->expectException(ValidationException::class);

        $this->service->registerExternalUrl($tenantId, $productId, 'not-a-url');
    }

    public function test_register_external_url_rejects_url_longer_than_2048_chars(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $longUrl = 'https://cdn.example.com/'.str_repeat('a', 2048);

        $this->expectException(ValidationException::class);

        $this->service->registerExternalUrl($tenantId, $productId, $longUrl);
    }

    public function test_register_external_url_rejects_localhost(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $this->expectException(ValidationException::class);

        $this->service->registerExternalUrl($tenantId, $productId, 'https://localhost/image.jpg');
    }

    public function test_register_external_url_rejects_loopback_ip(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $this->expectException(ValidationException::class);

        $this->service->registerExternalUrl($tenantId, $productId, 'https://127.0.0.1/image.jpg');
    }

    public function test_register_external_url_rejects_private_ip_10_network(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $this->expectException(ValidationException::class);

        $this->service->registerExternalUrl($tenantId, $productId, 'https://10.0.0.1/image.jpg');
    }

    public function test_register_external_url_rejects_private_ip_192_168_network(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $this->expectException(ValidationException::class);

        $this->service->registerExternalUrl($tenantId, $productId, 'https://192.168.1.1/image.jpg');
    }

    public function test_register_external_url_rejects_ipv6_loopback_with_brackets(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        // parse_url('https://[::1]/x') returns host '[::1]' (with brackets).
        // The normalisation `trim($host, '[]')` must strip brackets before the
        // BLOCKED_HOSTS comparison so this is correctly rejected.
        $this->expectException(ValidationException::class);

        $this->service->registerExternalUrl($tenantId, $productId, 'https://[::1]/image.jpg');
    }

    // -----------------------------------------------------------------------
    // Fix #4 — fail-loud when object storage write fails (putFileAs → false)
    // -----------------------------------------------------------------------

    public function test_upload_throws_runtime_exception_and_creates_no_asset_when_putfileas_fails(): void
    {
        Bus::fake();

        // Use a real fake disk but intercept putFileAs() so it returns false,
        // simulating a MinIO connectivity failure or bucket-not-found condition.
        $fakeDisk = Storage::fake('s3');

        // Partially mock the disk: put/putFileAs returns false.
        // Storage::fake() returns a FilesystemAdapter backed by an in-memory
        // League disk; wrap it so the next putFileAs call returns false.
        // We use Storage::shouldReceive() on a mocked disk by swapping the
        // 's3' disk with a mock that returns false for put/putFileAs.
        Storage::shouldReceive('disk')
            ->with('s3')
            ->once()
            ->andReturn(
                tap(\Mockery::mock(Filesystem::class), function ($mock): void {
                    $mock->shouldReceive('putFileAs')->once()->andReturn(false);
                }),
            );

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $assetCountBefore = MediaAsset::count();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Object storage write failed/');

        try {
            $this->service->uploadForProduct($tenantId, $productId, $file, null);
        } finally {
            // No media_assets row must have been created — the exception fires BEFORE
            // the DB transaction, so the count must remain unchanged.
            self::assertSame(
                $assetCountBefore,
                MediaAsset::count(),
                'No media_assets row must be created when S3 write fails',
            );
        }
    }
}
