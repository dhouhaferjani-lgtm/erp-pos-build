<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Exceptions\CashCountValidationException;
use App\Modules\POS\Application\Exceptions\UnauthorizedManagerException;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\POS\Domain\Exceptions\ServerFiscalAuthoringRetiredException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Presentation\Requests\GenerateZReportRequest;
use App\Modules\POS\Presentation\Resources\XReportResource;
use App\Modules\POS\Presentation\Resources\ZReportResource;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Controller for POS reports.
 *
 * Handles:
 * - X Reports: Mid-shift snapshots
 * - Z Reports: End-of-day closings
 * - Report history and verification
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReportGenerationService $reportGenerationService,
        private readonly ZReportHashService $zReportHashService,
        private readonly ReceiptHashService $receiptHashService,
        private readonly LocationScopeResolver $locationScope,
    ) {}

    /**
     * Generate X report (mid-shift snapshot)
     *
     * POST /api/v1/pos/reports/x
     */
    public function generateXReport(Request $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        $request->validate([
            // api.pos-stabilization.{016,017,018} — scope pos_terminals by caller tenant + company.
            'terminal_id' => [
                'required', 'string', 'uuid',
                ScopedExists::tenantAndCompany(
                    'pos_terminals',
                    $this->companyContext->requireCompany()->tenant_id,
                    $this->companyContext->requireCompanyId(),
                ),
            ],
        ]);

        /** @var Terminal $terminal */
        $terminal = Terminal::findOrFail($request->input('terminal_id'));

        // Verify terminal belongs to current company
        if ($terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Terminal does not belong to your company',
                ],
            ], 403);
        }

        try {
            /** @var User $user */
            $user = $request->user();
            $xReport = $this->reportGenerationService->generateXReport(
                $terminal,
                $user
            );

            return response()->json([
                'data' => XReportResource::make($xReport),
            ], 201);
        } catch (ShiftNotOpenException $e) {
            return response()->json([
                'error' => [
                    'code' => 'NO_OPEN_SHIFT',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        } catch (ServerFiscalAuthoringRetiredException $e) {
            return $this->deviceAuthorityRetiredResponse($e);
        }
    }

    /**
     * Generate Z report (end-of-day closing)
     *
     * POST /api/v1/pos/reports/z
     *
     * Accepts an optional cash_counts array. When omitted the legacy path is used
     * (backwards compatible with Cluster D callers).
     */
    public function generateZReport(GenerateZReportRequest $request): JsonResponse
    {
        Gate::authorize('pos.generate_z_report');

        /** @var Terminal $terminal */
        $terminal = Terminal::findOrFail($request->getTerminalId());

        // Verify terminal belongs to current company
        if ($terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Terminal does not belong to your company',
                ],
            ], 403);
        }

        try {
            /** @var User $user */
            $user = $request->user();

            // Build cash-count input DTOs when cash_counts is present (non-empty array).
            $cashCountInputs = null;
            $rawCounts = $request->getCashCountsInput();
            if ($rawCounts !== []) {
                $cashCountInputs = array_map(
                    static fn (array $row): CashCountInputDTO => new CashCountInputDTO(
                        paymentMethodId: $row['payment_method_id'],
                        currencyCode: $row['currency_code'],
                        actualAmount: $row['actual_amount'],
                    ),
                    $rawCounts,
                );
            }

            $zReport = $this->reportGenerationService->generateZReport(
                terminal: $terminal,
                generatedBy: $user,
                cashCountInputs: $cashCountInputs,
                varianceReason: $request->getVarianceReason(),
                managerOverrideBy: $request->getManagerUserId(),
                blindCountUsed: $request->getBlindCountUsed(),
            );

            // Eager-load relations so ZReportResource can render cash-count data.
            $zReport->load('counts.paymentMethod', 'shift.managerOverride');

            return response()->json([
                'data' => ZReportResource::make($zReport),
            ], 201);
        } catch (ShiftNotOpenException $e) {
            return response()->json([
                'error' => [
                    'code' => 'NO_OPEN_SHIFT',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        } catch (CashCountValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->firstCode() ?? 'CASH_COUNT_VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'errors' => array_map(
                        static fn (object $err): array => [
                            'code' => $err->code,
                            'field' => $err->field,
                            'message' => $err->message,
                        ],
                        $e->errors(),
                    ),
                ],
            ], 422);
        } catch (UnauthorizedManagerException $e) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED_MANAGER',
                    'message' => $e->getMessage(),
                ],
            ], 403);
        } catch (ServerFiscalAuthoringRetiredException $e) {
            return $this->deviceAuthorityRetiredResponse($e);
        }
    }

    private function deviceAuthorityRetiredResponse(ServerFiscalAuthoringRetiredException $e): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'Z_SESSION_DEVICE_AUTHORITY_REQUIRED',
                'message' => $e->getMessage(),
            ],
        ], 409);
    }

    /**
     * Get Z report by Z number
     *
     * GET /api/v1/pos/reports/z/{zNumber}
     */
    public function showZReport(string $zNumber, Request $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        $request->validate([
            'terminal_id' => ['required', 'string', 'uuid'],
        ]);

        $zReport = ZReport::where('terminal_id', $request->input('terminal_id'))
            ->where('z_number', $zNumber)
            ->firstOrFail();

        // Verify terminal belongs to current company
        if ($zReport->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Z report does not belong to your company',
                ],
            ], 403);
        }

        return response()->json([
            'data' => ZReportResource::make($zReport),
        ]);
    }

    /**
     * List Z reports for terminal
     *
     * GET /api/v1/pos/reports/z
     */
    public function listZReports(Request $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        $request->validate([
            'terminal_id' => ['nullable', 'string', 'uuid'],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['uuid'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        $requestedLocationIds = array_values(array_filter(
            (array) $request->input('location_ids', []),
            static fn (mixed $id): bool => is_string($id),
        ));
        $locationIds = $this->locationScope->resolve($user, $requestedLocationIds, null);

        $query = ZReport::query()
            ->whereHas('terminal', function (Builder $q) use ($locationIds): void {
                $q->where('pos_terminals.company_id', $this->companyContext->getCompanyId());
                if ($locationIds !== []) {
                    $q->whereIn('pos_terminals.location_id', $locationIds);
                }
            })
            ->when($request->filled('terminal_id'), fn ($q) => $q->where('terminal_id', $request->input('terminal_id')))
            ->with(['terminal.location', 'shift', 'generatedBy']);

        // Filter by date range
        if ($request->filled('from_date')) {
            $query->where('generated_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('generated_at', '<=', $request->input('to_date'));
        }

        $zReports = $query->orderByDesc('generated_at')->orderByDesc('z_number')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'data' => ZReportResource::collection($zReports->items()),
            'meta' => [
                'current_page' => $zReports->currentPage(),
                'last_page' => $zReports->lastPage(),
                'per_page' => $zReports->perPage(),
                'total' => $zReports->total(),
            ],
        ]);
    }

    /**
     * Download Z report as PDF.
     *
     * GET /api/v1/pos/reports/z/{zNumber}/pdf
     */
    public function downloadPdf(string $zNumber, Request $request): Response
    {
        Gate::authorize('pos.view_reports');

        $request->validate([
            'terminal_id' => ['required', 'string', 'uuid'],
        ]);

        // F.2 — defense-in-depth: ZReport itself has no tenant_id /
        // company_id columns; the terminal is the scope anchor. Resolve
        // the terminal via a tenant+company scoped lookup BEFORE
        // querying ZReport so a foreign-tenant terminal_id never even
        // reaches the Z-report table. Returns the same 404 shape across
        // foreign and missing ids so id-enumeration is impossible.
        $company = $this->companyContext->requireCompany();
        $terminalId = (string) $request->input('terminal_id');

        Terminal::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $terminalId)
            ->firstOrFail();

        $zReport = ZReport::query()
            ->where('terminal_id', $terminalId)
            ->where('z_number', $zNumber)
            ->firstOrFail();

        $pdf = $this->reportGenerationService->generatePdf($zReport);
        $filename = $this->reportGenerationService->getZReportFilename($zReport);

        return $pdf->download($filename);
    }

    /**
     * Verify Z report hash chain integrity
     *
     * POST /api/v1/pos/reports/z/verify-chain
     */
    public function verifyZReportChain(Request $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        $request->validate([
            // api.pos-stabilization.{016,017,018} — scope pos_terminals by caller tenant + company.
            'terminal_id' => [
                'required', 'string', 'uuid',
                ScopedExists::tenantAndCompany(
                    'pos_terminals',
                    $this->companyContext->requireCompany()->tenant_id,
                    $this->companyContext->requireCompanyId(),
                ),
            ],
        ]);

        /** @var Terminal $terminal */
        $terminal = Terminal::findOrFail($request->input('terminal_id'));

        // Verify terminal belongs to current company
        if ($terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Terminal does not belong to your company',
                ],
            ], 403);
        }

        $isValid = $this->zReportHashService->verifyZReportChain($terminal);

        $chainLength = ZReport::where('terminal_id', $terminal->id)->count();
        $firstZ = ZReport::where('terminal_id', $terminal->id)->orderBy('z_number')->first();
        $lastZ = ZReport::where('terminal_id', $terminal->id)->orderByDesc('z_number')->first();

        $brokenReport = null;
        if (! $isValid) {
            $brokenReport = $this->zReportHashService->findChainBreak($terminal);
        }

        return response()->json([
            'data' => [
                'is_valid' => $isValid,
                'chain_length' => $chainLength,
                'first_z_number' => $firstZ?->z_number,
                'last_z_number' => $lastZ?->z_number,
                'broken_at_z_number' => $brokenReport?->z_number,
                'broken_at_id' => $brokenReport?->id,
                'verified_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Verify receipt hash chain integrity for a terminal
     *
     * POST /api/v1/pos/reports/receipts/verify-chain
     */
    public function verifyReceiptChain(Request $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        $request->validate([
            // api.pos-stabilization.{016,017,018} — scope pos_terminals by caller tenant + company.
            'terminal_id' => [
                'required', 'string', 'uuid',
                ScopedExists::tenantAndCompany(
                    'pos_terminals',
                    $this->companyContext->requireCompany()->tenant_id,
                    $this->companyContext->requireCompanyId(),
                ),
            ],
        ]);

        /** @var Terminal $terminal */
        $terminal = Terminal::findOrFail($request->input('terminal_id'));

        // Verify terminal belongs to current company
        if ($terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Terminal does not belong to your company',
                ],
            ], 403);
        }

        $isValid = $this->receiptHashService->verifyTerminalChain($terminal);

        $chainLength = Receipt::where('terminal_id', $terminal->id)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->count();
        $firstReceipt = Receipt::where('terminal_id', $terminal->id)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->orderBy('chain_sequence')
            ->first();
        $lastReceipt = Receipt::where('terminal_id', $terminal->id)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->orderByDesc('chain_sequence')
            ->first();

        $response = [
            'is_valid' => $isValid,
            'chain_length' => $chainLength,
            'first_receipt' => $firstReceipt?->receipt_number,
            'last_receipt' => $lastReceipt?->receipt_number,
            'broken_at_sequence' => null,
            'verified_at' => now()->toIso8601String(),
        ];

        if (! $isValid) {
            $allReceipts = Receipt::where('terminal_id', $terminal->id)
                ->where('is_voided', false)
                ->where('is_training', false)
                ->orderBy('chain_sequence')
                ->get();

            $previousHash = null;
            foreach ($allReceipts as $receipt) {
                if ($receipt->previous_hash !== $previousHash) {
                    $response['broken_at_sequence'] = $receipt->chain_sequence;

                    break;
                }

                $expectedHash = $this->receiptHashService->calculateHash($receipt, $previousHash);
                if ($expectedHash !== $receipt->fiscal_hash) {
                    $response['broken_at_sequence'] = $receipt->chain_sequence;

                    break;
                }

                $previousHash = $receipt->fiscal_hash;
            }
        }

        return response()->json(['data' => $response]);
    }
}
