<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog\Media;

use App\Modules\Media\Application\Services\MediaUrlResolver;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit tests for MediaUrlResolver — two resolution strategies:
 *
 *  forAttachment() — SPA display URLs (signed `media.serve` for uploads).
 *  forPosSync()    — POS sync payload (legacy `products.images.download` route,
 *                    URL shape is frozen per the POS cache contract).
 */
final class MediaUrlResolverTest extends TestCase
{
    // -----------------------------------------------------------------------
    // forAttachment() — SPA signed-URL strategy
    // -----------------------------------------------------------------------

    /**
     * BUG-005 follow-up (authz gate 2026-08-06) — signed-URL stability.
     *
     * `primary_image_url` is minted per product row on the LIST endpoint. With a
     * rolling `now()->addMinutes(60)` expiry the signature changed on every
     * response, so every refetch produced brand-new URLs and invalidated the
     * browser image cache wholesale — a 60-100 product grid re-downloading each
     * minute, shared across terminals behind one NAT, trips the `signed-media`
     * limiter and images break intermittently.
     *
     * The expiry is bucketed so two calls inside the same window mint a
     * byte-identical URL, which is what makes the response cacheable.
     */
    public function test_for_attachment_mints_byte_identical_urls_within_an_expiry_bucket(): void
    {
        $resolver = new MediaUrlResolver;

        $tenantId = (string) Str::uuid();

        $asset = new MediaAsset;
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment;
        $attachment->id = (string) Str::uuid();
        $attachment->tenant_id = $tenantId;
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $first = $resolver->forAttachment($attachment, 'md');
        // Simulate a later request inside the same bucket.
        $this->travel(3)->minutes();
        $second = $resolver->forAttachment($attachment, 'md');

        self::assertSame(
            $first,
            $second,
            'Two resolutions inside one expiry bucket must produce identical URLs so the browser cache survives a refetch'
        );
    }

    public function test_bucketed_url_is_still_valid_for_at_least_an_hour(): void
    {
        $resolver = new MediaUrlResolver;

        $asset = new MediaAsset;
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment;
        $attachment->id = (string) Str::uuid();
        $attachment->tenant_id = (string) Str::uuid();
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forAttachment($attachment, 'md');
        self::assertNotNull($url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertArrayHasKey('expires', $query);

        // Bucketing must never shorten the URL's life below the Cache-Control
        // max-age (3600s) the serve route advertises, or the browser would keep
        // serving a cached response for a URL the server has already expired.
        self::assertGreaterThanOrEqual(
            now()->addHour()->getTimestamp(),
            (int) $query['expires'],
            'A bucketed URL must stay valid for at least the advertised max-age'
        );
    }

    public function test_for_attachment_upload_returns_signed_media_serve_url_with_variant(): void
    {
        $resolver = new MediaUrlResolver;

        $tenantId = (string) Str::uuid();
        $attachmentId = (string) Str::uuid();

        $asset = new MediaAsset;
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment;
        $attachment->id = $attachmentId;
        $attachment->tenant_id = $tenantId;
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forAttachment($attachment, 'sm');

        self::assertNotNull($url, 'forAttachment must return a URL for an Upload asset');
        // Relative signed URL: starts with '/', no scheme/host prefix.
        self::assertStringStartsWith('/', $url, 'Signed URL must be relative (no scheme/host — proxy/Docker-safe)');
        // Signed URL goes to the media.serve route, not the legacy download route.
        self::assertStringContainsString('/media/', $url, 'Signed URL must contain /media/ (media.serve route)');
        self::assertStringContainsString($attachmentId, $url, 'Signed URL must embed the attachment id');
        self::assertStringContainsString($tenantId, $url, 'Signed URL must embed the tenant id');
        self::assertStringContainsString('signature=', $url, 'Signed URL must contain an HMAC signature parameter');
        self::assertStringContainsString('variant=sm', $url, 'Signed URL must contain variant=sm');
        // Signed URL must NOT use the legacy download route shape.
        self::assertStringNotContainsString('/images/', $url, 'SPA signed URL must not use the legacy /images/ route');
    }

    public function test_for_attachment_upload_null_variant_is_omitted(): void
    {
        $resolver = new MediaUrlResolver;

        $tenantId = (string) Str::uuid();
        $attachmentId = (string) Str::uuid();

        $asset = new MediaAsset;
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment;
        $attachment->id = $attachmentId;
        $attachment->tenant_id = $tenantId;
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forAttachment($attachment, null);

        self::assertNotNull($url);
        self::assertStringStartsWith('/', $url, 'Signed URL must be relative (no scheme/host — proxy/Docker-safe)');
        self::assertStringContainsString('/media/', $url);
        self::assertStringContainsString('signature=', $url);
        self::assertStringNotContainsString('variant=', $url, 'Null variant must be omitted from the signed URL');
    }

    public function test_for_attachment_external_url_returns_asset_external_url(): void
    {
        $resolver = new MediaUrlResolver;

        $asset = new MediaAsset;
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::ExternalUrl;
        $asset->status = MediaStatus::Ready;
        $asset->external_url = 'https://cdn.example.com/photo.jpg';

        $attachment = new MediaAttachment;
        $attachment->id = (string) Str::uuid();
        $attachment->tenant_id = (string) Str::uuid();
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forAttachment($attachment, 'sm');

        // ExternalUrl must return the raw URL — no signed route wrapping.
        self::assertSame('https://cdn.example.com/photo.jpg', $url);
    }

    public function test_for_attachment_null_asset_returns_null(): void
    {
        $resolver = new MediaUrlResolver;

        $attachment = new MediaAttachment;
        $attachment->id = (string) Str::uuid();
        $attachment->tenant_id = (string) Str::uuid();
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', null);

        self::assertNull($resolver->forAttachment($attachment, 'sm'));
    }

    // -----------------------------------------------------------------------
    // forPosSync() — POS frozen URL contract (legacy download route)
    // -----------------------------------------------------------------------

    public function test_for_pos_sync_upload_returns_download_route_with_variant_query_string(): void
    {
        $resolver = new MediaUrlResolver;

        $productId = (string) Str::uuid();
        $attachmentId = (string) Str::uuid();

        $asset = new MediaAsset;
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment;
        $attachment->id = $attachmentId;
        $attachment->tenant_id = (string) Str::uuid();
        $attachment->owner_id = $productId;
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forPosSync($attachment, 'sm');

        // POS contract: URL must contain /images/{attachmentId}/download and variant=sm.
        // This shape is frozen — the Tauri POS caches image data keyed on this URL.
        self::assertNotNull($url);
        self::assertStringContainsString('/images/'.$attachmentId.'/download', $url);
        self::assertStringContainsString('variant=sm', $url);
        // Must NOT be a signed URL — POS does not validate HMAC.
        self::assertStringNotContainsString('signature=', $url);
    }

    public function test_for_pos_sync_null_variant_is_omitted(): void
    {
        $resolver = new MediaUrlResolver;

        $productId = (string) Str::uuid();
        $attachmentId = (string) Str::uuid();

        $asset = new MediaAsset;
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::Upload;
        $asset->status = MediaStatus::Ready;

        $attachment = new MediaAttachment;
        $attachment->id = $attachmentId;
        $attachment->tenant_id = (string) Str::uuid();
        $attachment->owner_id = $productId;
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forPosSync($attachment, null);

        self::assertNotNull($url);
        self::assertStringContainsString('/images/'.$attachmentId.'/download', $url);
        self::assertStringNotContainsString('variant=', $url);
    }

    public function test_for_pos_sync_external_url_returns_asset_external_url(): void
    {
        $resolver = new MediaUrlResolver;

        $externalUrl = 'https://cdn.example.com/placeholder.jpg';

        $asset = new MediaAsset;
        $asset->type = MediaAssetType::Image;
        $asset->source = MediaSource::ExternalUrl;
        $asset->status = MediaStatus::Ready;
        $asset->external_url = $externalUrl;

        $attachment = new MediaAttachment;
        $attachment->id = (string) Str::uuid();
        $attachment->tenant_id = (string) Str::uuid();
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forPosSync($attachment, 'sm');

        self::assertSame($externalUrl, $url);
    }
}
