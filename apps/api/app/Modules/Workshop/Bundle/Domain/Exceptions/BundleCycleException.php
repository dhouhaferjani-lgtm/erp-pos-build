<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when adding or updating a bundle component would introduce a
 * composition cycle (bundle A → B → ... → A).
 */
final class BundleCycleException extends RuntimeException
{
    public static function between(string $bundleId, string $nestedBundleId): self
    {
        return new self(
            sprintf(
                'Adding bundle %s as a nested component of %s would create a cycle.',
                $nestedBundleId,
                $bundleId,
            ),
        );
    }
}
