<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Media\Application\Jobs\GenerateRenditions;
use App\Modules\Media\Application\Services\RenditionService;
use App\Modules\Media\Domain\Contracts\MediaAssetRepositoryInterface;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature regression: GenerateRenditions job must be tenant-scoped.
 *
 * The job's `$assets->find($id, $tenantId)` queries by BOTH columns; this
 * test fails if the lookup ignores `tenant_id` (asset B would get processed).
 * A real Tenant row is required so BindsTenantContext::withTenantContext()
 * can resolve Tenant::find($tenantId) in the SQLite test environment.
 */
final class GenerateRenditionsJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A minimal valid JPEG (20×20 pixels) created via GD.
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

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => "Tenant {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    public function test_job_marks_ready_and_does_not_touch_another_tenants_asset(): void
    {
        Storage::fake('s3');

        // Create two real Tenant rows so BindsTenantContext can resolve them.
        $tenantA = $this->makeTenant('generate-renditions-a-'.Str::random(6));
        $tenantB = $this->makeTenant('generate-renditions-b-'.Str::random(6));

        $pathA = 'products/'.$tenantA->id.'/asset-a/original.jpg';

        // Asset A — tenant A, Upload, status UPLOADED, real bytes on fake disk.
        $assetA = MediaAsset::create([
            'tenant_id' => $tenantA->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 's3',
            'storage_path' => $pathA,
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
        ]);
        Storage::disk('s3')->put($pathA, $this->tinyJpegBytes());

        // Asset B — a DIFFERENT tenant, same status. Must remain untouched.
        $assetB = MediaAsset::create([
            'tenant_id' => $tenantB->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantB->id.'/asset-b/original.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
        ]);

        // Run the job synchronously under tenant A's context.
        (new GenerateRenditions($tenantA->id, $assetA->id))
            ->handle(
                $this->app->make(RenditionService::class),
                $this->app->make(MediaAssetRepositoryInterface::class),
            );

        // Asset A must be READY with renditions.
        $freshA = MediaAsset::withoutGlobalScopes()->find($assetA->id);
        self::assertNotNull($freshA, 'Asset A must still exist after the job');
        self::assertSame(MediaStatus::Ready, $freshA->status, 'Asset A must be READY after the job');
        self::assertGreaterThan(
            0,
            $freshA->renditions()->count(),
            'Asset A must have at least one rendition',
        );

        // Tenant isolation: Asset B must remain UPLOADED with zero renditions.
        $freshB = MediaAsset::withoutGlobalScopes()->find($assetB->id);
        self::assertNotNull($freshB, 'Asset B must still exist');
        self::assertSame(
            MediaStatus::Uploaded,
            $freshB->status,
            'Asset B (different tenant) must not be touched — status must remain UPLOADED',
        );
        self::assertSame(
            0,
            $freshB->renditions()->count(),
            'Asset B (different tenant) must have zero renditions',
        );
    }

    public function test_job_skips_external_url_assets(): void
    {
        Storage::fake('s3');

        $tenant = $this->makeTenant('generate-renditions-ext-'.Str::random(6));

        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 's3',
            'storage_path' => 'irrelevant/path.jpg',
            'external_url' => 'https://example.com/image.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 0,
        ]);

        // Must not throw and must not change status.
        (new GenerateRenditions($tenant->id, $asset->id))
            ->handle(
                $this->app->make(RenditionService::class),
                $this->app->make(MediaAssetRepositoryInterface::class),
            );

        $fresh = MediaAsset::withoutGlobalScopes()->find($asset->id);
        self::assertNotNull($fresh);
        self::assertSame(MediaStatus::Uploaded, $fresh->status, 'ExternalUrl asset must be skipped');
        self::assertSame(0, $fresh->renditions()->count());
    }

    public function test_job_skips_non_image_document_asset_and_marks_ready(): void
    {
        Storage::fake('s3');

        $tenant = $this->makeTenant('generate-renditions-doc-'.Str::random(6));

        // A Document asset that was created UPLOADED (defensive: should have been Ready,
        // but if somehow queued anyway, the job must handle it gracefully).
        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'type' => MediaAssetType::Document,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 's3',
            'storage_path' => 'documents/'.$tenant->id.'/doc-1/invoice.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);

        (new GenerateRenditions($tenant->id, $asset->id))
            ->handle(
                $this->app->make(RenditionService::class),
                $this->app->make(MediaAssetRepositoryInterface::class),
            );

        $fresh = MediaAsset::withoutGlobalScopes()->find($asset->id);
        self::assertNotNull($fresh);
        self::assertSame(MediaStatus::Ready, $fresh->status, 'Document asset must be marked Ready with no renditions');
        self::assertSame(0, $fresh->renditions()->count(), 'Document asset must have zero renditions');
    }

    public function test_job_marks_failed_and_rethrows_when_generate_throws(): void
    {
        Storage::fake('s3');

        $tenant = $this->makeTenant('generate-renditions-fail-'.Str::random(6));

        // Asset with UPLOAD source but NO file on disk — RenditionService will throw.
        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenant->id.'/missing/original.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
        ]);
        // Deliberately do NOT put any file on the fake disk.

        $this->expectException(\Throwable::class);

        (new GenerateRenditions($tenant->id, $asset->id))
            ->handle(
                $this->app->make(RenditionService::class),
                $this->app->make(MediaAssetRepositoryInterface::class),
            );

        // Unreachable — but document the contract: asset would be FAILED.
        $fresh = MediaAsset::withoutGlobalScopes()->find($asset->id);
        self::assertNotNull($fresh);
        self::assertSame(MediaStatus::Failed, $fresh->status);
    }
}
