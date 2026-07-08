<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\ValueObjects;

final class PrivateIpRanges
{
    public static function isDisallowed(string $ip): bool
    {
        // Reject anything that is not a global, non-reserved unicast address.
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (! $isPublic) {
            return true;
        }

        // CGNAT 100.64.0.0/10 is not covered by NO_RES_RANGE on all PHP builds.
        if (str_starts_with($ip, '100.')) {
            $second = (int) explode('.', $ip)[1];
            if ($second >= 64 && $second <= 127) {
                return true;
            }
        }

        // filter_var()'s NO_RES_RANGE flag does not cover multicast, nor
        // IPv6 tunnels that embed a private IPv4 payload. Inspect the raw
        // address bytes (via inet_pton) rather than relying on un-normalized
        // string prefixes.
        $packed = inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            // IPv4 multicast: 224.0.0.0/4
            $firstOctet = ord($packed[0]);
            if ($firstOctet >= 224 && $firstOctet <= 239) {
                return true;
            }
        } elseif (strlen($packed) === 16) {
            $b0 = ord($packed[0]);
            $b1 = ord($packed[1]);
            $b2 = ord($packed[2]);
            $b3 = ord($packed[3]);

            // IPv6 multicast: ff00::/8
            if ($b0 === 0xFF) {
                return true;
            }

            // 6to4 tunnel: 2002::/16 (embeds an IPv4 payload, may be private)
            if ($b0 === 0x20 && $b1 === 0x02) {
                return true;
            }

            // Teredo tunnel: 2001:0000::/32 (embeds an IPv4 payload, may be private)
            if ($b0 === 0x20 && $b1 === 0x01 && $b2 === 0x00 && $b3 === 0x00) {
                return true;
            }
        }

        return false;
    }
}
