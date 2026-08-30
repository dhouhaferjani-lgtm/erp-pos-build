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
            if ($this->governingCellWasProvided($field, $provided) || ! array_key_exists($field, $existing)) {
                $merged[$field] = $value;
            }
        }

        return $merged;
    }

    /** @param list<string> $provided */
    private function governingCellWasProvided(string $field, array $provided): bool
    {
        $governing = match ($field) {
            'tax_rate', 'default_tax_configuration_id' => ['tax_rate', 'category_name'],
            'category_id' => ['category_name'],
            'brand_id', 'brand_source' => ['brand'],
            'sale_price' => ['sale_price', 'purchase_price', 'margin_percent', 'margin'],
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
