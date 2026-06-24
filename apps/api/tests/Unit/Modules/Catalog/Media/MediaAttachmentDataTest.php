<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog\Media;

use App\Modules\Catalog\Application\DTOs\MediaAssetData;
use App\Modules\Catalog\Application\DTOs\MediaAttachmentData;
use App\Modules\Catalog\Application\DTOs\MediaRenditionData;
use App\Modules\Catalog\Application\DTOs\ProductMediaData;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Enums\RenditionFormat;
use App\Modules\Media\Domain\Enums\RenditionName;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Catalog\Domain\Media\MediaRendition;
use Tests\TestCase;

/**
 * These cases instantiate real Eloquent media models (MediaAsset / MediaAttachment
 * / MediaRendition) to exercise the DTO `fromModel` mappers. Eloquent models lean
 * on global static state (the event dispatcher — wired to Spatie event-sourcing —
 * and the connection resolver), so a bare PHPUnit\Framework\TestCase passes in
 * isolation but fails in the full Unit run once an earlier app-booting test leaks
 * that state (BindingResolutionException on the event subscriber; an int leaking
 * into the string `$id`). Extending the Laravel TestCase re-boots the app per test
 * (no DB needed — no model is persisted), making these order-independent — matching
 * the sibling MediaUrlResolverTest.
 */
final class MediaAttachmentDataTest extends TestCase
{
    public function test_attachment_data_exposes_url_and_role_from_model(): void
    {
        $asset = new MediaAsset;
        $asset->id = 'asset-uuid-001';
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment;
        $attachment->id = 'attach-uuid-001';
        $attachment->media_asset_id = 'asset-uuid-001';
        $attachment->role = MediaRole::Primary;
        $attachment->sort_order = 0;
        $attachment->alt = 'Product image';
        $attachment->caption = 'Main view';
        $attachment->setRelation('mediaAsset', $asset);

        $data = MediaAttachmentData::fromModel($attachment, 'https://x/y.jpg');

        self::assertSame('attach-uuid-001', $data->id);
        self::assertSame('asset-uuid-001', $data->asset_id);
        self::assertSame('IMAGE', $data->type);
        self::assertSame('PRIMARY', $data->role);
        self::assertSame(0, $data->sort_order);
        self::assertSame('https://x/y.jpg', $data->url);
        self::assertSame('Product image', $data->alt);
        self::assertSame('Main view', $data->caption);
    }

    public function test_attachment_data_url_can_be_null(): void
    {
        $asset = new MediaAsset;
        $asset->id = 'asset-uuid-002';
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment;
        $attachment->id = 'attach-uuid-002';
        $attachment->media_asset_id = 'asset-uuid-002';
        $attachment->role = MediaRole::Gallery;
        $attachment->sort_order = 1;
        $attachment->alt = null;
        $attachment->caption = null;
        $attachment->setRelation('mediaAsset', $asset);

        $data = MediaAttachmentData::fromModel($attachment, null);

        self::assertNull($data->url);
        self::assertNull($data->alt);
        self::assertNull($data->caption);
        self::assertSame('GALLERY', $data->role);
    }

    public function test_product_media_data_empty_returns_nulls_and_empty_array(): void
    {
        $empty = ProductMediaData::makeEmpty();

        self::assertNull($empty->primary_image_url);
        self::assertSame([], $empty->media);
    }

    public function test_product_media_data_holds_attachments(): void
    {
        $asset = new MediaAsset;
        $asset->id = 'asset-uuid-003';
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment;
        $attachment->id = 'attach-uuid-003';
        $attachment->media_asset_id = 'asset-uuid-003';
        $attachment->role = MediaRole::Primary;
        $attachment->sort_order = 0;
        $attachment->alt = null;
        $attachment->caption = null;
        $attachment->setRelation('mediaAsset', $asset);

        $dto = MediaAttachmentData::fromModel($attachment, 'https://cdn/img.jpg');
        $productMedia = new ProductMediaData('https://cdn/img.jpg', [$dto]);

        self::assertSame('https://cdn/img.jpg', $productMedia->primary_image_url);
        self::assertCount(1, $productMedia->media);
        self::assertSame('attach-uuid-003', $productMedia->media[0]->id);
    }

    public function test_media_asset_data_from_model(): void
    {
        $asset = new MediaAsset;
        $asset->id = 'asset-uuid-004';
        $asset->tenant_id = 'tenant-001';
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::ExternalUrl;
        $asset->status = MediaStatus::Ready;
        $asset->storage_disk = 'url';
        $asset->storage_path = null;
        $asset->external_url = 'https://example.com/img.png';
        $asset->original_filename = 'img.png';
        $asset->mime_type = 'image/png';
        $asset->file_size = null;
        $asset->width = null;
        $asset->height = null;
        $asset->title = 'Example image';

        $data = MediaAssetData::fromModel($asset);

        self::assertSame('asset-uuid-004', $data->id);
        self::assertSame('IMAGE', $data->type);
        self::assertSame('EXTERNAL_URL', $data->source);
        self::assertSame('READY', $data->status);
        self::assertSame('https://example.com/img.png', $data->external_url);
    }

    public function test_media_rendition_data_from_model(): void
    {
        $rendition = new MediaRendition;
        $rendition->id = 'rend-uuid-001';
        $rendition->tenant_id = 'tenant-001';
        $rendition->media_asset_id = 'asset-uuid-001';
        $rendition->name = RenditionName::Thumbnail;
        $rendition->format = RenditionFormat::Webp;
        $rendition->storage_disk = 's3';
        $rendition->storage_path = 'media/thumb.webp';
        $rendition->width = 150;
        $rendition->height = 150;
        $rendition->file_size = 4096;

        $data = MediaRenditionData::fromModel($rendition);

        self::assertSame('rend-uuid-001', $data->id);
        self::assertSame('THUMBNAIL', $data->name);
        self::assertSame('WEBP', $data->format);
        self::assertSame(150, $data->width);
        self::assertSame(150, $data->height);
        self::assertSame(4096, $data->file_size);
    }
}
