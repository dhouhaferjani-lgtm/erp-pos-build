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

        return false;
    }
}
