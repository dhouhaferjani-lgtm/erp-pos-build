<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Presentation\Resources\XReportResource;
use App\Modules\POS\Presentation\Resources\ZReportResource;
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
            'terminal_id' => ['required', 'string', 'uuid', 'exists:pos_terminals,id'],
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
            /** @var \App\Modules\Identity\Domain\User $user */
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
        }
    }

    /**
     * Generate Z report (end-of-day closing)
     *
     * POST /api/v1/pos/reports/z
     */
    public function generateZReport(Request $request): JsonResponse
    {
        Gate::authorize('pos.view_reports');

        $request->validate([
            'terminal_id' => ['required', 'string', 'uuid', 'exists:pos_terminals,id'],
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
            /** @var \App\Modules\Identity\Domain\User $user */
            $user = $request->user();
            $zReport = $this->reportGenerationService->generateZReport(
                $terminal,
                $user
            );

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
        }
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
            'terminal_id' => ['required', 'string', 'uuid'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        $query = ZReport::query()
            ->where('terminal_id', $request->input('terminal_id'))
            ->whereHas('terminal', function (\Illuminate\Database\Eloquent\Builder $q): void {
                $q->whereRaw('company_id = ?', [$this->companyContext->getCompanyId()]);
            })
            ->with(['terminal', 'shift', 'generatedBy']);

        // Filter by date range
        if ($request->filled('from_date')) {
            $query->where('generated_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('generated_at', '<=', $request->input('to_date'));
        }

        $zReports = $query->orderByDesc('z_number')
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

        $zReport = ZReport::where('terminal_id', $request->input('terminal_id'))
            ->where('z_number', $zNumber)
            ->firstOrFail();

        // Verify terminal belongs to current company
        if ($zReport->terminal->company_id !== $this->companyContext->getCompanyId()) {
            abort(403, 'Z report does not belong to your company');
        }

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
            'terminal_id' => ['required', 'string', 'uuid', 'exists:pos_terminals,id'],
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

        if (! $isValid) {
            $brokenReport = $this->zReportHashService->findChainBreak($terminal);

            return response()->json([
                'data' => [
                    'is_valid' => false,
                    'broken_at_z_number' => $brokenReport?->z_number,
                    'broken_at_id' => $brokenReport?->id,
                ],
            ], 200);
        }

        return response()->json([
            'data' => [
                'is_valid' => true,
                'broken_at_z_number' => null,
                'broken_at_id' => null,
            ],
        ]);
    }
}
