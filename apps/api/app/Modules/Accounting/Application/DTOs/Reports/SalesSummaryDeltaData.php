<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SalesSummaryDeltaData extends Data
{
    public function __construct(
        public readonly string $grossSalesAbs,
        public readonly ?string $grossSalesPct,
        public readonly int $salesCountAbs,
        public readonly ?string $salesCountPct,
    ) {}
}
