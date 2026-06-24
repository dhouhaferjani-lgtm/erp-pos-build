<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Contracts;

use App\Modules\Media\Domain\Media\MediaAttachment;
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

    /**
     * Stream the file at the given disk and path as a download response.
     *
     * Preserves the original filename and MIME type in the response headers
     * (Content-Disposition: attachment; filename="…", Content-Type: …).
     *
     * @param  string  $disk  Storage disk identifier (e.g. 's3').
     * @param  string  $path  Path within the disk.
     * @param  string  $filename  Original filename to surface in Content-Disposition.
     * @param  string  $mimeType  MIME type to use in Content-Type header.
     */
    public function download(string $disk, string $path, string $filename, string $mimeType): StreamedResponse;
}
