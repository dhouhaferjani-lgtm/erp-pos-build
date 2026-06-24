<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Domain\Contracts\MediaStorageInterface;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Enums\RenditionFormat;
use App\Modules\Media\Domain\Enums\RenditionName;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Catalog\Domain\Media\MediaRendition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class MediaServeTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Upload asset — streamed response (200)
    // -----------------------------------------------------------------------

    public function test_serve_upload_asset_returns_streamed_response_for_known_variant(): void
    {
        Storage::fake('s3');

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        // Create the asset and put the original file on the fake disk
        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/original.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('s3')->put('products/'.$tenantId.'/original.jpg', 'ORIGINAL_BYTES');

        // Create a THUMBNAIL rendition row and file on the fake disk
        $thumbnailPath = 'products/'.$tenantId.'/thumbnail.webp';
        Storage::disk('s3')->put($thumbnailPath, 'THUMBNAIL_BYTES');

        MediaRendition::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'name' => RenditionName::Thumbnail,
            'format' => RenditionFormat::Webp,
            'storage_disk' => 's3',
            'storage_path' => $thumbnailPath,
            'width' => 150,
            'height' => 113,
            'file_size' => 16,
        ]);

        $attachment = MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        // Load the relation so the adapter can inspect it
        $attachment->load('mediaAsset.renditions');

        /** @var MediaStorageInterface $adapter */
        $adapter = app(MediaStorageInterface::class);

        // 'sm' maps to THUMBNAIL — should return the rendition file (streamed)
        $response = $adapter->serve($attachment, 'sm');

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(StreamedResponse::class, $response);
    }

    public function test_serve_upload_asset_falls_back_to_original_for_unknown_variant(): void
    {
        Storage::fake('s3');

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/original.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('s3')->put('products/'.$tenantId.'/original.jpg', 'ORIGINAL_BYTES');

        $attachment = MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $attachment->load('mediaAsset.renditions');

        /** @var MediaStorageInterface $adapter */
        $adapter = app(MediaStorageInterface::class);

        // Unknown variant → fall back to the original file
        $response = $adapter->serve($attachment, 'unknown_variant');

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(StreamedResponse::class, $response);
    }

    public function test_serve_upload_asset_falls_back_to_original_when_variant_rendition_absent(): void
    {
        Storage::fake('s3');

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/original.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('s3')->put('products/'.$tenantId.'/original.jpg', 'ORIGINAL_BYTES');

        // No MediaRendition rows — no thumbnail exists
        $attachment = MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $attachment->load('mediaAsset.renditions');

        /** @var MediaStorageInterface $adapter */
        $adapter = app(MediaStorageInterface::class);

        // 'sm' maps to THUMBNAIL but no rendition exists → fall back to original
        $response = $adapter->serve($attachment, 'sm');

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(StreamedResponse::class, $response);
    }

    // -----------------------------------------------------------------------
    // External-URL asset — redirect (302)
    // -----------------------------------------------------------------------

    public function test_serve_external_url_asset_returns_redirect(): void
    {
        Storage::fake('s3');

        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'url',
            'external_url' => 'https://example.com/product.jpg',
        ]);

        $attachment = MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $attachment->load('mediaAsset.renditions');

        /** @var MediaStorageInterface $adapter */
        $adapter = app(MediaStorageInterface::class);

        $response = $adapter->serve($attachment, 'sm');

        self::assertSame(302, $response->getStatusCode());
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://example.com/product.jpg', $response->getTargetUrl());
    }

    // -----------------------------------------------------------------------
    // put / get / delete helpers
    // -----------------------------------------------------------------------

    public function test_put_get_delete_helpers_round_trip_on_fake_disk(): void
    {
        Storage::fake('s3');

        /** @var MediaStorageInterface $adapter */
        $adapter = app(MediaStorageInterface::class);

        $adapter->put('s3', 'test/file.txt', 'HELLO');

        self::assertSame('HELLO', $adapter->get('s3', 'test/file.txt'));

        $adapter->delete('s3', 'test/file.txt');

        self::assertNull($adapter->get('s3', 'test/file.txt'));
    }
}
