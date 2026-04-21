<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\ValueObjects;

use App\Modules\Service\Domain\Enums\PricingType;

/**
 * Minimal projection of a Service (labor-catalog entry) used by bundle
 * expansion. Bundle's Domain layer resolves labor via
 * `ServiceResolverInterface`, which returns this VO.
 */
final readonly class ComponentServiceRef
{
    public function __construct(
        public string $service_id,
        public string $display_name,
        public PricingType $pricing_type,
        public string $base_price,       // scaled decimal string
        public ?string $hourly_rate,     // scaled decimal string or null
        public string $currency,
        public ?string $tax_rate,
        public ?int $default_duration_minutes,
    ) {}
}
