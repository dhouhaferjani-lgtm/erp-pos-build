<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services\Nf525;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\GrandtotalEvent;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPrint;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use Illuminate\Support\Carbon;

/**
 * Service for exporting NF525 JET (Journal des Evenements Techniques) XML.
 *
 * Queries all POS data for a company within a date range and produces
 * a complete JET XML document suitable for French fiscal authority audits.
 */
final class Nf525JetExportService
{
    public function __construct(
        private readonly Nf525XmlBuilder $xmlBuilder,
    ) {}

    /**
     * Export JET XML for a company within a date range.
     *
     * @param  string  $companyId  Company UUID
     * @param  Carbon  $from  Start date (inclusive)
     * @param  Carbon  $to  End date (inclusive)
     * @return string XML string
     */
    public function exportJet(string $companyId, Carbon $from, Carbon $to): string
    {
        /** @var Company $company */
        $company = Company::findOrFail($companyId);

        // Query terminals for this company
        $terminals = Terminal::where('company_id', $companyId)->get();
        $terminalIds = $terminals->pluck('id')->toArray();

        if (count($terminalIds) === 0) {
            return $this->buildEmptyExport($company, $from, $to);
        }

        // Query receipts (non-voided, non-return sales, exclude training)
        $receipts = Receipt::with(['lines', 'vatDetails', 'payments', 'terminal'])
            ->where('company_id', $companyId)
            ->where('receipt_type', ReceiptType::Sale)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->whereBetween('posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('posted_at')
            ->get();

        // Query voided receipts (exclude training)
        $voidedReceipts = Receipt::where('company_id', $companyId)
            ->where('is_voided', true)
            ->where('is_training', false)
            ->whereBetween('voided_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('voided_at')
            ->get();

        // Query return receipts (exclude training)
        $returnReceipts = Receipt::where('company_id', $companyId)
            ->where('receipt_type', ReceiptType::Return)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->whereBetween('posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('posted_at')
            ->get();

        // Query training receipt counts per terminal for TrainingMode section
        $trainingCounts = Receipt::where('company_id', $companyId)
            ->where('is_training', true)
            ->whereBetween('posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('terminal_id, COUNT(*) as training_count')
            ->groupBy('terminal_id')
            ->toBase()
            ->pluck('training_count', 'terminal_id');

        // Query receipt prints (reprints/duplicates)
        $receiptPrints = ReceiptPrint::whereIn('terminal_id', $terminalIds)
            ->whereBetween('printed_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('printed_at')
            ->get();

        // Query Z reports
        $zReports = ZReport::with('terminal')
            ->whereIn('terminal_id', $terminalIds)
            ->whereBetween('generated_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('generated_at')
            ->get();

        // Query shifts
        $shifts = Shift::whereIn('terminal_id', $terminalIds)
            ->whereBetween('opened_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('opened_at')
            ->get();

        // Query cash drawer operations via shifts
        $shiftIds = $shifts->pluck('id')->toArray();
        $cashDrawerOperations = count($shiftIds) > 0
            ? CashDrawerOperation::whereIn('shift_id', $shiftIds)
                ->whereIn('operation_type', ['DEPOSIT', 'PAYOUT', 'REFUND'])
                ->orderBy('created_at')
                ->get()
            : collect();

        // Query grand totals
        $grandtotals = GrandtotalEvent::whereIn('terminal_id', $terminalIds)
            ->whereBetween('generated_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('generated_at')
            ->get();

        // Query terminal lifecycle audit events (activations, deactivations, software updates)
        $terminalLifecycleEventTypes = [
            'terminal.activated',
            'terminal.deactivated',
            'terminal.software_updated',
        ];
        $terminalEvents = AuditEvent::where('company_id', $companyId)
            ->where('aggregate_type', 'Terminal')
            ->whereIn('event_type', $terminalLifecycleEventTypes)
            ->whereBetween('occurred_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('occurred_at')
            ->get();

        // Build XML
        $this->xmlBuilder->createDocument()
            ->addHeader([
                'company_name' => $company->name,
                'company_id' => $company->id,
                'siret' => $company->siret ?? null,
                'address' => $company->address ?? null,
                'software_name' => 'AutoERP',
                'software_version' => config('app.version', '1.0.0'),
                'certification_number' => config('compliance.nf525_certification_number', ''),
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'export_date' => Carbon::now()->toIso8601String(),
            ])
            ->addReceipts($receipts)
            ->addVoids($voidedReceipts)
            ->addReturns($returnReceipts)
            ->addReprints($receiptPrints)
            ->addZReports($zReports)
            ->addGrandTotals($grandtotals)
            ->addCashDrawer($cashDrawerOperations)
            ->addTechnicalEvents($shifts)
            ->addTerminalEvents($terminalEvents)
            ->addTrainingMode($trainingCounts)
            ->addHashChains($terminals);

        return $this->xmlBuilder->toString();
    }

    /**
     * Export JET XML to a file.
     *
     * @param  string  $companyId  Company UUID
     * @param  Carbon  $from  Start date (inclusive)
     * @param  Carbon  $to  End date (inclusive)
     * @param  string  $outputPath  File path for output
     */
    public function exportJetToFile(string $companyId, Carbon $from, Carbon $to, string $outputPath): void
    {
        $xml = $this->exportJet($companyId, $from, $to);
        file_put_contents($outputPath, $xml);
    }

    /**
     * Build an empty export when no terminals exist.
     */
    private function buildEmptyExport(Company $company, Carbon $from, Carbon $to): string
    {
        $this->xmlBuilder->createDocument()
            ->addHeader([
                'company_name' => $company->name,
                'company_id' => $company->id,
                'software_name' => 'AutoERP',
                'software_version' => config('app.version', '1.0.0'),
                'certification_number' => config('compliance.nf525_certification_number', ''),
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'export_date' => Carbon::now()->toIso8601String(),
            ])
            ->addReceipts(collect())
            ->addVoids(collect())
            ->addReturns(collect())
            ->addReprints(collect())
            ->addZReports(collect())
            ->addGrandTotals(collect())
            ->addCashDrawer(collect())
            ->addTechnicalEvents(collect())
            ->addTerminalEvents(collect())
            ->addTrainingMode(collect())
            ->addHashChains(collect());

        return $this->xmlBuilder->toString();
    }
}
