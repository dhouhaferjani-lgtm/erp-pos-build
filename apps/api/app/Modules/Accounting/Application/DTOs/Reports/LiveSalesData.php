<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class LiveSalesData extends Data
{
    /**
     * @param  list<LiveSaleReceiptData>  $recent_receipts
     * @param  array<string, int>  $open_shifts_by_location
     */
    public function __construct(
        public readonly array $recent_receipts,
        #[LiteralTypeScriptType('Record<string, number>')]
        public readonly array $open_shifts_by_location,
        public readonly string $generated_at,
    ) {}
}
