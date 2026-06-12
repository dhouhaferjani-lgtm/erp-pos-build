<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;

/**
 * Pure URL builder for media attachments.
 *
 * Resolves a display URL from a loaded MediaAttachment:
 *   - ExternalUrl assets → return the external_url verbatim (no storage I/O).
 *   - Upload assets → build the authenticated download route URL so callers
 *     receive the byte-identical URL shape that the legacy product-image
 *     routes produced (/products/{product}/images/{image}/download?variant=…).
 *
 * PURE: no app() helper, no storage I/O, no DB queries.
 * Requires $a->mediaAsset to be already loaded (via eager-load or setRelation).
 */
final class MediaUrlResolver
{
    /**
     * @param  MediaAttachment  $a  Attachment with its mediaAsset relation loaded.
     * @param  string|null  $variant  Legacy variant key ('sm', 'md', …) passed as a
     *                                query-string parameter; null omits the param.
     * @return string|null The resolved display URL, or null when the asset is not loaded
     *                     or the asset has no external_url and no download route applies.
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
