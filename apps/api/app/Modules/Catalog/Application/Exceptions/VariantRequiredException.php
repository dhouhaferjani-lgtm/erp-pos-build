<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Exceptions;

/**
 * Thrown when an operation requires at least one variant to exist for a product
 * but none have been created yet.
 *
 * Example: attempting to set a default variant on a product that has no variants.
 */
final class VariantRequiredException extends \DomainException
{
    public static function forProduct(string $productId): self
    {
        return new self("Product '{$productId}' has no variants. Create a variant first.");
    }
}
