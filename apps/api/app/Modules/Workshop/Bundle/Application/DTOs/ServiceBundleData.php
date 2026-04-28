<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\DTOs;

use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Shared\Domain\CurrencyScale;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ServiceBundleData extends Data
{
    /**
     * @param  DataCollection<int, ServiceBundleComponentData>  $components
     * @param  DataCollection<int, ServiceBundleVehicleApplicabilityData>  $vehicle_applicabilities
     */
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $company_id,
        public string $code,
        public string $name,
        public ?string $description,
        public BundlePricingMode $pricing_mode,
        public ?string $base_price,
        public string $currency,
        public ?string $tax_rate,
        public ?string $estimated_labor_hours,
        public ?int $service_interval_km,
        public ?int $service_interval_months,
        public bool $is_active,
        #[DataCollectionOf(ServiceBundleComponentData::class)]
        public DataCollection $components,
        #[DataCollectionOf(ServiceBundleVehicleApplicabilityData::class)]
        public DataCollection $vehicle_applicabilities,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(ServiceBundle $bundle): self
    {
        $scale = CurrencyScale::for($bundle->currency);

        $components = $bundle->relationLoaded('components')
            ? $bundle->components->map(fn ($c): ServiceBundleComponentData => ServiceBundleComponentData::fromModel($c, $scale))->values()->all()
            : [];
        $applicabilities = $bundle->relationLoaded('vehicleApplicabilities')
            ? $bundle->vehicleApplicabilities->map(fn ($a): ServiceBundleVehicleApplicabilityData => ServiceBundleVehicleApplicabilityData::fromModel($a))->values()->all()
            : [];

        return new self(
            id: $bundle->id,
            tenant_id: $bundle->tenant_id,
            company_id: $bundle->company_id,
            code: $bundle->code,
            name: $bundle->name,
            description: $bundle->description,
            pricing_mode: $bundle->pricing_mode,
            base_price: $bundle->base_price !== null
                ? CurrencyScale::bcformat($bundle->base_price, $scale)
                : null,
            currency: $bundle->currency,
            tax_rate: $bundle->tax_rate !== null
                ? CurrencyScale::bcformat($bundle->tax_rate, 3)
                : null,
            estimated_labor_hours: $bundle->estimated_labor_hours !== null
                ? CurrencyScale::bcformat($bundle->estimated_labor_hours, 2)
                : null,
            service_interval_km: $bundle->service_interval_km,
            service_interval_months: $bundle->service_interval_months,
            is_active: $bundle->is_active,
            components: ServiceBundleComponentData::collect($components, DataCollection::class),
            vehicle_applicabilities: ServiceBundleVehicleApplicabilityData::collect($applicabilities, DataCollection::class),
            created_at: $bundle->created_at?->toIso8601String() ?? '',
            updated_at: $bundle->updated_at?->toIso8601String(),
        );
    }
}
