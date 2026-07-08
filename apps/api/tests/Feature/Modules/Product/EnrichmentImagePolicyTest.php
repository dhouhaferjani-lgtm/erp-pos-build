<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Application\Services\EnrichmentImagePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnrichmentImagePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_when_no_existing_primary_and_run_has_none(): void
    {
        $policy = app(EnrichmentImagePolicy::class);
        $productId = (string) Str::uuid();
        $tenantId = (string) Str::uuid();

        $this->assertSame(MediaRole::Primary, $policy->roleFor($productId, $tenantId, false));
    }

    public function test_gallery_when_run_already_assigned_primary(): void
    {
        $policy = app(EnrichmentImagePolicy::class);
        $productId = (string) Str::uuid();
        $tenantId = (string) Str::uuid();

        $this->assertSame(MediaRole::Gallery, $policy->roleFor($productId, $tenantId, true));
    }

    /**
     * Regression for the Task 7 review finding: MediaUploadService::upload()
     * dispatches GenerateRenditions via DB::afterCommit, so a just-persisted
     * PRIMARY sits in UPLOADED (not READY) for a while. A second persist()
     * call during that window must still see it as blocking — otherwise the
     * new image is assigned PRIMARY and MediaAttachmentService::attach()
     * silently demotes the in-flight PRIMARY to GALLERY.
     */
    public function test_gallery_when_existing_primary_asset_is_still_uploaded_not_ready(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $inFlightAsset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 's3',
            'storage_path' => "products/{$tenantId}/{$productId}/in-flight/original.png",
            'mime_type' => 'image/png',
            'checksum' => str_repeat('b', 64),
        ]);

        MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $inFlightAsset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $policy = app(EnrichmentImagePolicy::class);

        $this->assertSame(MediaRole::Gallery, $policy->roleFor($productId, $tenantId, false));
    }
}
