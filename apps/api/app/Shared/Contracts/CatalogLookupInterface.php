<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\CatalogProductDTO;
use App\Shared\Exceptions\PlatformCatalogUnavailableException;

interface CatalogLookupInterface
{
    /**
     * Normalizing lookup (cache-first). Null on a genuine miss or permanent
     * error (invalid barcode, unsupported vertical).
     *
     * @throws PlatformCatalogUnavailableException on
     *                                             transient platform failure — never treat an outage as a miss
     */
    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO;
}
