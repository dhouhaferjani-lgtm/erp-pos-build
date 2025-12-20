<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\DTOs\Reports\AgedPayablesData;
use App\Modules\Accounting\Application\DTOs\Reports\AgedReceivablesData;
use App\Modules\Accounting\Application\DTOs\Reports\BalanceSheetData;
use App\Modules\Accounting\Application\DTOs\Reports\ProfitLossData;
use App\Modules\Accounting\Application\DTOs\Reports\TrialBalanceData;
use App\Modules\Accounting\Application\Services\FiscalPeriodResolverService;
use App\Modules\Accounting\Application\Services\Reports\AgedPayablesService;
use App\Modules\Accounting\Application\Services\Reports\AgedReceivablesService;
use App\Modules\Accounting\Application\Services\Reports\BalanceSheetService;
use App\Modules\Accounting\Application\Services\Reports\ProfitLossService;
use App\Modules\Accounting\Application\Services\Reports\TrialBalanceService;
use App\Modules\Accounting\Presentation\Requests\GetAgedPayablesRequest;
use App\Modules\Accounting\Presentation\Requests\GetAgedReceivablesRequest;
use App\Modules\Accounting\Presentation\Requests\GetBalanceSheetRequest;
use App\Modules\Accounting\Presentation\Requests\GetProfitLossRequest;
use App\Modules\Accounting\Presentation\Requests\GetTrialBalanceRequest;
use App\Modules\Company\Services\CompanyContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * ReportsController
 *
 * Handles HTTP requests for financial reports in the Accounting module.
 *
 * This controller provides endpoints for generating various accounting reports:
 * - Trial Balance: Verify general ledger integrity (debits = credits)
 * - Profit & Loss: Revenue vs expenses (future)
 * - Balance Sheet: Assets = Liabilities + Equity (future)
 *
 * Controller Responsibilities:
 * - Validate HTTP request parameters
 * - Extract company context
 * - Resolve date ranges from fiscal periods
 * - Call application services to generate reports
 * - Transform service output to DTOs
 * - Return JSON responses
 *
 * Architectural Notes:
 * - Presentation layer (thin controller pattern)
 * - No business logic (delegates to services)
 * - Uses dependency injection for all services
 * - Returns Spatie Data DTOs (auto-serialized to JSON)
 *
 * Authorization:
 * - Handled by middleware (auth:sanctum)
 * - Route-level permission checks (can:reports.view)
 * - Company context validated by CompanyContext service
 *
 * Error Handling:
 * - Laravel handles validation errors (422 responses)
 * - ModelNotFoundException for missing fiscal periods
 * - General exceptions logged and returned as 500
 *
 * @package App\Modules\Accounting\Presentation\Controllers
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly FiscalPeriodResolverService $periodResolver,
        private readonly TrialBalanceService $trialBalanceService,
        private readonly ProfitLossService $profitLossService,
        private readonly BalanceSheetService $balanceSheetService,
        private readonly AgedReceivablesService $agedReceivablesService,
        private readonly AgedPayablesService $agedPayablesService,
    ) {}

    /**
     * Generate a Trial Balance report.
     *
     * The Trial Balance shows all accounts with their debit/credit balances,
     * proving that the general ledger is in balance (debits = credits).
     *
     * Query Parameters:
     * - as_of_date: Point-in-time date (YYYY-MM-DD format, defaults to today)
     * - fiscal_period_id: Alternative to as_of_date, uses period end date
     * - include_zero_balances: Boolean, whether to include accounts with zero balance (default: false)
     * - include_hierarchy: Boolean, whether to build hierarchical structure (default: true)
     *
     * Response Format:
     * ```json
     * {
     *   "data": {
     *     "lines": [
     *       {
     *         "account_code": "100",
     *         "account_name": "Assets",
     *         "account_type": "asset",
     *         "debit": "10000.00",
     *         "credit": "0.00",
     *         "level": 0,
     *         "is_parent": true
     *       }
     *     ],
     *     "total_debit": "10000.00",
     *     "total_credit": "10000.00",
     *     "is_balanced": true,
     *     "as_of_date": "2025-12-31"
     *   }
     * }
     * ```
     *
     * Error Responses:
     * - 422: Validation error (invalid parameters)
     * - 404: Fiscal period not found
     * - 500: Internal server error
     *
     * @param GetTrialBalanceRequest $request Validated request
     * @return JsonResponse
     *
     * @example
     * GET /api/v1/reports/trial-balance
     * GET /api/v1/reports/trial-balance?as_of_date=2025-12-31
     * GET /api/v1/reports/trial-balance?fiscal_period_id={uuid}
     * GET /api/v1/reports/trial-balance?include_zero_balances=true
     * GET /api/v1/reports/trial-balance?include_hierarchy=false
     */
    public function trialBalance(GetTrialBalanceRequest $request): JsonResponse
    {
        try {
            // Step 1: Get company context
            // This may throw RuntimeException if company context is missing
            $companyId = $this->companyContext->requireCompanyId();
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'COMPANY_CONTEXT_REQUIRED',
                    'message' => $e->getMessage(),
                ],
            ], 401);
        }

        try {
            // Step 2: Resolve date from parameters
            $asOfDate = $this->resolveDateForTrialBalance($request, $companyId);

            // Step 3: Get optional parameters
            $includeZeroBalances = $request->boolean('include_zero_balances', false);
            $includeHierarchy = $request->boolean('include_hierarchy', false); // TODO: Fix hierarchy balance calculation

            // Step 4: Generate report via service
            $reportData = $this->trialBalanceService->generate(
                companyId: $companyId,
                asOfDate: $asOfDate,
                includeZeroBalances: $includeZeroBalances,
                includeHierarchy: $includeHierarchy
            );

            // Step 5: Transform to DTO
            $dto = TrialBalanceData::fromArray($reportData);

            // Step 6: Return JSON response
            // Spatie Data automatically serializes to JSON
            return response()->json([
                'data' => $dto,
            ]);
        } catch (ModelNotFoundException $e) {
            // Fiscal period not found
            return response()->json([
                'error' => [
                    'code' => 'FISCAL_PERIOD_NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], 404);
        } catch (\Exception $e) {
            // Log unexpected errors
            \Log::error('Trial Balance generation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'REPORT_GENERATION_FAILED',
                    'message' => 'Failed to generate trial balance report. Please try again or contact support.',
                ],
            ], 500);
        }
    }

    /**
     * Resolve the "as of date" for trial balance report.
     *
     * Priority order:
     * 1. fiscal_period_id → use period's end_date
     * 2. as_of_date parameter → use specified date
     * 3. Default → today
     *
     * @param GetTrialBalanceRequest $request
     * @param string $companyId
     * @return Carbon
     * @throws ModelNotFoundException If fiscal period not found
     */
    private function resolveDateForTrialBalance(GetTrialBalanceRequest $request, string $companyId): Carbon
    {
        // Priority 1: Fiscal period ID
        $fiscalPeriodId = $request->input('fiscal_period_id');
        if ($fiscalPeriodId !== null) {
            $resolved = $this->periodResolver->resolvePeriodDates($companyId, $fiscalPeriodId);

            return $resolved['end_date'];
        }

        // Priority 2: Explicit as_of_date parameter
        $asOfDateStr = $request->input('as_of_date');
        if ($asOfDateStr !== null) {
            return Carbon::parse($asOfDateStr)->endOfDay();
        }

        // Priority 3: Default to today
        return Carbon::now()->endOfDay();
    }

    /**
     * Generate a Profit & Loss (Income Statement) report.
     *
     * The Profit & Loss shows revenue and expense accounts for a period,
     * calculating the company's net income or loss.
     *
     * Query Parameters:
     * - date_from: Required start date (YYYY-MM-DD format)
     * - date_to: Required end date (YYYY-MM-DD format)
     * - fiscal_period_id: Alternative to date range, uses period dates
     * - include_zero_balances: Boolean, whether to include accounts with zero balance (default: false)
     * - include_hierarchy: Boolean, whether to build hierarchical structure (default: true)
     *
     * Response Format:
     * ```json
     * {
     *   "data": {
     *     "revenue": [
     *       {
     *         "account_code": "400",
     *         "account_name": "Sales Revenue",
     *         "account_type": "revenue",
     *         "amount": "50000.0000",
     *         "level": 0,
     *         "is_parent": false
     *       }
     *     ],
     *     "expenses": [
     *       {
     *         "account_code": "600",
     *         "account_name": "Rent Expense",
     *         "account_type": "expense",
     *         "amount": "5000.0000",
     *         "level": 0,
     *         "is_parent": false
     *       }
     *     ],
     *     "total_revenue": "50000.0000",
     *     "total_expenses": "5000.0000",
     *     "net_income": "45000.0000",
     *     "date_from": "2025-01-01",
     *     "date_to": "2025-12-31"
     *   }
     * }
     * ```
     *
     * Error Responses:
     * - 401: Company context missing or invalid
     * - 422: Validation error (invalid parameters)
     * - 404: Fiscal period not found
     * - 500: Internal server error
     *
     * @param GetProfitLossRequest $request Validated request
     * @return JsonResponse
     *
     * @example
     * GET /api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31
     * GET /api/v1/reports/profit-loss?fiscal_period_id={uuid}
     * GET /api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31&include_zero_balances=true
     */
    public function profitLoss(GetProfitLossRequest $request): JsonResponse
    {
        try {
            // Step 1: Get company context
            // This may throw RuntimeException if company context is missing
            $companyId = $this->companyContext->requireCompanyId();
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'COMPANY_CONTEXT_REQUIRED',
                    'message' => $e->getMessage(),
                ],
            ], 401);
        }

        try {
            // Step 2: Resolve date range from parameters
            $dateRange = $this->resolveDateRangeForProfitLoss($request, $companyId);
            $dateFrom = $dateRange['start'];
            $dateTo = $dateRange['end'];

            // Step 3: Get optional parameters
            $includeZeroBalances = $request->boolean('include_zero_balances', false);
            $includeHierarchy = $request->boolean('include_hierarchy', false); // TODO: Fix hierarchy balance calculation

            // Step 4: Generate report via service
            $reportData = $this->profitLossService->generate(
                companyId: $companyId,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                includeZeroBalances: $includeZeroBalances,
                includeHierarchy: $includeHierarchy
            );

            // Step 5: Transform to DTO
            $dto = ProfitLossData::fromArray($reportData);

            // Step 6: Return JSON response
            // Spatie Data automatically serializes to JSON
            return response()->json([
                'data' => $dto,
            ]);
        } catch (ModelNotFoundException $e) {
            // Fiscal period not found
            return response()->json([
                'error' => [
                    'code' => 'FISCAL_PERIOD_NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], 404);
        } catch (\Exception $e) {
            // Log unexpected errors
            \Log::error('Profit & Loss generation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'REPORT_GENERATION_FAILED',
                    'message' => 'Failed to generate profit & loss report. Please try again or contact support.',
                ],
            ], 500);
        }
    }

    /**
     * Resolve the date range for profit & loss report.
     *
     * Priority order:
     * 1. fiscal_period_id → use period's start_date and end_date
     * 2. date_from + date_to parameters → use specified dates
     *
     * @param GetProfitLossRequest $request
     * @param string $companyId
     * @return array{start: Carbon, end: Carbon}
     * @throws ModelNotFoundException If fiscal period not found
     */
    private function resolveDateRangeForProfitLoss(GetProfitLossRequest $request, string $companyId): array
    {
        // Priority 1: Fiscal period ID
        $fiscalPeriodId = $request->input('fiscal_period_id');
        if ($fiscalPeriodId !== null) {
            $resolved = $this->periodResolver->resolvePeriodDates($companyId, $fiscalPeriodId);

            return [
                'start' => $resolved['start_date'],
                'end' => $resolved['end_date'],
            ];
        }

        // Priority 2: Explicit date_from and date_to parameters (both required)
        $dateFrom = Carbon::parse($request->input('date_from'))->startOfDay();
        $dateTo = Carbon::parse($request->input('date_to'))->endOfDay();

        return [
            'start' => $dateFrom,
            'end' => $dateTo,
        ];
    }

    /**
     * Generate a Balance Sheet (Statement of Financial Position) report.
     *
     * The Balance Sheet shows assets, liabilities, and equity accounts at a specific
     * point in time, verifying the fundamental accounting equation: Assets = Liabilities + Equity.
     *
     * Query Parameters:
     * - as_of_date: Point-in-time date (YYYY-MM-DD format, defaults to today)
     * - fiscal_period_id: Alternative to as_of_date, uses period end date
     * - include_zero_balances: Boolean, whether to include accounts with zero balance (default: false)
     * - include_hierarchy: Boolean, whether to build hierarchical structure (default: true)
     *
     * Response Format:
     * ```json
     * {
     *   "data": {
     *     "assets": [
     *       {
     *         "account_code": "100",
     *         "account_name": "Cash",
     *         "account_type": "asset",
     *         "amount": "10000.0000",
     *         "level": 1,
     *         "is_parent": false
     *       }
     *     ],
     *     "liabilities": [...],
     *     "equity": [...],
     *     "total_assets": "75000.0000",
     *     "total_liabilities": "8000.0000",
     *     "total_equity": "67000.0000",
     *     "retained_earnings": "17000.0000",
     *     "is_balanced": true,
     *     "as_of_date": "2025-12-31"
     *   }
     * }
     * ```
     *
     * Error Responses:
     * - 401: Company context missing or invalid
     * - 422: Validation error (invalid parameters)
     * - 404: Fiscal period not found
     * - 500: Internal server error
     *
     * @param GetBalanceSheetRequest $request Validated request
     * @return JsonResponse
     *
     * @example
     * GET /api/v1/reports/balance-sheet
     * GET /api/v1/reports/balance-sheet?as_of_date=2025-12-31
     * GET /api/v1/reports/balance-sheet?fiscal_period_id={uuid}
     * GET /api/v1/reports/balance-sheet?include_zero_balances=true
     */
    public function balanceSheet(GetBalanceSheetRequest $request): JsonResponse
    {
        try {
            // Step 1: Get company context
            // This may throw RuntimeException if company context is missing
            $companyId = $this->companyContext->requireCompanyId();
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'COMPANY_CONTEXT_REQUIRED',
                    'message' => $e->getMessage(),
                ],
            ], 401);
        }

        try {
            // Step 2: Resolve date from parameters
            $asOfDate = $this->resolveDateForBalanceSheet($request, $companyId);

            // Step 3: Get optional parameters
            $includeZeroBalances = $request->boolean('include_zero_balances', false);
            $includeHierarchy = $request->boolean('include_hierarchy', false); // TODO: Fix hierarchy balance calculation

            // Step 4: Generate report via service
            $reportData = $this->balanceSheetService->generate(
                companyId: $companyId,
                asOfDate: $asOfDate,
                includeZeroBalances: $includeZeroBalances,
                includeHierarchy: $includeHierarchy
            );

            // Step 5: Transform to DTO
            $dto = BalanceSheetData::fromArray($reportData);

            // Step 6: Return JSON response
            // Spatie Data automatically serializes to JSON
            return response()->json([
                'data' => $dto,
            ]);
        } catch (ModelNotFoundException $e) {
            // Fiscal period not found
            return response()->json([
                'error' => [
                    'code' => 'FISCAL_PERIOD_NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], 404);
        } catch (\Exception $e) {
            // Log unexpected errors
            \Log::error('Balance Sheet generation failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'REPORT_GENERATION_FAILED',
                    'message' => 'Failed to generate balance sheet report. Please try again or contact support.',
                ],
            ], 500);
        }
    }

    /**
     * Resolve the "as of date" for balance sheet report.
     *
     * Priority order:
     * 1. fiscal_period_id → use period's end_date
     * 2. as_of_date parameter → use specified date
     * 3. Default → today
     *
     * @param GetBalanceSheetRequest $request
     * @param string $companyId
     * @return Carbon
     * @throws ModelNotFoundException If fiscal period not found
     */
    private function resolveDateForBalanceSheet(GetBalanceSheetRequest $request, string $companyId): Carbon
    {
        // Priority 1: Fiscal period ID
        $fiscalPeriodId = $request->input('fiscal_period_id');
        if ($fiscalPeriodId !== null) {
            $resolved = $this->periodResolver->resolvePeriodDates($companyId, $fiscalPeriodId);

            return $resolved['end_date'];
        }

        // Priority 2: Explicit as_of_date parameter
        $asOfDateStr = $request->input('as_of_date');
        if ($asOfDateStr !== null) {
            return Carbon::parse($asOfDateStr)->endOfDay();
        }

        // Priority 3: Default to today
        return Carbon::now()->endOfDay();
    }

    /**
     * Generate an Aged Receivables report.
     *
     * Shows outstanding customer invoices grouped by aging buckets:
     * - Current (0-30 days)
     * - 31-60 days
     * - 61-90 days
     * - 91-120 days
     * - Over 120 days
     *
     * Query Parameters:
     * - as_of_date: Snapshot date (YYYY-MM-DD format, defaults to today)
     *
     * @param GetAgedReceivablesRequest $request Validated request
     * @return JsonResponse
     */
    public function agedReceivables(GetAgedReceivablesRequest $request): JsonResponse
    {
        try {
            $companyId = $this->companyContext->requireCompanyId();
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'COMPANY_CONTEXT_REQUIRED',
                    'message' => $e->getMessage(),
                ],
            ], 401);
        }

        try {
            $asOfDate = $request->input('as_of_date')
                ? Carbon::parse($request->input('as_of_date'))
                : Carbon::today();

            $reportData = $this->agedReceivablesService->generate($companyId, $asOfDate);

            return response()->json([
                'data' => $reportData->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'REPORT_GENERATION_ERROR',
                    'message' => 'Failed to generate aged receivables report: '.$e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Generate an Aged Payables report.
     *
     * Shows outstanding supplier invoices grouped by aging buckets:
     * - Current (0-30 days)
     * - 31-60 days
     * - 61-90 days
     * - 91-120 days
     * - Over 120 days
     *
     * Query Parameters:
     * - as_of_date: Snapshot date (YYYY-MM-DD format, defaults to today)
     *
     * @param GetAgedPayablesRequest $request Validated request
     * @return JsonResponse
     */
    public function agedPayables(GetAgedPayablesRequest $request): JsonResponse
    {
        try {
            $companyId = $this->companyContext->requireCompanyId();
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'COMPANY_CONTEXT_REQUIRED',
                    'message' => $e->getMessage(),
                ],
            ], 401);
        }

        try {
            $asOfDate = $request->input('as_of_date')
                ? Carbon::parse($request->input('as_of_date'))
                : Carbon::today();

            $reportData = $this->agedPayablesService->generate($companyId, $asOfDate);

            return response()->json([
                'data' => $reportData->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'REPORT_GENERATION_ERROR',
                    'message' => 'Failed to generate aged payables report: '.$e->getMessage(),
                ],
            ], 500);
        }
    }
}
