<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Application\DTOs\ProductMediaData;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Shared\Contracts\CatalogMediaQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogMediaQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_products_returns_primary_and_gallery_without_n_plus_one(): void
    {
        $tenantId = (string) Str::uuid();
        $ids = [];

        for ($i = 0; $i < 3; $i++) {
            $pid = (string) Str::uuid();
            $ids[] = $pid;

            $asset = MediaAsset::create([
                'tenant_id' => $tenantId,
                'type' => MediaAssetType::Image,
                'source' => MediaSource::ExternalUrl,
                'status' => MediaStatus::Ready,
                'storage_disk' => 'url',
                'external_url' => 'https://example.com/image-'.$i.'.jpg',
            ]);

            MediaAttachment::create([
                'tenant_id' => $tenantId,
                'media_asset_id' => $asset->id,
                'owner_type' => MediaOwnerType::Product,
                'owner_id' => $pid,
                'role' => MediaRole::Primary,
                'sort_order' => 0,
            ]);
        }

        /** @var CatalogMediaQueryInterface $query */
        $query = app(CatalogMediaQueryInterface::class);

        DB::enableQueryLog();
        $result = $query->forProducts($ids, $tenantId);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertCount(3, $result);
        self::assertContainsOnlyInstancesOf(ProductMediaData::class, $result);
        self::assertNotNull($result[$ids[0]]->primary_image_url);
        self::assertNotNull($result[$ids[1]]->primary_image_url);
        self::assertNotNull($result[$ids[2]]->primary_image_url);
        self::assertStringContainsString('example.com', $result[$ids[0]]->primary_image_url);
        self::assertLessThanOrEqual(2, $count, 'forProducts must batch (no N+1), got '.$count.' queries');
    }

    public function test_for_product_returns_make_empty_when_no_attachments(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        /** @var CatalogMediaQueryInterface $query */
        $query = app(CatalogMediaQueryInterface::class);

        $result = $query->forProduct($productId, $tenantId);

        self::assertNull($result->primary_image_url);
        self::assertSame([], $result->media);
    }

    public function test_for_products_returns_empty_for_products_with_no_attachments(): void
    {
        $tenantId = (string) Str::uuid();
        $pid1 = (string) Str::uuid();
        $pid2 = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'url',
            'external_url' => 'https://example.com/image.jpg',
        ]);

        MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $pid1,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        /** @var CatalogMediaQueryInterface $query */
        $query = app(CatalogMediaQueryInterface::class);
        $result = $query->forProducts([$pid1, $pid2], $tenantId);

        self::assertCount(2, $result);
        self::assertNotNull($result[$pid1]->primary_image_url);
        self::assertNull($result[$pid2]->primary_image_url);
        self::assertSame([], $result[$pid2]->media);
    }
}
