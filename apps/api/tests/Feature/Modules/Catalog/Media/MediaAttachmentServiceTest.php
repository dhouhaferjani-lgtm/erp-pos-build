<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Application\Services\MediaAttachmentService;
use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Enums\RenditionFormat;
use App\Modules\Catalog\Domain\Enums\RenditionName;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Catalog\Domain\Media\MediaRendition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaAttachmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private MediaAttachmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(MediaAttachmentService::class);
    }

    private function makeAsset(string $tenantId): MediaAsset
    {
        return MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'url',
            'external_url' => 'https://example.com/'.Str::uuid().'.jpg',
        ]);
    }

    /**
     * Create a MediaAsset backed by a fake S3 disk, with a real file stored at the path.
     *
     * @return array{asset: MediaAsset, asset_path: string}
     */
    private function makeS3Asset(string $tenantId): array
    {
        $assetPath = 'products/'.$tenantId.'/'.Str::uuid().'/original.jpg';

        Storage::disk('s3')->put($assetPath, 'fake-image-content');

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => $assetPath,
            'mime_type' => 'image/jpeg',
            'file_size' => 18,
            'checksum' => hash('sha256', 'fake-image-content'),
        ]);

        return ['asset' => $asset, 'asset_path' => $assetPath];
    }

    public function test_attaching_new_primary_demotes_prior_primary_to_gallery(): void
    {
        $tenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();

        $assetA = $this->makeAsset($tenantId);
        $assetB = $this->makeAsset($tenantId);

        // Attach A as PRIMARY.
        $attachmentA = $this->service->attach(
            $assetA->id,
            MediaOwnerType::Product,
            $ownerId,
            MediaRole::Primary,
            0,
            $tenantId,
        );

        self::assertSame(MediaRole::Primary, $attachmentA->fresh()->role);

        // Attach B as PRIMARY — A must be demoted to GALLERY.
        $attachmentB = $this->service->attach(
            $assetB->id,
            MediaOwnerType::Product,
            $ownerId,
            MediaRole::Primary,
            1,
            $tenantId,
        );

        self::assertSame(MediaRole::Gallery, $attachmentA->fresh()->role, 'Asset A must be demoted to GALLERY');
        self::assertSame(MediaRole::Primary, $attachmentB->fresh()->role, 'Asset B must be PRIMARY');

        // Only one PRIMARY must exist for this owner.
        $primaryCount = MediaAttachment::where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $ownerId)
            ->where('tenant_id', $tenantId)
            ->where('role', MediaRole::Primary)
            ->count();

        self::assertSame(1, $primaryCount, 'Exactly one PRIMARY must exist for the owner');
    }

    public function test_attaching_gallery_does_not_affect_existing_primary(): void
    {
        $tenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();

        $assetA = $this->makeAsset($tenantId);
        $assetB = $this->makeAsset($tenantId);

        $attachmentA = $this->service->attach(
            $assetA->id,
            MediaOwnerType::Product,
            $ownerId,
            MediaRole::Primary,
            0,
            $tenantId,
        );

        $this->service->attach(
            $assetB->id,
            MediaOwnerType::Product,
            $ownerId,
            MediaRole::Gallery,
            1,
            $tenantId,
        );

        // A must remain PRIMARY.
        self::assertSame(MediaRole::Primary, $attachmentA->fresh()->role, 'Existing primary must remain PRIMARY');
    }

    public function test_reorder_updates_sort_order(): void
    {
        $tenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();

        $assetA = $this->makeAsset($tenantId);
        $assetB = $this->makeAsset($tenantId);

        $attachA = $this->service->attach($assetA->id, MediaOwnerType::Product, $ownerId, MediaRole::Primary, 0, $tenantId);
        $attachB = $this->service->attach($assetB->id, MediaOwnerType::Product, $ownerId, MediaRole::Gallery, 1, $tenantId);

        // Reorder: B first, A second.
        $this->service->reorder(MediaOwnerType::Product, $ownerId, [$attachB->id, $attachA->id], $tenantId);

        self::assertSame(0, $attachB->fresh()->sort_order);
        self::assertSame(1, $attachA->fresh()->sort_order);
    }

    public function test_detach_link_hard_deletes_gallery_attachment(): void
    {
        $tenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();

        $assetA = $this->makeAsset($tenantId);
        $assetB = $this->makeAsset($tenantId);

        $attachA = $this->service->attach($assetA->id, MediaOwnerType::Product, $ownerId, MediaRole::Primary, 0, $tenantId);
        $attachB = $this->service->attach($assetB->id, MediaOwnerType::Product, $ownerId, MediaRole::Gallery, 1, $tenantId);

        $this->service->detachLink($attachB->id, $tenantId);

        self::assertNull(MediaAttachment::find($attachB->id), 'Detached attachment must be hard-deleted');
        // Primary A must remain untouched.
        self::assertSame(MediaRole::Primary, $attachA->fresh()->role);
    }

    public function test_detach_primary_link_promotes_next_by_sort_order(): void
    {
        $tenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();

        $assetA = $this->makeAsset($tenantId);
        $assetB = $this->makeAsset($tenantId);
        $assetC = $this->makeAsset($tenantId);

        $attachA = $this->service->attach($assetA->id, MediaOwnerType::Product, $ownerId, MediaRole::Primary, 0, $tenantId);
        $attachB = $this->service->attach($assetB->id, MediaOwnerType::Product, $ownerId, MediaRole::Gallery, 1, $tenantId);
        $attachC = $this->service->attach($assetC->id, MediaOwnerType::Product, $ownerId, MediaRole::Gallery, 2, $tenantId);

        // Detach the PRIMARY (A) — B (sort_order=1) should become PRIMARY.
        $this->service->detachLink($attachA->id, $tenantId);

        self::assertNull(MediaAttachment::find($attachA->id), 'Primary attachment must be hard-deleted');
        self::assertSame(MediaRole::Primary, $attachB->fresh()->role, 'Next attachment (lowest sort_order) must be promoted to PRIMARY');
        self::assertSame(MediaRole::Gallery, $attachC->fresh()->role, 'Third attachment must remain GALLERY');
    }

    public function test_detach_primary_link_with_no_remaining_does_not_throw(): void
    {
        $tenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();

        $assetA = $this->makeAsset($tenantId);

        $attachA = $this->service->attach($assetA->id, MediaOwnerType::Product, $ownerId, MediaRole::Primary, 0, $tenantId);

        // Only one attachment — detaching should not throw.
        $this->service->detachLink($attachA->id, $tenantId);

        self::assertNull(MediaAttachment::find($attachA->id));
    }

    public function test_delete_asset_soft_deletes_when_no_links_remain(): void
    {
        $tenantId = (string) Str::uuid();

        $asset = $this->makeAsset($tenantId);

        // No attachments — deleteAsset should succeed.
        $this->service->deleteAsset($asset->id, $tenantId);

        // Soft-deleted: must not appear in normal queries.
        self::assertNull(MediaAsset::find($asset->id), 'Soft-deleted asset must not appear in normal queries');
        // But must still exist with trashed scope.
        self::assertNotNull(MediaAsset::withTrashed()->find($asset->id), 'Soft-deleted asset must exist in trashed scope');
    }

    public function test_delete_asset_throws_when_links_remain(): void
    {
        $tenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();

        $asset = $this->makeAsset($tenantId);

        $this->service->attach($asset->id, MediaOwnerType::Product, $ownerId, MediaRole::Gallery, 0, $tenantId);

        $this->expectException(\RuntimeException::class);

        $this->service->deleteAsset($asset->id, $tenantId);
    }

    public function test_attach_is_scoped_to_tenant(): void
    {
        $tenantId = (string) Str::uuid();
        $otherTenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();

        $assetA = $this->makeAsset($tenantId);
        $assetB = $this->makeAsset($otherTenantId);

        $attachA = $this->service->attach($assetA->id, MediaOwnerType::Product, $ownerId, MediaRole::Primary, 0, $tenantId);
        $attachB = $this->service->attach($assetB->id, MediaOwnerType::Product, $ownerId, MediaRole::Primary, 0, $otherTenantId);

        // Tenant A's attachment must remain PRIMARY (different tenant — no collision).
        self::assertSame(MediaRole::Primary, $attachA->fresh()->role, 'Tenant A PRIMARY must not be demoted by tenant B operation');
        self::assertSame(MediaRole::Primary, $attachB->fresh()->role, 'Tenant B PRIMARY must be set correctly');
    }

    public function test_delete_asset_removes_s3_original_and_rendition_files(): void
    {
        Storage::fake('s3');

        $tenantId = (string) Str::uuid();

        ['asset' => $asset, 'asset_path' => $assetPath] = $this->makeS3Asset($tenantId);

        // Create a rendition row with its own S3 file.
        $renditionPath = 'products/'.$tenantId.'/'.$asset->id.'/thumb.webp';
        Storage::disk('s3')->put($renditionPath, 'fake-rendition-content');

        MediaRendition::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'name' => RenditionName::Thumbnail,
            'format' => RenditionFormat::Webp,
            'storage_disk' => 's3',
            'storage_path' => $renditionPath,
            'width' => 150,
            'height' => 150,
            'file_size' => 22,
        ]);

        // Confirm files exist before deletion.
        Storage::disk('s3')->assertExists($assetPath);
        Storage::disk('s3')->assertExists($renditionPath);

        // No attachment links exist — deleteAsset must succeed.
        $this->service->deleteAsset($asset->id, $tenantId);

        // Both files must have been removed from S3 after the commit.
        Storage::disk('s3')->assertMissing($assetPath);
        Storage::disk('s3')->assertMissing($renditionPath);
    }
}
