<?php

declare(strict_types=1);

namespace App\Modules\Media\Infrastructure\Storage;

use App\Modules\Media\Domain\Contracts\MediaStorageInterface;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\RenditionName;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Media\Domain\Media\MediaRendition;
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

            // A rendition ROW can outlive its object (interrupted encode, manual
            // bucket cleanup). Treat a missing object exactly like a missing row
            // and fall through to the original rather than 404ing a variant of
            // an asset whose original is perfectly serveable.
            if ($rendition !== null && Storage::disk($rendition->storage_disk)->exists($rendition->storage_path)) {
                $disk = $rendition->storage_disk;
                $path = $rendition->storage_path;
            }
            // else: rendition row/object absent → fall through to the original path
        }
        // else: unknown/null variant → serve the original

        // BUG-005 / A2 follow-up (authz gate 2026-08-06): assets are created
        // READY and GenerateRenditions no longer marks them FAILED, so the
        // status filter in SignedMediaController no longer hides an asset whose
        // ORIGINAL object never landed (partial S3/MinIO put, or a legacy row
        // promoted by the backfill migration). Streaming a missing key yields a
        // metadata error / truncated body — a 500 plus Sentry noise — where the
        // honest answer is a clean 404.
        if (! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        // The signed URL is now stable within an expiry bucket (MediaUrlResolver),
        // so the browser can actually reuse a cached response instead of
        // re-downloading every image on each grid render — which would otherwise
        // trip the `signed-media` rate limiter on a large product grid.
        // `private`: signed URLs are per-tenant and must never enter a shared cache.
        return Storage::disk($disk)->response($path, headers: [
            'Cache-Control' => 'private, max-age=3600, immutable',
        ]);
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

    /**
     * {@inheritDoc}
     */
    public function download(string $disk, string $path, string $filename, string $mimeType): StreamedResponse
    {
        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException('Attachment file not found on storage.');
        }

        return Storage::disk($disk)->download($path, $filename, ['Content-Type' => $mimeType]);
    }
}
