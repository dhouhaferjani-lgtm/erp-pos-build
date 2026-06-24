<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Enums\RenditionFormat;
use App\Modules\Media\Domain\Enums\RenditionName;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaRendition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaAssetModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_asset_with_enum_casts_and_renditions(): void
    {
        $tenantId = (string) Str::uuid();
        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Processing,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/a/original.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1234,
            'checksum' => str_repeat('a', 64),
            'width' => 800,
            'height' => 600,
        ]);

        $rendition = MediaRendition::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'name' => RenditionName::Thumbnail,
            'format' => RenditionFormat::Webp,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/p/a/thumbnail.webp',
            'width' => 150,
            'height' => 113,
            'file_size' => 321,
        ]);

        $fresh = MediaAsset::with('renditions')->find($asset->id);
        self::assertInstanceOf(MediaAssetType::class, $fresh->type);
        self::assertSame(MediaStatus::Processing, $fresh->status);
        self::assertCount(1, $fresh->renditions);
        self::assertSame(RenditionName::Thumbnail, $fresh->renditions->first()->name);
        self::assertSame($tenantId, $rendition->tenant_id);
    }
}
