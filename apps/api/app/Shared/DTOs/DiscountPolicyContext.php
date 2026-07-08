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
    ) {}
}
