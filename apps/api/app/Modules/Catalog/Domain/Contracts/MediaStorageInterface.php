<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

use App\Modules\Catalog\Domain\Media\MediaAttachment;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface MediaStorageInterface
{
    /**
     * Serve a media attachment to an HTTP client.
     *
     * - EXTERNAL_URL asset → returns a 302 redirect to the external_url.
     * - Upload asset → resolves the best file path for the requested variant
     *   and returns a streamed 200 response (inline delivery).
     *
     * Legacy variant key mapping: 'sm' → THUMBNAIL, 'md' → SMALL.
     * Unknown / absent variant renditions fall back to the asset's original.
     *
     * @param  MediaAttachment  $attachment  Attachment with mediaAsset.renditions relation loaded.
     * @param  string|null  $variant  Legacy variant key ('sm', 'md', …) or null for original.
     */
    public function serve(MediaAttachment $attachment, ?string $variant): StreamedResponse|RedirectResponse;

    /**
     * Store file contents on the given disk at the given path.
     */
    public function put(string $disk, string $path, string $contents): void;

    /**
     * Retrieve file contents from the given disk, or null if absent.
     */
    public function get(string $disk, string $path): ?string;

    /**
     * Delete the file at the given disk and path.
     */
    public function delete(string $disk, string $path): void;
}
