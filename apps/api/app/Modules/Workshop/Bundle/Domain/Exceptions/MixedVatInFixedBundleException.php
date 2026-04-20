<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a `fixed_bundle` bundle's components span multiple VAT
 * rates. Fixed-bundle pricing is incompatible with mixed-VAT component
 * baskets because the flat price cannot be split back to line-level tax
 * without operator intent; the operator must either rely on Standard
 * pricing or harmonize component tax rates.
 */
final class MixedVatInFixedBundleException extends RuntimeException
{
    /**
     * @param  list<string>  $rates
     */
    public static function forRates(string $bundleId, array $rates): self
    {
        return new self(
            sprintf(
                'Bundle %s uses fixed_bundle pricing but its components span multiple VAT rates: %s.',
                $bundleId,
                implode(', ', $rates),
            ),
        );
    }
}
