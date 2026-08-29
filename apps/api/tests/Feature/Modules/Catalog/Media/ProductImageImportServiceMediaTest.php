<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Application\Services\ProductImageImportService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Verifies that ProductImageImportService now creates MediaAsset + MediaAttachment
 * rows (via MediaUploadService + MediaAttachmentService) instead of the legacy
 * ProductImage rows (via the deleted ProductImageService).
 *
 * Contract:
 *  - A ZIP with one SKU-matched image must create exactly one media_assets row.
 *  - Exactly one media_attachments row with role=PRIMARY must be created.
 *  - The returned image_id must equal the attachment id (façade identity), NOT the asset id.
 */
final class ProductImageImportServiceMediaTest extends TestCase
{
    use RefreshDatabase;

    private ProductImageImportService $service;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->service = $this->app->make(ProductImageImportService::class);
    }

    public function test_zip_import_creates_one_asset_and_primary_attachment(): void
    {
        // Use a SKU without multiple dashes so the filename-parsing strategy
        // (Strategy 1: exact match) returns the full SKU unchanged.
        $sku = 'TESTSKU001';
        Product::factory()->create([
            'sku' => $sku,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $zipPath = $this->makeZipWithImage("{$sku}.jpg");

        $importJob = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'type' => ImportType::ProductImages,
            'status' => ImportStatus::Pending,
            'original_filename' => 'test.zip',
            'file_path' => $zipPath,
        ]);

        $results = $this->service->processZipImport($importJob, $zipPath);

        // Exactly one result for the one image in the ZIP.
        $this->assertCount(1, $results);
        $result = $results[0];

        $this->assertTrue($result['success'], 'Import must succeed for a matched SKU: '.($result['error'] ?? ''));
        $this->assertSame($sku, $result['sku']);

        // --- DB assertions ---

        // Exactly one media_assets row must have been created.
        $assetCount = MediaAsset::where('source', MediaSource::Upload)->count();
        self::assertSame(1, $assetCount, 'Expected exactly one media_assets row (source=UPLOAD)');

        /** @var MediaAsset $asset */
        $asset = MediaAsset::where('source', MediaSource::Upload)->first();
        self::assertSame(MediaStatus::Ready, $asset->status, 'Image assets are READY on upload (BUG-005 A2)');

        // Exactly one media_attachments row with role=PRIMARY must exist.
        $attachmentCount = MediaAttachment::where('role', MediaRole::Primary)->count();
        self::assertSame(1, $attachmentCount, 'Expected exactly one media_attachments row (role=PRIMARY)');

        /** @var MediaAttachment $attachment */
        $attachment = MediaAttachment::where('role', MediaRole::Primary)->first();
        self::assertSame(MediaOwnerType::Product, $attachment->owner_type);

        // The returned image_id must be the ATTACHMENT id (façade identity), not the asset id.
        self::assertSame(
            $attachment->id,
            $result['image_id'],
            'image_id in the result must equal the attachment id (façade identity)',
        );

        // Sanity: image_id must NOT be the asset id.
        self::assertNotSame(
            $asset->id,
            $result['image_id'],
            'image_id must NOT be the asset id — it is the attachment id',
        );
    }

    public function test_zip_import_unmatched_sku_returns_failure_row_and_no_media_rows(): void
    {
        $zipPath = $this->makeZipWithImage('NONEXISTENTSKU.jpg');

        $importJob = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'type' => ImportType::ProductImages,
            'status' => ImportStatus::Pending,
            'original_filename' => 'test.zip',
            'file_path' => $zipPath,
        ]);

        $results = $this->service->processZipImport($importJob, $zipPath);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['success']);
        self::assertSame(0, MediaAsset::count(), 'No media_assets rows for unmatched SKU');
        self::assertSame(0, MediaAttachment::count(), 'No media_attachments rows for unmatched SKU');
    }

    /**
     * Create a temporary ZIP file containing one 1×1 JPEG image.
     *
     * @return string Absolute path to the ZIP on disk.
     */
    private function makeZipWithImage(string $imageFilename): string
    {
        $tempDir = sys_get_temp_dir().'/pii-test-'.Str::random(8);
        mkdir($tempDir, 0755, true);

        // Create a minimal valid JPEG (1×1 pixel).
        $jpegPath = $tempDir.'/'.$imageFilename;
        $img = imagecreatetruecolor(1, 1);
        imagejpeg($img, $jpegPath, 80);
        imagedestroy($img);

        $zipPath = $tempDir.'/import.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFile($jpegPath, $imageFilename);
        $zip->close();

        // Register cleanup so the ZIP survives until end of test.
        $this->beforeApplicationDestroyed(function () use ($tempDir): void {
            if (is_dir($tempDir)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
                }
                rmdir($tempDir);
            }
        });

        return $zipPath;
    }
}
