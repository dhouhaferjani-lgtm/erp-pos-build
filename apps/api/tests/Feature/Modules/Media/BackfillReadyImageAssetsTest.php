<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUG-005 / RCA A2 backfill migration (authz-gate ruling 2026-08-06).
 *
 * Exercises the migration's UPDATE through the same predicate the migration
 * uses, so a drift in the enum backing values (the column stores UPPERCASE
 * 'IMAGE'/'UPLOADED', not lowercase) fails here rather than silently matching
 * zero rows in production.
 */
final class BackfillReadyImageAssetsTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->tenantId = (string) Str::uuid();
    }

    public function test_migration_promotes_uploaded_and_processing_images_but_never_failed(): void
    {
        $uploaded = $this->makeAsset(MediaAssetType::Image, MediaStatus::Uploaded);
        $processing = $this->makeAsset(MediaAssetType::Image, MediaStatus::Processing);
        $failed = $this->makeAsset(MediaAssetType::Image, MediaStatus::Failed);
        $alreadyReady = $this->makeAsset(MediaAssetType::Image, MediaStatus::Ready);
        // A non-image asset is out of scope: Documents are already created READY,
        // and a Video/Spin360 has its own pipeline.
        $document = $this->makeAsset(MediaAssetType::Document, MediaStatus::Uploaded);

        $this->runBackfill();

        self::assertSame(MediaStatus::Ready, $uploaded->refresh()->status);
        self::assertSame(MediaStatus::Ready, $processing->refresh()->status);
        self::assertSame(MediaStatus::Ready, $alreadyReady->refresh()->status);

        self::assertSame(
            MediaStatus::Failed,
            $failed->refresh()->status,
            'FAILED must be excluded — markFailed() also fired when the ORIGINAL object was missing'
        );
        self::assertSame(
            MediaStatus::Uploaded,
            $document->refresh()->status,
            'Non-image assets are out of the backfill predicate'
        );
    }

    public function test_migration_is_idempotent(): void
    {
        $asset = $this->makeAsset(MediaAssetType::Image, MediaStatus::Uploaded);

        $this->runBackfill();
        $first = $asset->refresh()->status;

        $this->runBackfill();

        self::assertSame($first, $asset->refresh()->status);
        self::assertSame(MediaStatus::Ready, $asset->refresh()->status);
    }

    /**
     * Run the migration's up() against the current connection.
     */
    private function runBackfill(): void
    {
        $migration = require __DIR__.'/../../../../database/migrations/tenant/2026_08_06_100000_backfill_ready_image_media_assets.php';
        $migration->up();
    }

    private function makeAsset(MediaAssetType $type, MediaStatus $status): MediaAsset
    {
        return MediaAsset::create([
            'tenant_id' => $this->tenantId,
            'type' => $type,
            'source' => MediaSource::Upload,
            'status' => $status,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$this->tenantId.'/'.Str::uuid().'/original.jpg',
            'mime_type' => $type === MediaAssetType::Document ? 'application/pdf' : 'image/jpeg',
        ]);
    }
}
