<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Storage;

use App\Modules\Catalog\Domain\Contracts\MediaStorageInterface;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\RenditionName;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Catalog\Domain\Media\MediaRendition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Disk-aware media serve adapter.
 *
 * Responsibilities:
 *   - EXTERNAL_URL assets  → 302 redirect to the external_url (no byte I/O).
 *   - Upload assets        → resolve the best storage path for the requested
 *     variant (legacy key mapping sm→THUMBNAIL, md→SMALL; unknown/absent
 *     variant falls back to the asset's original path) and stream it inline.
 *
 * URL *building* (route generation for download links) lives in
 * MediaUrlResolver (Task 5b); this adapter handles byte *serving* only.
 *
 * Requires $attachment->mediaAsset (and its renditions relation) to be
 * already loaded before calling serve(). The contract type-hints MediaAttachment
 * which holds a non-null BelongsTo; callers must eager-load the relation.
 */
final class MediaStorageAdapter implements MediaStorageInterface
{
    /**
     * Legacy variant key → RenditionName mapping.
     *
     * @var array<string, RenditionName>
     */
    private const VARIANT_MAP = [
        'sm' => RenditionName::Thumbnail,
        'md' => RenditionName::Small,
    ];

    /**
     * {@inheritDoc}
     */
    public function serve(MediaAttachment $attachment, ?string $variant): StreamedResponse|RedirectResponse
    {
        /** @var MediaAsset $asset */
        $asset = $attachment->mediaAsset;

        if ($asset->source === MediaSource::ExternalUrl) {
            return redirect()->away((string) $asset->external_url);
        }

        // Resolve the rendition for the requested variant key.
        $renditionName = $variant !== null && isset(self::VARIANT_MAP[$variant])
            ? self::VARIANT_MAP[$variant]
            : null;

        $disk = $asset->storage_disk;
        $path = (string) $asset->storage_path;

        if ($renditionName !== null) {
            /** @var MediaRendition|null $rendition */
            $rendition = $asset->renditions
                ->first(fn (MediaRendition $r): bool => $r->name === $renditionName);

            if ($rendition !== null) {
                $disk = $rendition->storage_disk;
                $path = $rendition->storage_path;
            }
            // else: rendition row absent → fall through to the original path
        }
        // else: unknown/null variant → serve the original

        return Storage::disk($disk)->response($path);
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $disk, string $path, string $contents): void
    {
        Storage::disk($disk)->put($path, $contents);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $disk, string $path): ?string
    {
        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return Storage::disk($disk)->get($path);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }
}
