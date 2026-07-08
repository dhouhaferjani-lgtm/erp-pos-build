<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Contracts;

use App\Modules\Media\Application\DTOs\FetchedImage;
use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;

interface PinnedImageDownloaderInterface
{
    /**
     * Download $url with the socket pinned to $pinnedIp (Host/SNI preserved),
     * redirects DENIED, aborting once maxBytes+1 bytes are read. Verifies the
     * connected peer IP == $pinnedIp.
     *
     * @throws RemoteImageFetchException on any violation
     */
    public function download(string $url, string $host, string $pinnedIp, int $maxBytes): FetchedImage;
}
