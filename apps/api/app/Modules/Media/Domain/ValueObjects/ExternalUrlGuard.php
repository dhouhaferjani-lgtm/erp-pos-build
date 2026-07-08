<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\ValueObjects;

use InvalidArgumentException;

final class ExternalUrlGuard
{
    private const MAX_LENGTH = 2048;

    /** @var list<string> */
    private const BLOCKED_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /** @var list<string> */
    private const PRIVATE_IP_PREFIXES = ['10.', '192.168.', '169.254.', '172.16.', '172.17.', '172.18.', '172.19.', '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.'];

    public static function assertHttpsHostAllowed(string $url): void
    {
        if (strlen($url) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('URL exceeds maximum length.');
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https') {
            throw new InvalidArgumentException('URL must be https.');
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new InvalidArgumentException('URL host is missing.');
        }

        // Product images are served over standard https on port 443. Reject any
        // explicit non-443 port: it would let the pinned downloader's RESOLVE
        // entry (which must match the real connection port) drift, and a non-443
        // https endpoint is not a legitimate CDN image origin. A URL with no
        // explicit port, or an explicit :443, is allowed.
        $port = parse_url($url, PHP_URL_PORT);
        if ($port !== null && $port !== 443) {
            throw new InvalidArgumentException('URL port is not allowed; only standard https (443) is permitted.');
        }

        $host = strtolower(trim($host, '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new InvalidArgumentException('URL host is blocked.');
        }

        foreach (self::PRIVATE_IP_PREFIXES as $prefix) {
            if (str_starts_with($host, $prefix)) {
                throw new InvalidArgumentException('URL host is a private address.');
            }
        }
    }
}
