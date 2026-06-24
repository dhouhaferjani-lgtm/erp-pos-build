<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Media\Application\Services\RenditionService;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Enums\RenditionFormat;
use App\Modules\Media\Domain\Media\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RenditionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A minimal valid JPEG (20×20 pixels) encoded as base64.
     * Generated inline via GD to ensure portability.
     */
    private function tinyJpegBytes(): string
    {
        $img = imagecreatetruecolor(20, 20);
        $red = imagecolorallocate($img, 220, 50, 50);
        imagefill($img, 0, 0, $red);

        ob_start();
        imagejpeg($img, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($img);

        self::assertNotFalse($bytes, 'GD could not create JPEG bytes in test setup');

        return (string) $bytes;
    }

    public function test_generates_thumbnail_small_web_webp_rows_for_image_asset(): void
    {
        Storage::fake('s3');

        $tenantId = (string) Str::uuid();
        $storagePath = 'products/'.$tenantId.'/original.jpg';

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Processing,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'width' => 20,
            'height' => 20,
        ]);

        // Put a real JPEG at the asset's storage_path on the fake disk
        Storage::disk('s3')->put($storagePath, $this->tinyJpegBytes());

        /** @var RenditionService $service */
        $service = app(RenditionService::class);
        $service->generate($asset);

        $fresh = $asset->fresh(['renditions']);
        self::assertNotNull($fresh);

        $names = $fresh->renditions
            ->pluck('name')
            ->map(fn ($n) => $n->value)
            ->sort()
            ->values()
            ->all();

        self::assertSame(['SMALL', 'THUMBNAIL', 'WEB'], $names);

        self::assertTrue(
            $fresh->renditions->every(fn ($r) => $r->format === RenditionFormat::Webp),
            'Every rendition must have format WEBP',
        );
    }

    public function test_rendition_storage_paths_are_written_to_disk(): void
    {
        Storage::fake('s3');

        $tenantId = (string) Str::uuid();
        $storagePath = 'products/'.$tenantId.'/main.jpg';

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Processing,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
        ]);

        Storage::disk('s3')->put($storagePath, $this->tinyJpegBytes());

        /** @var RenditionService $service */
        $service = app(RenditionService::class);
        $service->generate($asset);

        $fresh = $asset->fresh(['renditions']);
        self::assertNotNull($fresh);

        foreach ($fresh->renditions as $rendition) {
            self::assertTrue(
                Storage::disk('s3')->exists($rendition->storage_path),
                "Expected rendition file on disk: {$rendition->storage_path}",
            );
        }
    }

    public function test_generate_is_idempotent_on_rerun(): void
    {
        // A queue retry after a partial success (or a regenerate command) re-runs
        // generate() for an asset that already has renditions. It must update the
        // existing rows, not throw on the (media_asset_id, name, format) unique
        // index, and must not duplicate rows.
        Storage::fake('s3');

        $tenantId = (string) Str::uuid();
        $storagePath = 'products/'.$tenantId.'/rerun.jpg';

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Processing,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
        ]);

        Storage::disk('s3')->put($storagePath, $this->tinyJpegBytes());

        /** @var RenditionService $service */
        $service = app(RenditionService::class);

        $service->generate($asset);
        // Second run must not throw and must not create duplicates.
        $service->generate($asset);

        self::assertSame(
            count(RenditionService::TARGETS),
            $asset->fresh(['renditions'])?->renditions->count(),
            'Re-running generate() must keep exactly one rendition per target (idempotent).',
        );
    }
}
