<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for validating and repairing the balance_due cache.
 *
 * The balance_due column is a cached value maintained by PostgreSQL trigger.
 * This service compares the cached value against the computed outstanding amount
 * to detect and repair any inconsistencies.
 */
final class DocumentCacheValidationService
{
    /**
     * Find documents where cached balance_due differs from computed outstanding.
     *
     * @param  string  $companyId  Company ID to check
     * @param  float  $tolerance  Tolerance for floating-point comparison (default 0.01)
     * @return Collection<int, object{id: string, document_number: string, cached_balance: string, computed_balance: string, difference: string}>
     */
    public function findInconsistencies(string $companyId, float $tolerance = 0.01): Collection
    {
        // Note: Using subquery approach for better SQLite compatibility
        $results = DB::select("
            SELECT * FROM (
                SELECT
                    d.id,
                    d.document_number,
                    d.balance_due as cached_balance,
                    (
                        COALESCE(d.total, 0) -
                        COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE document_id = d.id), 0) -
                        COALESCE((SELECT SUM(amount) FROM credit_note_allocations WHERE invoice_id = d.id), 0)
                    ) as computed_balance,
                    ABS(d.balance_due - (
                        COALESCE(d.total, 0) -
                        COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE document_id = d.id), 0) -
                        COALESCE((SELECT SUM(amount) FROM credit_note_allocations WHERE invoice_id = d.id), 0)
                    )) as difference
                FROM documents d
                WHERE d.company_id = ?
                  AND d.type = 'invoice'
                  AND d.status = 'posted'
                  AND d.deleted_at IS NULL
            ) AS calc
            WHERE calc.difference > ?
            ORDER BY calc.difference DESC
        ", [$companyId, $tolerance]);

        return collect($results)->map(static function (object $row): object {
            $row->cached_balance = CurrencyScale::bcformat($row->cached_balance, 2);
            $row->computed_balance = CurrencyScale::bcformat($row->computed_balance, 2);
            $row->difference = CurrencyScale::bcformat($row->difference, 2);

            return $row;
        });
    }

    /**
     * Repair inconsistent cache values for a company.
     *
     * @param  string  $companyId  Company ID to repair
     * @return int Number of documents repaired
     */
    public function repairCache(string $companyId): int
    {
        return DB::update("
            UPDATE documents
            SET balance_due = (
                COALESCE(total, 0) -
                COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE document_id = documents.id), 0) -
                COALESCE((SELECT SUM(amount) FROM credit_note_allocations WHERE invoice_id = documents.id), 0)
            ),
            updated_at = NOW()
            WHERE company_id = ?
              AND type = 'invoice'
              AND status = 'posted'
              AND deleted_at IS NULL
        ", [$companyId]);
    }

    /**
     * Get cache accuracy report for a company.
     *
     * @param  string  $companyId  Company ID to check
     * @return array{total_invoices: int, accurate_invoices: int, inconsistent_invoices: int, accuracy_percentage: float, max_difference: string}
     */
    public function getCacheAccuracyReport(string $companyId): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_invoices,
                COUNT(*) FILTER (WHERE ABS(d.balance_due - (
                    COALESCE(d.total, 0) -
                    COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE document_id = d.id), 0) -
                    COALESCE((SELECT SUM(amount) FROM credit_note_allocations WHERE invoice_id = d.id), 0)
                )) <= 0.01) as accurate_invoices,
                COUNT(*) FILTER (WHERE ABS(d.balance_due - (
                    COALESCE(d.total, 0) -
                    COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE document_id = d.id), 0) -
                    COALESCE((SELECT SUM(amount) FROM credit_note_allocations WHERE invoice_id = d.id), 0)
                )) > 0.01) as inconsistent_invoices,
                MAX(ABS(d.balance_due - (
                    COALESCE(d.total, 0) -
                    COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE document_id = d.id), 0) -
                    COALESCE((SELECT SUM(amount) FROM credit_note_allocations WHERE invoice_id = d.id), 0)
                ))) as max_difference
            FROM documents d
            WHERE d.company_id = ?
              AND d.type = 'invoice'
              AND d.status = 'posted'
              AND d.deleted_at IS NULL
        ", [$companyId]);

        $totalInvoices = (int) $stats->total_invoices;
        $accurateInvoices = (int) $stats->accurate_invoices;
        $inconsistentInvoices = (int) $stats->inconsistent_invoices;
        $maxDifference = CurrencyScale::bcformat($stats->max_difference ?? 0, 2);

        $accuracyPercentage = $totalInvoices > 0
            ? ($accurateInvoices / $totalInvoices) * 100
            : 100.0;

        return [
            'total_invoices' => $totalInvoices,
            'accurate_invoices' => $accurateInvoices,
            'inconsistent_invoices' => $inconsistentInvoices,
            'accuracy_percentage' => round($accuracyPercentage, 2),
            'max_difference' => $maxDifference,
        ];
    }

    /**
     * Validate cache for all companies.
     *
     * @return Collection<int, array{company_id: string, total_invoices: int, accurate_invoices: int, inconsistent_invoices: int, accuracy_percentage: float, max_difference: string}>
     */
    public function validateAllCompanies(): Collection
    {
        $companies = DB::table('companies')->pluck('id');

        return $companies->map(function (string $companyId) {
            $report = $this->getCacheAccuracyReport($companyId);

            return [
                'company_id' => $companyId,
                ...$report,
            ];
        });
    }
}
