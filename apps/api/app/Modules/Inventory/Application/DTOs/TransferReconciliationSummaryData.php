<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Transfer-level totals. Quantities are 4-dp strings; freight is at transfer_cost's stored scale. */
#[TypeScript]
final class TransferReconciliationSummaryData extends Data
{
    public function __construct(
        public int $lines,
        public int $lines_with_discrepancy,
        public string $total_sent,
        public string $total_received,
        public string $total_damaged,
        public string $total_written_off,
        public string $total_returned,
        public string $total_remaining,
        public string $freight_uncapitalized,
    ) {}
}
