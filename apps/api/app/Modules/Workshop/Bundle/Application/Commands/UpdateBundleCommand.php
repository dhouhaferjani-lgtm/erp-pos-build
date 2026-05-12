<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Commands;

use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;

/**
 * Command carrying an update to an existing bundle. Optional fields
 * (null) leave the stored value untouched.
 */
final readonly class UpdateBundleCommand
{
    public function __construct(
        public string $bundle_id,
        public ?string $name,
        public ?string $description,
        public ?BundlePricingMode $pricing_mode,
        public ?string $base_price,
        public ?string $currency,
        public ?string $tax_rate,
        public ?string $estimated_labor_hours,
        public ?int $service_interval_km,
        public ?int $service_interval_months,
        public ?bool $is_active,
        public string $tenant_id,
        public string $company_id,
    ) {}
}
