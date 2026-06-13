<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Domain\Contracts\MediaAssetRepositoryInterface;
use App\Modules\Catalog\Domain\Contracts\MediaAttachmentRepositoryInterface;
use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EloquentMediaRepositoryTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // MediaAttachmentRepository
    // -----------------------------------------------------------------------

    public function test_attachment_repo_returns_product_attachments_ordered_with_ready_assets(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/original.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $attachment = MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        /** @var MediaAttachmentRepositoryInterface $repo */
        $repo = app(MediaAttachmentRepositoryInterface::class);
        $rows = $repo->forOwners(MediaOwnerType::Product, [$productId], $tenantId);

        self::assertArrayHasKey($productId, $rows);
        self::assertCount(1, $rows[$productId]);
        self::assertSame($attachment->id, $rows[$productId][0]->id);
    }

    public function test_attachment_repo_excludes_non_ready_assets(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $processingAsset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Processing,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/original.jpg',
        ]);

        MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $processingAsset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        /** @var MediaAttachmentRepositoryInterface $repo */
        $repo = app(MediaAttachmentRepositoryInterface::class);
        $rows = $repo->forOwners(MediaOwnerType::Product, [$productId], $tenantId);

        self::assertArrayNotHasKey($productId, $rows);
    }

    public function test_attachment_repo_tenant_isolation(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        $productId = (string) Str::uuid();

        // Asset and attachment belong to tenant B
        $assetB = MediaAsset::create([
            'tenant_id' => $tenantB,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantB.'/p/original.jpg',
        ]);

        MediaAttachment::create([
            'tenant_id' => $tenantB,
            'media_asset_id' => $assetB->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        /** @var MediaAttachmentRepositoryInterface $repo */
        $repo = app(MediaAttachmentRepositoryInterface::class);

        // Querying with tenant A should return nothing
        $rows = $repo->forOwners(MediaOwnerType::Product, [$productId], $tenantA);

        self::assertEmpty($rows);
    }

    public function test_attachment_repo_returns_empty_for_empty_owner_ids(): void
    {
        /** @var MediaAttachmentRepositoryInterface $repo */
        $repo = app(MediaAttachmentRepositoryInterface::class);

        $rows = $repo->forOwners(MediaOwnerType::Product, [], (string) Str::uuid());

        self::assertSame([], $rows);
    }

    public function test_attachment_repo_orders_by_sort_order(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $asset1 = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/original1.jpg',
        ]);

        $asset2 = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/original2.jpg',
        ]);

        // Create in reverse order to verify sorting
        $attachmentB = MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset2->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Gallery,
            'sort_order' => 2,
        ]);

        $attachmentA = MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset1->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        /** @var MediaAttachmentRepositoryInterface $repo */
        $repo = app(MediaAttachmentRepositoryInterface::class);
        $rows = $repo->forOwners(MediaOwnerType::Product, [$productId], $tenantId);

        self::assertArrayHasKey($productId, $rows);
        self::assertCount(2, $rows[$productId]);
        self::assertSame($attachmentA->id, $rows[$productId][0]->id);
        self::assertSame($attachmentB->id, $rows[$productId][1]->id);
    }

    public function test_attachment_repo_eager_loads_asset(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/original.jpg',
        ]);

        MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        /** @var MediaAttachmentRepositoryInterface $repo */
        $repo = app(MediaAttachmentRepositoryInterface::class);
        $rows = $repo->forOwners(MediaOwnerType::Product, [$productId], $tenantId);

        $loadedAttachment = $rows[$productId][0];
        self::assertTrue($loadedAttachment->relationLoaded('mediaAsset'));
        self::assertSame($asset->id, $loadedAttachment->mediaAsset->id);
        // Renditions are NOT eager-loaded here; they are loaded on-demand by the
        // serve adapter (Task 7) when a specific uploaded asset needs to be streamed.
        // The query path (CatalogMediaQuery) only needs asset metadata (source, external_url).
    }

    // -----------------------------------------------------------------------
    // MediaAssetRepository
    // -----------------------------------------------------------------------

    public function test_asset_repo_save_and_find_by_id_and_tenant(): void
    {
        $tenantId = (string) Str::uuid();

        $asset = new MediaAsset([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/original.jpg',
        ]);

        /** @var MediaAssetRepositoryInterface $repo */
        $repo = app(MediaAssetRepositoryInterface::class);
        $repo->save($asset);

        self::assertNotEmpty($asset->id);

        $found = $repo->find($asset->id, $tenantId);
        self::assertNotNull($found);
        self::assertSame($asset->id, $found->id);
    }

    public function test_asset_repo_find_scopes_by_tenant(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantA,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantA.'/p/original.jpg',
        ]);

        /** @var MediaAssetRepositoryInterface $repo */
        $repo = app(MediaAssetRepositoryInterface::class);

        // Same id but wrong tenant — must not find
        $notFound = $repo->find($asset->id, $tenantB);
        self::assertNull($notFound);

        // Correct tenant — must find
        $found = $repo->find($asset->id, $tenantA);
        self::assertNotNull($found);
    }

    public function test_asset_repo_mark_processing_changes_status(): void
    {
        $tenantId = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/original.jpg',
        ]);

        /** @var MediaAssetRepositoryInterface $repo */
        $repo = app(MediaAssetRepositoryInterface::class);
        $repo->markProcessing($asset);

        $fresh = MediaAsset::find($asset->id);
        self::assertNotNull($fresh);
        self::assertSame(MediaStatus::Processing, $fresh->status);
    }

    public function test_asset_repo_mark_ready_changes_status(): void
    {
        $tenantId = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Processing,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/original.jpg',
        ]);

        /** @var MediaAssetRepositoryInterface $repo */
        $repo = app(MediaAssetRepositoryInterface::class);
        $repo->markReady($asset);

        $fresh = MediaAsset::find($asset->id);
        self::assertNotNull($fresh);
        self::assertSame(MediaStatus::Ready, $fresh->status);
    }

    public function test_asset_repo_mark_failed_changes_status(): void
    {
        $tenantId = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Processing,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/original.jpg',
        ]);

        /** @var MediaAssetRepositoryInterface $repo */
        $repo = app(MediaAssetRepositoryInterface::class);
        $repo->markFailed($asset);

        $fresh = MediaAsset::find($asset->id);
        self::assertNotNull($fresh);
        self::assertSame(MediaStatus::Failed, $fresh->status);
    }
}
