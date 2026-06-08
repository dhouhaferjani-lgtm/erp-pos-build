<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\ProductVariantSummary;
use Illuminate\Support\Collection;

/**
 * Cross-module read contract for product variant data.
 *
 * Module boundaries are sacred: external modules (Pricing, POS, Inventory, etc.)
 * must access variant data ONLY via this interface — never by importing the
 * Catalog Eloquent model directly.
 */
interface ProductVariantLookup
{
    public function findById(string $id): ?ProductVariantSummary;

    public function findByBarcode(string $barcode, string $companyId): ?ProductVariantSummary;

    public function findBySku(string $sku, string $companyId): ?ProductVariantSummary;

    /**
     * @return Collection<int, ProductVariantSummary>
     */
    public function listForProduct(string $productId, bool $onlyActive = true): Collection;
}
