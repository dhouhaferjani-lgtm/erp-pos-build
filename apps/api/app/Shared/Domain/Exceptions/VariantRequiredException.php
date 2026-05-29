<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

/**
 * Thrown when an operation requires at least one variant to exist for a product
 * but none have been created yet, OR when a product that has active variants is
 * addressed at the product level without specifying a variant.
 *
 * Lives in SharedDomain because the no-mixed-mode invariant is cross-cutting:
 * it is enforced by Catalog (set default variant), Inventory (stock mutations),
 * BatchExpiry, and POS.
 */
final class VariantRequiredException extends \DomainException
{
    public static function forProduct(string $productId): self
    {
        return new self("Product '{$productId}' has active variants; a variant_id is required for this operation.");
    }
}
