<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SalesByLocationData extends Data
{
    public function __construct(
        public readonly string $period,
        public readonly string $company_id,
        public readonly string $company_name,
        public readonly string $location_id,
        public readonly string $location_name,
        public readonly string $gross_sales,
        public readonly int $receipt_count,
    ) {}
}
