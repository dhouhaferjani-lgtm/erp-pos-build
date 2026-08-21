<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SalesSummaryDeltaData extends Data
{
    /**
     * `netSalesAbs` / `netSalesPct` trend the NET-of-returns figure (O-28, owner ruling
     * 2026-08-21: the Today's-Sales headline is net, excluding refunds). The headline
     * tile must carry the trend of the number it displays — a net figure badged with a
     * gross-derived percentage is precisely the blend the ruling forbids. The gross
     * pair is retained for surfaces that report gross explicitly.
     */
    public function __construct(
        public readonly string $grossSalesAbs,
        public readonly ?string $grossSalesPct,
        public readonly string $netSalesAbs,
        public readonly ?string $netSalesPct,
        public readonly int $salesCountAbs,
        public readonly ?string $salesCountPct,
    ) {}
}
