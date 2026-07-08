<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Services;

use App\Modules\Media\Application\DTOs\FetchedImage;
use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Domain\Contracts\HostResolverInterface;
use App\Modules\Media\Domain\Contracts\PinnedImageDownloaderInterface;
use App\Modules\Media\Domain\ValueObjects\ExternalUrlGuard;
use App\Modules\Media\Domain\ValueObjects\PrivateIpRanges;
use Throwable;

/**
 * SSRF-hardened remote image fetcher.
 *
 * Guards the URL structurally, resolves the host and validates EVERY resolved
 * IP against the private/reserved ranges, then pins the socket to the first
 * validated public IP (delegated to an injected downloader that owns the
 * streamed, redirect-denied, byte-capped cURL transfer). Splitting resolve+guard
 * from the pinned transfer keeps the IP-pin behaviour testable in isolation.
 */
final class RemoteImageFetcher
{
    public const MAX_BYTES = 10_485_760; // 10 MiB

    public function __construct(
        private readonly HostResolverInterface $resolver,
        private readonly PinnedImageDownloaderInterface $downloader,
    ) {}

    public function fetch(string $url): FetchedImage
    {
        try {
            ExternalUrlGuard::assertHttpsHostAllowed($url);
        } catch (Throwable $e) {
            throw new RemoteImageFetchException("Blocked URL: {$e->getMessage()}", 0, $e);
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new RemoteImageFetchException("Host does not resolve: {$host}");
        }

        foreach ($ips as $ip) {
            if (PrivateIpRanges::isDisallowed($ip)) {
                throw new RemoteImageFetchException("Host resolves to a disallowed address: {$ip}");
            }
        }

        // Pin the socket to the first validated public IP while preserving Host +
        // SNI, so a post-check DNS rebind cannot redirect the transfer to a
        // private address. The downloader re-verifies the connected peer IP.
        $pinnedIp = $ips[0];

        // Thread the real connection port so the downloader's RESOLVE pin matches
        // the actual port; ExternalUrlGuard has already rejected any non-443 port.
        $parsedPort = parse_url($url, PHP_URL_PORT);
        $port = is_int($parsedPort) ? $parsedPort : 443;

        return $this->downloader->download($url, $host, $pinnedIp, $port, self::MAX_BYTES);
    }
}
