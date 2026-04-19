<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * AgedReceivablesData
 *
 * Data Transfer Object for a complete aged receivables report.
 *
 * Shows outstanding customer balances grouped by aging buckets with totals.
 */
#[TypeScript]
final class AgedReceivablesData extends Data
{
    /**
     * @param  string  $as_of_date  The snapshot date for the aging report (YYYY-MM-DD)
     * @param  DataCollection<int, AgedReceivablesLineData>|array<int, AgedReceivablesLineData>  $lines  Customer aging lines
     * @param  string  $total_current  Total of all current (0-30 days) balances
     * @param  string  $total_days_30  Total of all 31-60 days balances
     * @param  string  $total_days_60  Total of all 61-90 days balances
     * @param  string  $total_days_90  Total of all 91-120 days balances
     * @param  string  $total_over_90  Total of all over 120 days balances
     * @param  string  $grand_total  Grand total of all outstanding receivables
     */
    public function __construct(
        public readonly string $as_of_date,
        #[DataCollectionOf(AgedReceivablesLineData::class)]
        public readonly DataCollection|array $lines,
        public readonly string $total_current,
        public readonly string $total_days_30,
        public readonly string $total_days_60,
        public readonly string $total_days_90,
        public readonly string $total_over_90,
        public readonly string $grand_total,
    ) {}
}
