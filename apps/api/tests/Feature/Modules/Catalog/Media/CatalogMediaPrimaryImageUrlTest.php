<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Shared\Contracts\CatalogMediaQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUG-005 / RCA A1 — `primary_image_url` must be a RELATIVE HMAC-signed
 * `media.serve` URL, not the Bearer-auth-gated absolute
 * `products.images.download` URL.
 *
 * An `<img src="…">` tag cannot send an Authorization header, and the SPA
 * talks same-origin through the web proxy, so no cookie exists for the API
 * host either — the legacy download URL therefore 401s and renders as a
 * broken-image icon on the product hero.
 *
 * The per-attachment `media[]` array keeps `forPosSync()` byte-for-byte
 * unchanged (POS URL-shape contract) — asserted here as a regression guard.
 */
final class CatalogMediaPrimaryImageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_image_url_targets_signed_media_serve_route(): void
    {
        [$productId, $tenantId] = $this->makeUploadedPrimaryImage();

        /** @var CatalogMediaQueryInterface $query */
        $query = app(CatalogMediaQueryInterface::class);

        $media = $query->forProduct($productId, $tenantId);

        $url = $media->primary_image_url;

        self::assertNotNull($url, 'primary_image_url must resolve for an Upload-source primary attachment');
        self::assertStringStartsWith(
            '/',
            $url,
            'primary_image_url must be RELATIVE — an absolute API-host URL is mixed-content/cross-origin for the SPA'
        );
        self::assertStringContainsString(
            '/media/',
            $url,
            'primary_image_url must target the media.serve route, not products.images.download'
        );
        self::assertStringContainsString(
            'signature=',
            $url,
            'primary_image_url must be HMAC-signed so <img> can load it without a Bearer token'
        );
        self::assertStringContainsString(
            'variant=md',
            $url,
            'primary_image_url must request the md variant (hero size) via the resolver, not by string surgery'
        );
        self::assertStringNotContainsString(
            '/download',
            $url,
            'primary_image_url must NOT use the auth:sanctum-gated products.images.download route'
        );
    }

    public function test_pos_sync_media_url_shape_is_unchanged(): void
    {
        [$productId, $tenantId, $attachmentId] = $this->makeUploadedPrimaryImage();

        /** @var CatalogMediaQueryInterface $query */
        $query = app(CatalogMediaQueryInterface::class);

        $media = $query->forProduct($productId, $tenantId);

        self::assertCount(1, $media->media);
        $url = $media->media[0]->url;

        self::assertNotNull($url);
        self::assertStringContainsString(
            '/products/'.$productId.'/images/'.$attachmentId.'/download',
            $url,
            'POS URL shape is frozen (Tauri cache contract) — media[].url must stay on products.images.download'
        );
        self::assertStringContainsString('variant=sm', $url, 'POS payload variant must stay sm');
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function makeUploadedPrimaryImage(): array
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$tenantId.'/'.$productId.'/'.Str::uuid().'/original.jpg',
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

        return [$productId, $tenantId, $attachment->id];
    }
}
