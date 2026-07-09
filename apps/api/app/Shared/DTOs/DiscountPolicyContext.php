<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use App\Modules\Pricing\Domain\Enums\PriceBasis;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DiscountPolicyContext extends Data
{
    public function __construct(
        public string $companyId,
        public string $productId,
        public ?string $variantId,
        public string $effectiveUnitPrice,
        public string $currency,
        public string $quantity = '1.0000',
        public ?string $taxRate = null,
        public ?string $taxConfigurationId = null,
        public string $priceBasis = PriceBasis::Ht->value,
        public ?string $userId = null,
        /**
         * Whether the authenticated caller holds a floor-override permission
         * (pricing.sell_below_cost or pricing.sell_below_minimum_margin).
         * MUST be set server-side from the authenticated user — never accepted
         * from the request payload (it is a privilege claim).
         */
        public bool $callerHasFloorOverride = false,
    ) {}
}
