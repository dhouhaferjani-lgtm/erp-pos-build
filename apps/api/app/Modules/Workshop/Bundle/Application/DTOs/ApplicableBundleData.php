<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\DTOs;

use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Shared\Domain\CurrencyScale;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Compact projection of a bundle for the "applicable for this vehicle"
 * picker endpoint. Does not carry full component/applicability detail —
 * the detail endpoint returns {@see ServiceBundleData}.
 */
#[TypeScript]
final class ApplicableBundleData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public ?string $description,
        public BundlePricingMode $pricing_mode,
        public ?string $base_price,
        public string $currency,
        public ?int $service_interval_km,
        public ?int $service_interval_months,
        public ?string $estimated_labor_hours,
        public int $component_count,
    ) {}

    public static function fromModel(ServiceBundle $bundle): self
    {
        $scale = CurrencyScale::for($bundle->currency);

        return new self(
            id: $bundle->id,
            code: $bundle->code,
            name: $bundle->name,
            description: $bundle->description,
            pricing_mode: $bundle->pricing_mode,
            base_price: $bundle->base_price !== null
                ? CurrencyScale::bcformat($bundle->base_price, $scale)
                : null,
            currency: $bundle->currency,
            service_interval_km: $bundle->service_interval_km,
            service_interval_months: $bundle->service_interval_months,
            estimated_labor_hours: $bundle->estimated_labor_hours !== null
                ? CurrencyScale::bcformat($bundle->estimated_labor_hours, 2)
                : null,
            component_count: $bundle->relationLoaded('components') ? $bundle->components->count() : 0,
        );
    }
}
