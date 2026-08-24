<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\CategoryResolutionDTO;

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

    /**
     * Resolve a free-text category name to a company category, creating it when
     * no category carries that name (W2-3 — a miss used to be dropped silently).
     *
     * The outcome tells the caller whether master data changed, so an import can
     * report it on the row instead of conjuring categories behind the operator.
     *
     * @param  string  $name  Raw value; must be non-blank after trimming.
     */
    public function resolveCategoryByName(
        string $companyId,
        string $name
    ): CategoryResolutionDTO;
}
