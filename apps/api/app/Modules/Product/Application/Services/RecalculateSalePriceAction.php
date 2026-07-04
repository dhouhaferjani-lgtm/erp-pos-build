<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;

final class RecalculateSalePriceAction
{
    public function __construct(private readonly MarginService $marginService) {}

    /**
     * Explicitly reprice a product, even if it was manually priced.
     *
     * Sets pricing_mode back to Auto and delegates to MarginService::updateSalePrice(),
     * which will now reprice because the product is Auto-mode again.
     * This is the only sanctioned way to reset a Manual product's price to the
     * effective target-margin price without manual intervention.
     *
     * @return bool True if the sale price was updated
     */
    public function execute(Product $product): bool
    {
        $product->pricing_mode = PricingMode::Auto;
        $product->save();

        return $this->marginService->updateSalePrice($product);
    }
}
