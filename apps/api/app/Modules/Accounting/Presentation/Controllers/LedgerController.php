<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\DTOs\Reports\LedgerData;
use App\Modules\Accounting\Application\Services\Reports\GeneralLedgerReportService;
use App\Modules\Accounting\Presentation\Requests\GetLedgerRequest;
use App\Modules\Company\Services\CompanyContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * LedgerController
 *
 * Handles HTTP requests for General Ledger (GL) queries in the Accounting module.
 *
 * The General Ledger provides a detailed, chronological view of all accounting
 * transactions for specific accounts over a period. Unlike aggregated reports
 * (Trial Balance, P&L), the GL shows individual journal entries with running
 * balances.
 *
 * Controller Responsibilities:
 * - Validate HTTP request parameters (account, date range, partner)
 * - Extract company context for multi-tenancy
 * - Call GeneralLedgerReportService to generate detailed transaction list
 * - Transform service output to DTOs
 * - Apply pagination (50 transactions per page by default)
 * - Return JSON responses
 *
 * Use Cases:
 * - Account reconciliation (e.g., bank account matching)
 * - Transaction history lookup
 * - Audit trail verification
 * - Customer/supplier account statements (with partner_id filter)
 * - Drill-down from Trial Balance to transaction detail
 *
 * Architectural Notes:
 * - Presentation layer (thin controller pattern)
 * - No business logic (delegates to GeneralLedgerReportService)
 * - Uses dependency injection for all services
 * - Returns Spatie Data DTOs (auto-serialized to JSON)
 *
 * Authorization:
 * - Handled by middleware (auth:sanctum)
 * - Route-level permission checks (can:ledger.view)
 * - Company context validated by CompanyContext service
 *
 * Error Handling:
 * - Laravel handles validation errors (422 responses)
 * - Company context errors (401 responses)
 * - General exceptions logged and returned as 500
 *
 * Performance Notes:
 * - Pagination is CRITICAL for large ledgers (100K+ transactions)
 * - Default page size: 50 transactions
 * - Frontend should implement infinite scroll or traditional pagination
 * - Consider caching for historical periods (closed fiscal periods)
 *
 * @package App\Modules\Accounting\Presentation\Controllers
 */
class LedgerController extends Controller
{
    /**
     * Default number of transactions per page.
     *
     * This balances between:
     * - Reducing API calls (larger page size)
     * - Faster response times (smaller page size)
     * - Memory usage on both server and client
     *
     * 50 is a reasonable default for typical accounting workflows.
     */
    private const DEFAULT_PAGE_SIZE = 50;

    /**
     * Maximum allowed page size to prevent memory exhaustion.
     *
     * Clients cannot request more than this many transactions in one call.
     */
    private const MAX_PAGE_SIZE = 200;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly GeneralLedgerReportService $ledgerService,
    ) {}

    /**
     * Generate a General Ledger report.
     *
     * Returns a paginated list of journal entries with running balances for
     * the specified account(s) and date range.
     *
     * Query Parameters:
     * - account_id: Optional account UUID to filter transactions (default: all accounts)
     * - date_from: Optional start date in YYYY-MM-DD format (default: beginning of time)
     * - date_to: Optional end date in YYYY-MM-DD format (default: today)
     * - partner_id: Optional partner UUID for subledger filtering (default: all partners)
     * - page: Page number for pagination (default: 1)
     * - per_page: Items per page (default: 50, max: 200)
     *
     * Response Format:
     * ```json
     * {
     *   "data": {
     *     "opening_balance": "1000.0000",
     *     "closing_balance": "1500.0000",
     *     "total_debits": "800.0000",
     *     "total_credits": "300.0000",
     *     "lines": [
     *       {
     *         "id": "uuid",
     *         "date": "2025-12-19",
     *         "entry_number": "JE-2025-001",
     *         "description": "Cash sale",
     *         "account_code": "100",
     *         "account_name": "Cash",
     *         "partner_name": "John Doe",
     *         "debit": "500.0000",
     *         "credit": "0.0000",
     *         "balance": "1500.0000",
     *         "source_type": "invoice",
     *         "source_id": "inv-uuid"
     *       }
     *     ],
     *     "date_from": "2025-01-01",
     *     "date_to": "2025-12-19",
     *     "account_filter": "account-uuid",
     *     "partner_filter": null
     *   },
     *   "meta": {
     *     "current_page": 1,
     *     "per_page": 50,
     *     "total": 150,
     *     "last_page": 3
     *   }
     * }
     * ```
     *
     * Error Responses:
     * - 401: Company context missing or invalid
     * - 422: Validation error (invalid date format, etc.)
     * - 500: Internal server error
     *
     * @param GetLedgerRequest $request Validated request
     * @return JsonResponse
     *
     * @example
     * GET /api/v1/ledger
     * GET /api/v1/ledger?account_id={uuid}
     * GET /api/v1/ledger?date_from=2025-01-01&date_to=2025-12-19
     * GET /api/v1/ledger?partner_id={uuid}&page=2
     * GET /api/v1/ledger?account_id={uuid}&per_page=100
     */
    public function index(GetLedgerRequest $request): JsonResponse
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
            // Step 2: Parse request parameters
            $accountId = $request->input('account_id');
            $partnerId = $request->input('partner_id');
            $dateFrom = $request->input('date_from')
                ? Carbon::parse($request->input('date_from'))
                : null;
            $dateTo = $request->input('date_to')
                ? Carbon::parse($request->input('date_to'))
                : Carbon::now();

            // Step 3: Get pagination parameters
            $page = (int) $request->input('page', 1);
            $perPage = min(
                (int) $request->input('per_page', self::DEFAULT_PAGE_SIZE),
                self::MAX_PAGE_SIZE
            );

            // Calculate offset for database pagination
            $offset = ($page - 1) * $perPage;

            // Step 4: Generate paginated ledger report
            // The service now handles pagination at the database level
            $reportData = $this->ledgerService->generate(
                companyId: $companyId,
                accountId: $accountId,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                partnerId: $partnerId,
                offset: $offset,
                limit: $perPage
            );

            // Step 5: Transform to DTO
            $dto = LedgerData::fromArray($reportData);

            // Step 6: Calculate pagination metadata
            $total = $reportData['total_count'];
            $lastPage = (int) ceil($total / $perPage);
            $currentCount = count($reportData['lines']);

            // Step 7: Return JSON response with pagination metadata
            return response()->json([
                'data' => $dto,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => $offset + $currentCount,
                ],
            ]);
        } catch (\Exception $e) {
            // Log unexpected errors
            \Log::error('General Ledger generation failed', [
                'company_id' => $companyId,
                'account_id' => $accountId ?? null,
                'partner_id' => $partnerId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'LEDGER_GENERATION_FAILED',
                    'message' => 'Failed to generate ledger report. Please try again or contact support.',
                ],
            ], 500);
        }
    }
}
