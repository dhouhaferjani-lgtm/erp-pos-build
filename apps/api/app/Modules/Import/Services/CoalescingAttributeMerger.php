<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Shared\Contracts\CoalescingAttributeMergerInterface;

final class CoalescingAttributeMerger implements CoalescingAttributeMergerInterface
{
    /**
     * @param  array<string, bool|int|string|null>  $existing
     * @param  array<string, bool|int|string|null>  $incoming
     * @param  list<string>  $provided
     * @return array<string, bool|int|string|null>
     */
    public function merge(array $existing, array $incoming, array $provided): array
    {
        $merged = $existing;
        foreach ($incoming as $field => $value) {
            if ($field === 'sale_price' && $value === null && array_key_exists($field, $existing)) {
                continue;
            }

            if ($this->governingCellWasProvided($field, $provided) || ! array_key_exists($field, $existing)) {
                $merged[$field] = $value;
            }
        }

        return $merged;
    }

    /**
     * Keep each derived attribute governed by the complete source-cell set read by
     * its resolver: ImportService::resolveRowCategory()/resolveProductTax() for
     * category and tax, ProductService::upsertWithResult() for brand,
     * ImportService::importProduct() + ProductUnitResolver::resolve() for unit,
     * and ProductPriceResolver::resolve() for sale price.
     *
     * @param  list<string>  $provided
     */
    private function governingCellWasProvided(string $field, array $provided): bool
    {
        $governing = match ($field) {
            'tax_rate', 'default_tax_configuration_id' => ['tax_rate', 'category_name'],
            'category_id' => ['category_name'],
            'brand_id', 'brand_source' => ['brand'],
            // Exact ProductPriceResolver::resolve() row read-set; keep in lockstep.
            'sale_price' => [
                'sale_price',
                'sale_price_incl_tax',
                'sale_price_excl_tax',
                'purchase_price',
                'margin',
            ],
            'unit_id' => ['unit'],
            default => [$field],
        };

        foreach ($governing as $cell) {
            if (in_array($cell, $provided, true)) {
                return true;
            }
        }

        return false;
    }
}
