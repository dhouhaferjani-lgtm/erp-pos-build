<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Aggregate snapshot of all POS-side data needed to build a NF525 JET XML
 * export for one company over one date range.
 *
 * Compliance receives this from the POS-side provider via the
 * Nf525DataProviderContract and hands it to its private XML builder; it
 * never touches POS Eloquent models.
 *
 * `$company` is null-eligible only as a defensive shape, but in practice the
 * provider throws if the company id is unknown — the contract tests pin
 * that behavior.
 */
final readonly class Nf525ExportSnapshot
{
    /**
     * @param  list<Nf525ReceiptData>  $sales         Sale receipts in the period
     * @param  list<Nf525ReceiptData>  $voidedReceipts Void events keyed by voided_at in the period
     * @param  list<Nf525ReceiptData>  $returnReceipts Return receipts in the period
     * @param  list<Nf525ReceiptPrintData>  $reprints
     * @param  list<Nf525ZReportData>  $zReports
     * @param  list<Nf525GrandTotalData>  $grandTotals
     * @param  list<Nf525CashDrawerOperationData>  $cashDrawerOperations
     * @param  list<Nf525ShiftData>  $shifts
     * @param  list<Nf525TerminalLifecycleEventData>  $terminalLifecycleEvents
     * @param  list<Nf525TrainingModeCount>  $trainingCounts
     * @param  list<Nf525TerminalData>  $terminals     Hash-chain summary per terminal
     */
    public function __construct(
        public Nf525CompanyHeaderData $company,
        public string $periodStart,    // YYYY-MM-DD
        public string $periodEnd,      // YYYY-MM-DD
        public array $sales,
        public array $voidedReceipts,
        public array $returnReceipts,
        public array $reprints,
        public array $zReports,
        public array $grandTotals,
        public array $cashDrawerOperations,
        public array $shifts,
        public array $terminalLifecycleEvents,
        public array $trainingCounts,
        public array $terminals,
    ) {}
}
