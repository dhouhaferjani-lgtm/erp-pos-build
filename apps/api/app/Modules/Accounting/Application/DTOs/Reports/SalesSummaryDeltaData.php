<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SalesSummaryDeltaData extends Data
{
    /**
     * `netSalesPct` trends the NET-of-returns figure (O-28, owner ruling 2026-08-21:
     * the Today's-Sales headline is net, excluding refunds). The headline tile must
     * carry the trend of the number it displays — a net figure badged with a
     * gross-derived percentage is precisely the blend the ruling forbids.
     *
     * There is deliberately NO `netSalesAbs` companion to `grossSalesAbs`: nothing
     * renders an absolute delta (`StatCard`'s trend contract is a percentage plus a
     * label), so shipping one would be dead payload. `grossSalesAbs` is equally
     * unread but predates this lane and is already published, so removing it is a
     * contract change for the OpenAPI lane rather than a side effect of O-28.
     */
    public function __construct(
        public readonly string $grossSalesAbs,
        public readonly ?string $grossSalesPct,
        public readonly ?string $netSalesPct,
        public readonly int $salesCountAbs,
        public readonly ?string $salesCountPct,
    ) {}
}
