<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

/**
 * Thrown when a referenced variant cannot be found.
 *
 * Used by setDefault, resolveBarcode, resolveSku, and other look-up operations
 * that require the variant to exist.
 *
 * Lives in SharedDomain because variant resolution is cross-cutting across
 * Catalog, Inventory, BatchExpiry, and POS.
 */
final class MissingVariantException extends \DomainException
{
    public static function withId(string $variantId): self
    {
        return new self("Variant '{$variantId}' does not exist or has been deleted.");
    }
}
