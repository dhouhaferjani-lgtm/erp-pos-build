<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\Nf525\Nf525JetExportService;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReprintLogFilter;
use App\Shared\Contracts\Compliance\Nf525DataProviderContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Controller for NF525 compliance export endpoints.
 *
 * Provides:
 * - JET XML export for fiscal audits
 * - Hash chain verification per terminal
 * - Reprint audit log viewing
 *
 * After H3: depends only on the Nf525DataProviderContract published by POS
 * (via App\Shared\Contracts\Compliance\). No POS Domain imports remain.
 *
 * Tenant isolation (Section 8 / api.compliance round 2):
 * `company_id` is resolved exclusively from `CompanyContext` (set by
 * `CompanyContextMiddleware` after verifying the user's
 * `UserCompanyMembership` for the X-Company-Id header). Earlier rounds
 * trusted `$request->input('company_id')` directly, which let an admin
 * holding `compliance.export_jet` exfiltrate any company's NF525 export
 * across tenant boundaries simply by submitting a foreign company UUID
 * in the request body. Body `company_id` is no longer accepted.
 */
final class Nf525ExportController extends Controller
{
    public function __construct(
        private readonly Nf525JetExportService $exportService,
        private readonly Nf525DataProviderContract $dataProvider,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Export JET XML for a company within a date range.
     *
     * POST /api/v1/compliance/nf525/export-jet
     * Body: { from: string, to: string }  (company resolved from context)
     * Returns: XML file download
     */
    public function exportJet(Request $request): Response
    {
        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $companyId = $this->companyContext->requireCompanyId();
        $from = Carbon::parse((string) $request->input('from'));
        $to = Carbon::parse((string) $request->input('to'));

        $xml = $this->exportService->exportJet($companyId, $from, $to);

        $filename = sprintf('jet_%s_%s_%s.xml', $companyId, $from->format('Ymd'), $to->format('Ymd'));

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Verify receipt and Z-report hash chains for a company's terminals.
     *
     * POST /api/v1/compliance/nf525/verify-chains
     * Body: {} (company resolved from context)
     * Returns: JSON with per-terminal chain verification results
     */
    public function verifyChains(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $terminals = $this->dataProvider->listTerminalsForCompany($companyId);

        $results = [];
        foreach ($terminals as $terminal) {
            $receiptResult = $this->dataProvider->verifyReceiptChain($terminal->terminalId);
            $zReportResult = $this->dataProvider->verifyZReportChain($terminal->terminalId);

            $results[] = [
                'terminal_id' => $terminal->terminalId,
                'terminal_code' => $terminal->terminalCode,
                'terminal_name' => $terminal->terminalName,
                'receipt_chain' => [
                    'is_valid' => $receiptResult->isValid,
                    'total_receipts' => $receiptResult->totalRows,
                    'verified' => $receiptResult->verifiedRows,
                    'failed_at_sequence' => $receiptResult->failedAtSequence,
                    'error' => $receiptResult->error,
                ],
                'z_report_chain' => [
                    'is_valid' => $zReportResult->isValid,
                    'total_reports' => $zReportResult->totalRows,
                    'verified' => $zReportResult->verifiedRows,
                    'failed_at_z_number' => $zReportResult->failedAtSequence,
                    'error' => $zReportResult->error,
                ],
                'is_valid' => $receiptResult->isValid && $zReportResult->isValid,
            ];
        }

        $allValid = true;
        foreach ($results as $row) {
            if ($row['is_valid'] !== true) {
                $allValid = false;
                break;
            }
        }

        return response()->json([
            'data' => [
                'company_id' => $companyId,
                'terminals' => $results,
                'all_chains_valid' => $allValid,
                'verified_at' => Carbon::now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get paginated reprint audit log.
     *
     * GET /api/v1/compliance/nf525/reprint-log
     * Query: terminal_id?, from?, to?, per_page? (company resolved from context)
     * Returns: Paginated reprint log
     */
    public function reprintLog(Request $request): JsonResponse
    {
        $request->validate([
            'terminal_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->companyContext->requireCompanyId();
        $rawTerminalId = $request->input('terminal_id');
        $terminalId = is_string($rawTerminalId) ? $rawTerminalId : null;
        $rawFrom = $request->input('from');
        $fromDate = is_string($rawFrom) ? $rawFrom : null;
        $rawTo = $request->input('to');
        $toDate = is_string($rawTo) ? $rawTo : null;

        $filter = new Nf525ReprintLogFilter(
            companyId: $companyId,
            terminalId: $terminalId,
            fromDate: $fromDate,
            toDate: $toDate,
            perPage: (int) ($request->input('per_page', 20)),
        );

        $page = $this->dataProvider->fetchReprintLog($filter);

        return response()->json([
            'data' => $page->rows,
            'meta' => [
                'current_page' => $page->currentPage,
                'last_page' => $page->lastPage,
                'per_page' => $page->perPage,
                'total' => $page->total,
            ],
        ]);
    }
}
