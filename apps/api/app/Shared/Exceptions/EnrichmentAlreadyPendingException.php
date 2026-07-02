<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * The product already has a live (pending / in-progress) enrichment
 * submission; a second submission would orphan the first correlation.
 */
final class EnrichmentAlreadyPendingException extends RuntimeException
{
    public function __construct(string $productId)
    {
        parent::__construct("Product [{$productId}] already has a pending enrichment submission.");
    }
}
