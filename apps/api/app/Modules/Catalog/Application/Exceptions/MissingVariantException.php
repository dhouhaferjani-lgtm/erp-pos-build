<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Exceptions;

/**
 * Thrown when a referenced variant cannot be found.
 *
 * Used by setDefault, resolveBarcode, resolveSku, and other look-up operations
 * that require the variant to exist.
 */
final class MissingVariantException extends \DomainException
{
    public static function withId(string $variantId): self
    {
        return new self("Variant '{$variantId}' does not exist or has been deleted.");
    }
}
