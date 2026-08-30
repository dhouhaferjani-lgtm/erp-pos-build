<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\UnitCatalogQueryInterface;
use App\Shared\DTOs\ProductUnitBackfillResultData;
use App\Shared\DTOs\UnitCatalogEntryData;
use Throwable;

final readonly class ProductUnitBackfillService
{
    public function __construct(private UnitCatalogQueryInterface $units) {}

    public function backfill(): ProductUnitBackfillResultData
    {
        $mapped = 0;
        $ambiguous = 0;
        $unknown = 0;
        $missingCompany = 0;
        $visibleByCompany = [];

        Product::query()
            ->whereNull('unit_id')
            ->whereNotNull('unit')
            ->lazyById(500)
            ->each(function (Product $product) use (
                &$mapped,
                &$ambiguous,
                &$unknown,
                &$missingCompany,
                &$visibleByCompany,
            ): void {
                $companyId = (string) $product->company_id;
                if (! array_key_exists($companyId, $visibleByCompany)) {
                    try {
                        $visibleByCompany[$companyId] = $this->units->visibleUnits($companyId);
                    } catch (Throwable) {
                        $visibleByCompany[$companyId] = null;
                    }
                }

                $visible = $visibleByCompany[$companyId];
                if (! is_array($visible)) {
                    $missingCompany++;

                    return;
                }
                $matches = UnitCatalogEntryData::exactCodeMatchesAtWinningTier(
                    $visible,
                    trim((string) $product->unit),
                );

                if (count($matches) === 1) {
                    $product->update(['unit_id' => $matches[0]->id, 'unit' => $matches[0]->code]);
                    $mapped++;
                } elseif ($matches === []) {
                    $unknown++;
                } else {
                    $ambiguous++;
                }
            });

        return new ProductUnitBackfillResultData($mapped, $ambiguous, $unknown, $missingCompany);
    }
}
