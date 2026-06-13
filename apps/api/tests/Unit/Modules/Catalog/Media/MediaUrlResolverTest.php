<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog\Media;

use App\Modules\Catalog\Application\Services\MediaUrlResolver;
use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaUrlResolverTest extends TestCase
{
    public function test_upload_attachment_returns_download_route_with_variant_query_string(): void
    {
        $resolver = new MediaUrlResolver();

        $productId = (string) Str::uuid();
        $attachmentId = (string) Str::uuid();

        $asset = new MediaAsset();
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment();
        $attachment->id = $attachmentId;
        $attachment->owner_id = $productId;
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forAttachment($attachment, 'sm');

        self::assertNotNull($url);
        self::assertStringContainsString('/images/' . $attachmentId . '/download', $url);
        self::assertStringContainsString('variant=sm', $url);
    }

    public function test_external_url_attachment_returns_asset_external_url(): void
    {
        $resolver = new MediaUrlResolver();

        $asset = new MediaAsset();
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::ExternalUrl;
        $asset->status = MediaStatus::Ready;
        $asset->external_url = 'https://x/y.jpg';

        $attachment = new MediaAttachment();
        $attachment->id = (string) Str::uuid();
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forAttachment($attachment, 'sm');

        self::assertSame('https://x/y.jpg', $url);
    }

    public function test_null_variant_is_omitted_from_route_url(): void
    {
        $resolver = new MediaUrlResolver();

        $productId = (string) Str::uuid();
        $attachmentId = (string) Str::uuid();

        $asset = new MediaAsset();
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment();
        $attachment->id = $attachmentId;
        $attachment->owner_id = $productId;
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forAttachment($attachment, null);

        self::assertNotNull($url);
        self::assertStringContainsString('/images/' . $attachmentId . '/download', $url);
        self::assertStringNotContainsString('variant=', $url);
    }
}
