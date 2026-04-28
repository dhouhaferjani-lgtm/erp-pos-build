<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * AgedReceivablesLineData
 *
 * Data Transfer Object for a single customer line in an aged receivables report.
 *
 * Represents outstanding receivables for one customer broken down by aging buckets.
 */
#[TypeScript]
final class AgedReceivablesLineData extends Data
{
    /**
     * @param  string  $customer_id  UUID of the customer (partner)
     * @param  string  $customer_name  Name of the customer
     * @param  string  $current  Outstanding amount 0-30 days old (decimal string with 4 decimals)
     * @param  string  $days_30  Outstanding amount 31-60 days old (decimal string with 4 decimals)
     * @param  string  $days_60  Outstanding amount 61-90 days old (decimal string with 4 decimals)
     * @param  string  $days_90  Outstanding amount 91-120 days old (decimal string with 4 decimals)
     * @param  string  $over_90  Outstanding amount over 120 days old (decimal string with 4 decimals)
     * @param  string  $total  Total outstanding for this customer (decimal string with 4 decimals)
     */
    public function __construct(
        public readonly string $customer_id,
        public readonly string $customer_name,
        public readonly string $current,
        public readonly string $days_30,
        public readonly string $days_60,
        public readonly string $days_90,
        public readonly string $over_90,
        public readonly string $total,
    ) {}
}
