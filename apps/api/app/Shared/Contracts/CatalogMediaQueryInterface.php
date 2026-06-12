<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Catalog\Application\DTOs\ProductMediaData;

interface CatalogMediaQueryInterface
{
    /**
     * Return the full media payload for a single product.
     *
     * Delegates to forProducts() and returns makeEmpty() when the product has no
     * READY attachments.
     */
    public function forProduct(string $productId, string $tenantId): ProductMediaData;

    /**
     * Return the full media payload for a batch of products in a single query.
     *
     * @param  array<string>  $productIds
     * @return array<string, ProductMediaData> keyed by product_id; every requested id
     *                                         has an entry (makeEmpty() when no attachments)
     */
    public function forProducts(array $productIds, string $tenantId): array;
}
