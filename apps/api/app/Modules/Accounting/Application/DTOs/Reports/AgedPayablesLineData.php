<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;

/**
 * AgedPayablesLineData
 *
 * Data Transfer Object for a single vendor line in an aged payables report.
 *
 * Represents outstanding payables for one vendor broken down by aging buckets.
 */
final class AgedPayablesLineData extends Data
{
    /**
     * @param  string  $vendor_id  UUID of the vendor (partner)
     * @param  string  $vendor_name  Name of the vendor
     * @param  string  $current  Outstanding amount 0-30 days old (decimal string with 4 decimals)
     * @param  string  $days_30  Outstanding amount 31-60 days old (decimal string with 4 decimals)
     * @param  string  $days_60  Outstanding amount 61-90 days old (decimal string with 4 decimals)
     * @param  string  $days_90  Outstanding amount 91-120 days old (decimal string with 4 decimals)
     * @param  string  $over_90  Outstanding amount over 120 days old (decimal string with 4 decimals)
     * @param  string  $total  Total outstanding for this vendor (decimal string with 4 decimals)
     */
    public function __construct(
        public readonly string $vendor_id,
        public readonly string $vendor_name,
        public readonly string $current,
        public readonly string $days_30,
        public readonly string $days_60,
        public readonly string $days_90,
        public readonly string $over_90,
        public readonly string $total,
    ) {}
}
