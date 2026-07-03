<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\CatalogProductDTO;

interface CatalogLookupInterface
{
    /**
     * Normalizing lookup (cache-first). Null when not found, platform unavailable, or invalid barcode.
     */
    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO;
}
