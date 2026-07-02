<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * The product referenced by an enrichment submission does not exist, or is
 * not owned by the current company.
 */
final class ProductNotFoundForEnrichmentException extends RuntimeException
{
    public function __construct(string $productId)
    {
        parent::__construct("Product [{$productId}] not found for enrichment.");
    }
}
