<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\AgedReceivablesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Financial Reports Controller
 *
 * Handles generation of various financial reports:
 * - Aged receivables
 * - Customer statements
 * - Overdue invoices summary
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AgedReceivablesService $agedReceivablesService,
    ) {}

    /**
     * Generate aged receivables report
     *
     * GET /api/v1/reports/aged-receivables
     *
     * Query parameters:
     * - partner_id (optional): Filter by specific partner
     * - as_of_date (optional): Calculate aging as of this date (default: today)
     */
    public function agedReceivables(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $partnerId = $request->query('partner_id');
        if ($partnerId !== null && ! is_string($partnerId)) {
            return response()->json([
                'error' => 'Invalid partner_id parameter',
            ], 400);
        }

        $asOfDate = $request->query('as_of_date');
        if ($asOfDate !== null && ! is_string($asOfDate)) {
            return response()->json([
                'error' => 'Invalid as_of_date parameter',
            ], 400);
        }

        // Validate date format if provided
        if ($asOfDate !== null) {
            try {
                new \DateTime($asOfDate);
            } catch (\Exception $e) {
                return response()->json([
                    'error' => 'Invalid date format. Use YYYY-MM-DD',
                ], 400);
            }
        }

        $report = $this->agedReceivablesService->generateReport(
            companyId: $companyId,
            partnerId: $partnerId,
            asOfDate: $asOfDate
        );

        return response()->json([
            'data' => $report,
        ]);
    }

    /**
     * Generate customer statement
     *
     * GET /api/v1/reports/customer-statement/{partnerId}
     *
     * Query parameters:
     * - from_date (required): Start date (YYYY-MM-DD)
     * - to_date (required): End date (YYYY-MM-DD)
     */
    public function customerStatement(Request $request, string $partnerId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $fromDate = $request->query('from_date');
        $toDate = $request->query('to_date');

        if (! is_string($fromDate) || ! is_string($toDate)) {
            return response()->json([
                'error' => 'Both from_date and to_date are required',
            ], 400);
        }

        // Validate date formats
        try {
            new \DateTime($fromDate);
            new \DateTime($toDate);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Invalid date format. Use YYYY-MM-DD',
            ], 400);
        }

        // Validate date range
        if ($fromDate > $toDate) {
            return response()->json([
                'error' => 'from_date must be before or equal to to_date',
            ], 400);
        }

        try {
            $statement = $this->agedReceivablesService->generateCustomerStatement(
                companyId: $companyId,
                partnerId: $partnerId,
                fromDate: $fromDate,
                toDate: $toDate
            );

            return response()->json([
                'data' => $statement,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Partner not found',
            ], 404);
        }
    }

    /**
     * Get overdue invoices summary
     *
     * GET /api/v1/reports/overdue-summary
     */
    public function overdueSummary(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $summary = $this->agedReceivablesService->getOverdueSummary($companyId);

        return response()->json([
            'data' => $summary,
        ]);
    }
}
