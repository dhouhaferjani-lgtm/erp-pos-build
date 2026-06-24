<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Media\Domain\Enums\MediaSource;
use Illuminate\Support\Facades\URL;

/**
 * Pure URL builder for media attachments.
 *
 * Two resolution strategies are provided depending on the caller:
 *
 *   forAttachment() — SPA display URLs.
 *     ExternalUrl → external_url verbatim.
 *     Upload      → short-lived HMAC-signed URL to `media.serve` (60-min TTL).
 *                   Allows <img src="…"> in the bearer-token SPA without an
 *                   Authorization header or a custom fetch proxy.
 *
 *   forPosSync() — POS sync payload.
 *     ExternalUrl → external_url verbatim (unchanged).
 *     Upload      → legacy `products.images.download` route URL.
 *                   The POS SQLite cache is keyed on this URL shape; it MUST
 *                   NOT change without a coordinated POS release.
 *
 * PURE: no app() helper, no storage I/O, no DB queries.
 * Requires $a->mediaAsset to be already loaded (via eager-load or setRelation).
 */
final class MediaUrlResolver
{
    /**
     * Signed URL TTL for uploaded assets served via `media.serve`.
     * 60 minutes covers a typical admin session page load.  The SPA should
     * refetch the image list (and get fresh signed URLs) on navigation.
     */
    private const SIGNED_URL_TTL_MINUTES = 60;

    /**
     * Resolve a display URL for use in the SPA (e.g. <img src="…"> tags).
     *
     * Upload assets return a short-lived HMAC-signed URL to `media.serve`
     * so the browser can load images without an Authorization header.
     *
     * @param  MediaAttachment  $a  Attachment with its mediaAsset relation loaded.
     * @param  string|null  $variant  Variant key ('sm', 'md') appended to the signed URL;
     *                                null omits the param.
     * @return string|null The resolved display URL, or null when the asset is not loaded.
     */
    public function forAttachment(MediaAttachment $a, ?string $variant): ?string
    {
        /** @var MediaAsset|null $asset */
        $asset = $a->mediaAsset;

        if ($asset === null) {
            return null;
        }

        if ($asset->source === MediaSource::ExternalUrl) {
            return $asset->external_url;
        }

        // Build a short-lived HMAC-signed URL to the `media.serve` route.
        // The {tenant} segment is embedded in the signed payload, so an
        // attacker cannot swap it without invalidating the HMAC.
        /** @var array<string, string> $params */
        $params = [
            'tenant' => (string) $a->tenant_id,
            'attachment' => $a->id,
        ];

        if ($variant !== null) {
            $params['variant'] = $variant;
        }

        // absolute: false → a RELATIVE signed URL (path + query only, no host).
        // The browser resolves it against the page origin, so it works behind the
        // nginx proxy / inside Docker regardless of APP_URL (which is the internal
        // service name, e.g. http://api). The HMAC covers the path + query, so the
        // serve route validates it with the `signed:relative` middleware.
        return URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
            $params,
            absolute: false,
        );
    }

    /**
     * Resolve a URL for the POS sync payload.
     *
     * The POS SQLite cache is keyed on this URL shape — it MUST NOT change
     * without a coordinated POS release.  Upload assets therefore keep the
     * legacy `products.images.download` route URL that the POS has always
     * received, while ExternalUrl assets continue to return the raw URL.
     *
     * @param  MediaAttachment  $a  Attachment with its mediaAsset relation loaded.
     * @param  string|null  $variant  Variant key ('sm', 'md') appended as ?variant=…;
     *                                null omits the param.
     * @return string|null The resolved POS image URL, or null when the asset is not loaded.
     */
    public function forPosSync(MediaAttachment $a, ?string $variant): ?string
    {
        /** @var MediaAsset|null $asset */
        $asset = $a->mediaAsset;

        if ($asset === null) {
            return null;
        }

        if ($asset->source === MediaSource::ExternalUrl) {
            return $asset->external_url;
        }

        // POS contract: frozen URL shape containing /images/{attachmentId}/download.
        // The Tauri POS caches image data keyed on (product_id + this URL) — do NOT
        // change this shape without a coordinated POS release.
        /** @var array<string, string> $params */
        $params = [
            'product' => $a->owner_id,
            'image' => $a->id,
        ];

        if ($variant !== null) {
            $params['variant'] = $variant;
        }

        return route('products.images.download', $params);
    }
}
