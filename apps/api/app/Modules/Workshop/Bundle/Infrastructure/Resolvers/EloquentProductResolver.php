<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Infrastructure\Resolvers;

use App\Modules\Product\Domain\Product;
use App\Modules\Workshop\Bundle\Domain\Contracts\ProductResolverInterface;
use App\Modules\Workshop\Bundle\Domain\ValueObjects\ComponentProductRef;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Str;

final class EloquentProductResolver implements ProductResolverInterface
{
    public function findForBundleComponent(string $productId): ?ComponentProductRef
    {
        if (! Str::isUuid($productId)) {
            return null;
        }

        $product = Product::query()
            ->with('unitOfMeasure')
            ->find($productId);

        if ($product === null) {
            return null;
        }

        // Products do not yet carry a dedicated currency column; the
        // company-wide currency via $product->company->currency is
        // authoritative. BundleExpansionService re-scales based on the
        // bundle's own currency at expansion time.
        $currency = $product->company->currency !== '' ? $product->company->currency : 'TND';
        $scale = CurrencyScale::for($currency);

        $unit = $product->unitOfMeasure !== null
            ? ($product->unitOfMeasure->symbol !== '' ? $product->unitOfMeasure->symbol : $product->unitOfMeasure->code)
            : ($product->unit ?? 'piece');

        return new ComponentProductRef(
            product_id: $product->id,
            display_name: $product->name,
            sale_price: $product->sale_price !== null
                ? CurrencyScale::bcformat($product->sale_price, $scale)
                : null,
            currency: $currency,
            tax_rate: $product->tax_rate !== null
                ? CurrencyScale::bcformat($product->tax_rate, 3)
                : null,
            unit: (string) $unit,
        );
    }
}
