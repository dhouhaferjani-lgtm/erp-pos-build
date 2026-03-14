<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Services\Nf525\Nf525JetExportService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPrint;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Domain\Services\ZReportHashService;
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
 */
final class Nf525ExportController extends Controller
{
    public function __construct(
        private readonly Nf525JetExportService $exportService,
        private readonly ReceiptHashService $receiptHashService,
        private readonly ZReportHashService $zReportHashService,
    ) {}

    /**
     * Export JET XML for a company within a date range.
     *
     * POST /api/v1/compliance/nf525/export-jet
     * Body: { company_id: string, from: string, to: string }
     * Returns: XML file download
     */
    public function exportJet(Request $request): Response
    {
        $request->validate([
            'company_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $companyId = (string) $request->input('company_id');
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
     * Body: { company_id: string }
     * Returns: JSON with per-terminal chain verification results
     */
    public function verifyChains(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'uuid'],
        ]);

        $companyId = (string) $request->input('company_id');

        $terminals = Terminal::where('company_id', $companyId)->get();

        $results = [];

        foreach ($terminals as $terminal) {
            $receiptResult = $this->verifyReceiptChain($terminal);
            $zReportResult = $this->verifyZReportChain($terminal);

            $results[] = [
                'terminal_id' => $terminal->id,
                'terminal_code' => $terminal->code,
                'terminal_name' => $terminal->name,
                'receipt_chain' => $receiptResult,
                'z_report_chain' => $zReportResult,
                'is_valid' => $receiptResult['is_valid'] && $zReportResult['is_valid'],
            ];
        }

        $allValid = collect($results)->every(fn (array $r): bool => $r['is_valid']);

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
     * Query: company_id, terminal_id?, from?, to?, per_page?
     * Returns: Paginated reprint log
     */
    public function reprintLog(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'uuid'],
            'terminal_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = (string) $request->input('company_id');
        $perPage = (int) ($request->input('per_page', 20));

        // Get terminal IDs for the company
        $terminalIds = Terminal::where('company_id', $companyId)->pluck('id')->toArray();

        $query = ReceiptPrint::with(['receipt', 'terminal', 'user'])
            ->whereIn('terminal_id', $terminalIds);

        $terminalId = $request->input('terminal_id');
        if ($terminalId !== null && is_string($terminalId)) {
            $query->where('terminal_id', $terminalId);
        }

        $from = $request->input('from');
        if ($from !== null && is_string($from)) {
            $query->where('printed_at', '>=', Carbon::parse($from)->startOfDay());
        }

        $to = $request->input('to');
        if ($to !== null && is_string($to)) {
            $query->where('printed_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $paginated = $query->orderByDesc('printed_at')->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Verify receipt hash chain integrity for a terminal.
     *
     * @return array{is_valid: bool, total_receipts: int, verified: int, failed_at_sequence: int|null, error: string|null}
     */
    private function verifyReceiptChain(Terminal $terminal): array
    {
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->orderBy('chain_sequence')
            ->get();

        if ($receipts->isEmpty()) {
            return [
                'is_valid' => true,
                'total_receipts' => 0,
                'verified' => 0,
                'failed_at_sequence' => null,
                'error' => null,
            ];
        }

        $verified = 0;
        $previousHash = null;

        foreach ($receipts as $receipt) {
            // Verify chain linkage
            if ($receipt->previous_hash !== $previousHash) {
                return [
                    'is_valid' => false,
                    'total_receipts' => $receipts->count(),
                    'verified' => $verified,
                    'failed_at_sequence' => $receipt->chain_sequence,
                    'error' => 'Chain linkage broken: previous_hash mismatch',
                ];
            }

            // Verify hash computation
            $expectedHash = $this->receiptHashService->calculateHash($receipt, $previousHash);
            if ($expectedHash !== $receipt->fiscal_hash) {
                return [
                    'is_valid' => false,
                    'total_receipts' => $receipts->count(),
                    'verified' => $verified,
                    'failed_at_sequence' => $receipt->chain_sequence,
                    'error' => 'Fiscal hash mismatch: receipt data may have been tampered with',
                ];
            }

            $previousHash = $receipt->fiscal_hash;
            $verified++;
        }

        return [
            'is_valid' => true,
            'total_receipts' => $receipts->count(),
            'verified' => $verified,
            'failed_at_sequence' => null,
            'error' => null,
        ];
    }

    /**
     * Verify Z-report hash chain integrity for a terminal.
     *
     * @return array{is_valid: bool, total_reports: int, verified: int, failed_at_z_number: int|null, error: string|null}
     */
    private function verifyZReportChain(Terminal $terminal): array
    {
        $zReports = ZReport::where('terminal_id', $terminal->id)
            ->orderBy('z_number')
            ->get();

        if ($zReports->isEmpty()) {
            return [
                'is_valid' => true,
                'total_reports' => 0,
                'verified' => 0,
                'failed_at_z_number' => null,
                'error' => null,
            ];
        }

        $verified = 0;
        $previousHash = null;

        foreach ($zReports as $zReport) {
            // Verify chain linkage
            if ($zReport->previous_z_hash !== $previousHash) {
                return [
                    'is_valid' => false,
                    'total_reports' => $zReports->count(),
                    'verified' => $verified,
                    'failed_at_z_number' => $zReport->z_number,
                    'error' => 'Chain linkage broken: previous_z_hash mismatch',
                ];
            }

            // Verify hash computation
            $expectedHash = $this->zReportHashService->calculateHash($zReport, $previousHash);
            if ($expectedHash !== $zReport->fiscal_hash) {
                return [
                    'is_valid' => false,
                    'total_reports' => $zReports->count(),
                    'verified' => $verified,
                    'failed_at_z_number' => $zReport->z_number,
                    'error' => 'Fiscal hash mismatch: Z-report data may have been tampered with',
                ];
            }

            $previousHash = $zReport->fiscal_hash;
            $verified++;
        }

        return [
            'is_valid' => true,
            'total_reports' => $zReports->count(),
            'verified' => $verified,
            'failed_at_z_number' => null,
            'error' => null,
        ];
    }
}
