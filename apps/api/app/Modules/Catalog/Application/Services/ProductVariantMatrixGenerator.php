<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

/**
 * Pure cartesian-product helper — no DB, no side effects, no injected deps.
 *
 * Operates on raw string arrays of "codes" (attribute_code => [value_codes]).
 * The caller is responsible for mapping codes back to IDs before persisting.
 */
final class ProductVariantMatrixGenerator
{
    /**
     * Generate all combinations of the given axes, excluding any combos that
     * appear in $excluded.
     *
     * @param  array<string, string[]>  $axes  attribute_code => [value_codes]
     * @param  array<int, array<string, string>>  $excluded  combos to skip (same shape as output)
     * @return array<int, array<string, string>>
     */
    public function cartesian(array $axes, array $excluded = []): array
    {
        if ($axes === []) {
            return [];
        }

        $result = [[]];

        foreach ($axes as $axisCode => $values) {
            $next = [];

            foreach ($result as $partial) {
                foreach ($values as $val) {
                    $next[] = $partial + [$axisCode => $val];
                }
            }

            $result = $next;
        }

        return array_values(
            array_filter($result, fn (array $combo): bool => ! in_array($combo, $excluded, true))
        );
    }
}
