<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Contracts;

use App\Modules\Media\Application\DTOs\FetchedImage;
use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Application\Services\RemoteImageFetcher;
use App\Modules\Media\Domain\ValueObjects\ExternalUrlGuard;

interface PinnedImageDownloaderInterface
{
    /**
     * Download $url with the socket pinned to $pinnedIp for ($host, $port)
     * (Host/SNI preserved), redirects DENIED, aborting once maxBytes+1 bytes are
     * read. Verifies the connected peer IP == $pinnedIp.
     *
     * The $port MUST be the actual connection port of $url (e.g. 443 for https)
     * so the pin matches the connection; a mismatched port makes the pin inert.
     *
     * SCHEME-TRUST BOUNDARY: this contract does NOT validate the URL scheme, host
     * safety, or port. Callers MUST pre-validate the URL with
     * {@see ExternalUrlGuard::assertHttpsHostAllowed}
     * (as {@see RemoteImageFetcher} does)
     * and resolve/guard the IP before calling. Passing an unvalidated URL here is
     * an SSRF risk the downloader is not required to catch.
     *
     * @throws RemoteImageFetchException on any violation
     */
    public function download(string $url, string $host, string $pinnedIp, int $port, int $maxBytes): FetchedImage;
}
