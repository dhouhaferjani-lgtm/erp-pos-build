<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\DTOs;

use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Shared\Domain\CurrencyScale;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ServiceData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $category_id,
        public ?ServiceCategoryData $category,
        public PricingType $pricing_type,
        public string $base_price,
        public string $currency,
        public ?int $default_duration_minutes,
        public ?string $hourly_rate,
        public ?string $tax_rate,
        public bool $is_active,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Service $service, int $scale = 3): self
    {
        return new self(
            id: $service->id,
            code: $service->code,
            name: $service->name,
            description: $service->description,
            category_id: $service->category_id,
            category: $service->relationLoaded('category') && $service->category !== null
                ? ServiceCategoryData::fromModel($service->category)
                : null,
            pricing_type: $service->pricing_type,
            base_price: CurrencyScale::bcformat($service->base_price, $scale),
            currency: $service->currency,
            default_duration_minutes: $service->default_duration_minutes,
            hourly_rate: $service->hourly_rate !== null ? CurrencyScale::bcformat($service->hourly_rate, $scale) : null,
            tax_rate: $service->tax_rate !== null ? CurrencyScale::bcformat($service->tax_rate, 2) : null,
            is_active: $service->is_active,
            created_at: $service->created_at?->toIso8601String() ?? '',
            updated_at: $service->updated_at?->toIso8601String(),
        );
    }
}
