<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Interface for product operations used by other modules.
 *
 * Module boundaries are sacred: Cross-module communication ONLY via interfaces.
 */
interface ProductServiceInterface
{
    /**
     * Find a product by SKU.
     *
     * @return string|null Product ID or null if not found
     */
    public function findIdBySku(
        string $tenantId,
        string $companyId,
        string $sku
    ): ?string;

    /**
     * Create or update a product.
     *
     * @param  array<string, mixed>  $data  Product data
     * @return string The product ID
     */
    public function upsert(
        string $tenantId,
        string $companyId,
        array $data
    ): string;
}
