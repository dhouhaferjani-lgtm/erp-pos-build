<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SalesSummaryData extends Data
{
    public function __construct(
        public readonly string $currencyCode,
        public readonly string $grossSales,
        public readonly string $returnsAmount,
        public readonly string $netSales,
        public readonly int $salesCount,
        public readonly int $returnsCount,
        public readonly string $itemsSold,
        public readonly ?string $averageBasket,
        public readonly SalesSummaryDeltaData $delta,
    ) {}
}
