<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Commands;

use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;

/**
 * Command carrying the fields needed to create a new service bundle.
 */
final readonly class CreateBundleCommand
{
    public function __construct(
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
    ) {}
}
