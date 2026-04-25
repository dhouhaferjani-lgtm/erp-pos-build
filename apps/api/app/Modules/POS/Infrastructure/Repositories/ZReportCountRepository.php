<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Repositories;

use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\ZReportCount;

/**
 * Persists per-tender cash-count rows for a Z report.
 *
 * Owned by the POS module. Used by ReportGenerationService when a Z report is generated
 * with cash-count inputs (Task 18 / PR-2 of the cash-counting cluster).
 */
final class ZReportCountRepository
{
    /**
     * Persist all per-tender breakdowns for a Z report in a single batch.
     *
     * The unique index `pos_z_report_counts_unique_method_per_z` (z_report_id, payment_method_id)
     * enforces one row per tender per Z. The CHECK constraints in PostgreSQL enforce that
     * variance_amount = actual_amount - expected_amount and that the direction matches the sign.
     *
     * @param  array<int, CashCountBreakdownDTO>  $breakdowns
     */
    public function createMany(string $zReportId, array $breakdowns): void
    {
        foreach ($breakdowns as $breakdown) {
            ZReportCount::create([
                'z_report_id' => $zReportId,
                'payment_method_id' => $breakdown->paymentMethodId,
                'currency_code' => $breakdown->currencyCode,
                'expected_amount' => $breakdown->expectedAmount,
                'actual_amount' => $breakdown->actualAmount,
                'variance_amount' => $breakdown->varianceAmount,
                'variance_direction' => $breakdown->varianceDirection->value,
                'transaction_count' => $breakdown->transactionCount,
            ]);
        }
    }
}
