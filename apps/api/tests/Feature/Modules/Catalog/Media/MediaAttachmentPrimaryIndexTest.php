<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaAttachmentPrimaryIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Partial unique index is PG-only.');
        }
    }

    public function test_second_primary_for_same_global_slot_violates_unique_index(): void
    {
        $tenantId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();
        $asset = fn () => MediaAsset::create([
            'tenant_id' => $tenantId, 'type' => MediaAssetType::Image, 'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready, 'storage_disk' => 'url', 'external_url' => 'https://x/y.jpg',
        ]);

        MediaAttachment::create([
            'tenant_id' => $tenantId, 'media_asset_id' => $asset()->id,
            'owner_type' => MediaOwnerType::Product, 'owner_id' => $ownerId,
            'role' => MediaRole::Primary, 'sort_order' => 0,
        ]);

        $this->expectException(QueryException::class);
        MediaAttachment::create([
            'tenant_id' => $tenantId, 'media_asset_id' => $asset()->id,
            'owner_type' => MediaOwnerType::Product, 'owner_id' => $ownerId,
            'role' => MediaRole::Primary, 'sort_order' => 1,
        ]);
    }
}
