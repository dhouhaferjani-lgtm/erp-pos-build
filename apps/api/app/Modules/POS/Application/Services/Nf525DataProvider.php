<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\GrandtotalEvent;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptPrint;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Shared\Contracts\Compliance\DTOs\Nf525CashDrawerOperationData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ChainVerificationResult;
use App\Shared\Contracts\Compliance\DTOs\Nf525CompanyHeaderData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ExportSnapshot;
use App\Shared\Contracts\Compliance\DTOs\Nf525GrandTotalData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReceiptData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReceiptLineData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReceiptPaymentData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReceiptPrintData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReceiptVatDetailData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReprintLogFilter;
use App\Shared\Contracts\Compliance\DTOs\Nf525ReprintLogPage;
use App\Shared\Contracts\Compliance\DTOs\Nf525ShiftData;
use App\Shared\Contracts\Compliance\DTOs\Nf525TerminalChainSummary;
use App\Shared\Contracts\Compliance\DTOs\Nf525TerminalData;
use App\Shared\Contracts\Compliance\DTOs\Nf525TerminalLifecycleEventData;
use App\Shared\Contracts\Compliance\DTOs\Nf525TrainingModeCount;
use App\Shared\Contracts\Compliance\DTOs\Nf525ZReportData;
use App\Shared\Contracts\Compliance\Nf525DataProviderContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * POS-side implementation of Nf525DataProviderContract.
 *
 * Owns all data-extraction queries that previously lived inside Compliance
 * (Nf525JetExportService + the verify*Chain helpers on Nf525ExportController).
 * This service is the only place where the NF525 export traverses POS Domain
 * Eloquent models; everything Compliance sees is a primitive-typed DTO.
 *
 * Extension story for refund flow:
 * - Voucher-ledger entries on receipts: populate
 *   `Nf525ReceiptData::$voucherLedgerEntries` once the Voucher module exists.
 * - `exchange_group_id`, return-audit fields: read from the new columns on
 *   `pos_receipts` and pass straight through to `Nf525ReceiptData`.
 * - Z-report v3 keys: already JSON pass-through via `report_data`; no code
 *   change needed in this provider when refund flow extends `report_data`.
 * - Voucher tender rows: `instrument_type`/`instrument_serial` columns on
 *   `pos_receipt_payments` (renamed/added by refund flow §3.3) flow into
 *   `Nf525ReceiptPaymentData::$instrumentType` and `$instrumentSerial`.
 */
final class Nf525DataProvider implements Nf525DataProviderContract
{
    public function __construct(
        private readonly ReceiptHashService $receiptHashService,
        private readonly ZReportHashService $zReportHashService,
    ) {}

    public function buildExportSnapshot(string $companyId, Carbon $from, Carbon $to): Nf525ExportSnapshot
    {
        /** @var Company $company */
        $company = Company::findOrFail($companyId);

        $terminals = Terminal::where('company_id', $companyId)->get();
        $terminalIds = $terminals->pluck('id')->all();

        $companyHeader = $this->buildCompanyHeader($company);
        $periodStart = $from->toDateString();
        $periodEnd = $to->toDateString();
        $terminalSummaries = $this->mapTerminals($terminals);

        if (count($terminalIds) === 0) {
            return new Nf525ExportSnapshot(
                company: $companyHeader,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                sales: [],
                voidedReceipts: [],
                returnReceipts: [],
                reprints: [],
                zReports: [],
                grandTotals: [],
                cashDrawerOperations: [],
                shifts: [],
                terminalLifecycleEvents: [],
                trainingCounts: [],
                terminals: $terminalSummaries,
            );
        }

        // Sale receipts (non-voided, non-return, non-training, fiscalized only)
        $salesQuery = Receipt::with(['lines', 'vatDetails', 'payments'])
            ->where('company_id', $companyId)
            ->where('receipt_type', ReceiptType::Sale)
            ->where('fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->whereBetween('posted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('posted_at')
            ->get();
        $sales = [];
        foreach ($salesQuery as $receipt) {
            $sales[] = $this->mapSaleReceipt($receipt);
        }

        // Voided receipts (any type, voided in window, non-training, fiscalized only)
        // Receipts that were voided before being fiscalized should not appear in the export.
        $voidedQuery = Receipt::where('company_id', $companyId)
            ->where('fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('is_voided', true)
            ->where('is_training', false)
            ->whereBetween('voided_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('voided_at')
            ->get();
        $voidedReceipts = [];
        foreach ($voidedQuery as $receipt) {
            $voidedReceipts[] = $this->mapVoidedReceipt($receipt);
        }

        // Return receipts (non-voided, non-training, fiscalized only)
        $returnsQuery = Receipt::where('company_id', $companyId)
            ->where('receipt_type', ReceiptType::Return)
            ->where('fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->whereBetween('posted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('posted_at')
            ->get();
        $returnReceipts = [];
        foreach ($returnsQuery as $receipt) {
            $returnReceipts[] = $this->mapReturnReceipt($receipt);
        }

        // Training mode counts per terminal
        /** @var Collection<int|string, mixed> $trainingRows */
        $trainingRows = Receipt::where('company_id', $companyId)
            ->where('is_training', true)
            ->whereBetween('posted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('terminal_id, COUNT(*) as training_count')
            ->groupBy('terminal_id')
            ->toBase()
            ->pluck('training_count', 'terminal_id');
        $trainingCounts = [];
        foreach ($trainingRows as $terminalId => $count) {
            $trainingCounts[] = new Nf525TrainingModeCount(
                terminalId: (string) $terminalId,
                count: (int) $count,
            );
        }

        // Reprint log
        $reprintsQuery = ReceiptPrint::whereIn('terminal_id', $terminalIds)
            ->whereBetween('printed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('printed_at')
            ->get();
        $reprints = [];
        foreach ($reprintsQuery as $print) {
            $reprints[] = $this->mapReceiptPrint($print);
        }

        // Z reports
        $zReportsQuery = ZReport::whereIn('terminal_id', $terminalIds)
            ->whereBetween('generated_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('generated_at')
            ->get();
        $zReports = [];
        foreach ($zReportsQuery as $zReport) {
            $zReports[] = $this->mapZReport($zReport);
        }

        // Shifts
        $shiftsQuery = Shift::whereIn('terminal_id', $terminalIds)
            ->whereBetween('opened_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('opened_at')
            ->get();
        $shifts = [];
        $shiftIds = [];
        foreach ($shiftsQuery as $shift) {
            $shifts[] = $this->mapShift($shift);
            $shiftIds[] = (string) $shift->id;
        }

        // Cash drawer operations (via the shifts in window)
        $cashDrawerOperations = [];
        if (count($shiftIds) > 0) {
            $cashOps = CashDrawerOperation::whereIn('shift_id', $shiftIds)
                ->whereIn('operation_type', ['DEPOSIT', 'PAYOUT', 'REFUND'])
                ->orderBy('created_at')
                ->get();
            foreach ($cashOps as $op) {
                $cashDrawerOperations[] = $this->mapCashDrawerOperation($op);
            }
        }

        // Grand totals
        $grandTotalsQuery = GrandtotalEvent::whereIn('terminal_id', $terminalIds)
            ->whereBetween('generated_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('generated_at')
            ->get();
        $grandTotals = [];
        foreach ($grandTotalsQuery as $grandtotal) {
            $grandTotals[] = $this->mapGrandTotal($grandtotal);
        }

        // Terminal lifecycle audit events
        $lifecycleQuery = AuditEvent::where('company_id', $companyId)
            ->where('aggregate_type', 'Terminal')
            ->whereIn('event_type', [
                'terminal.activated',
                'terminal.deactivated',
                'terminal.software_updated',
            ])
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('occurred_at')
            ->get();
        $terminalLifecycleEvents = [];
        foreach ($lifecycleQuery as $event) {
            $terminalLifecycleEvents[] = $this->mapTerminalLifecycleEvent($event);
        }

        return new Nf525ExportSnapshot(
            company: $companyHeader,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            sales: $sales,
            voidedReceipts: $voidedReceipts,
            returnReceipts: $returnReceipts,
            reprints: $reprints,
            zReports: $zReports,
            grandTotals: $grandTotals,
            cashDrawerOperations: $cashDrawerOperations,
            shifts: $shifts,
            terminalLifecycleEvents: $terminalLifecycleEvents,
            trainingCounts: $trainingCounts,
            terminals: $terminalSummaries,
        );
    }

    public function listTerminalsForCompany(string $companyId): array
    {
        $terminals = Terminal::where('company_id', $companyId)->get();
        $summaries = [];
        foreach ($terminals as $terminal) {
            $summaries[] = new Nf525TerminalChainSummary(
                terminalId: (string) $terminal->id,
                terminalCode: $terminal->code,
                terminalName: $terminal->name,
            );
        }

        return $summaries;
    }

    public function verifyReceiptChain(string $terminalId): Nf525ChainVerificationResult
    {
        $receipts = Receipt::where('terminal_id', $terminalId)
            ->orderBy('chain_sequence')
            ->get();

        if ($receipts->isEmpty()) {
            return new Nf525ChainVerificationResult(
                isValid: true,
                totalRows: 0,
                verifiedRows: 0,
                failedAtSequence: null,
                error: null,
            );
        }

        $verified = 0;
        $previousHash = null;

        foreach ($receipts as $receipt) {
            if ($receipt->previous_hash !== $previousHash) {
                return new Nf525ChainVerificationResult(
                    isValid: false,
                    totalRows: $receipts->count(),
                    verifiedRows: $verified,
                    failedAtSequence: (int) $receipt->chain_sequence,
                    error: 'Chain linkage broken: previous_hash mismatch',
                );
            }

            $expected = $this->receiptHashService->calculateHash($receipt, $previousHash);
            if ($expected !== $receipt->fiscal_hash) {
                return new Nf525ChainVerificationResult(
                    isValid: false,
                    totalRows: $receipts->count(),
                    verifiedRows: $verified,
                    failedAtSequence: (int) $receipt->chain_sequence,
                    error: 'Fiscal hash mismatch: receipt data may have been tampered with',
                );
            }

            $previousHash = $receipt->fiscal_hash;
            $verified++;
        }

        return new Nf525ChainVerificationResult(
            isValid: true,
            totalRows: $receipts->count(),
            verifiedRows: $verified,
            failedAtSequence: null,
            error: null,
        );
    }

    public function verifyZReportChain(string $terminalId): Nf525ChainVerificationResult
    {
        $zReports = ZReport::where('terminal_id', $terminalId)
            ->orderBy('z_number')
            ->get();

        if ($zReports->isEmpty()) {
            return new Nf525ChainVerificationResult(
                isValid: true,
                totalRows: 0,
                verifiedRows: 0,
                failedAtSequence: null,
                error: null,
            );
        }

        $verified = 0;
        $previousHash = null;

        foreach ($zReports as $zReport) {
            if ($zReport->previous_z_hash !== $previousHash) {
                return new Nf525ChainVerificationResult(
                    isValid: false,
                    totalRows: $zReports->count(),
                    verifiedRows: $verified,
                    failedAtSequence: (int) $zReport->z_number,
                    error: 'Chain linkage broken: previous_z_hash mismatch',
                );
            }

            $expected = $this->zReportHashService->calculateHash($zReport, $previousHash);
            if ($expected !== $zReport->fiscal_hash) {
                return new Nf525ChainVerificationResult(
                    isValid: false,
                    totalRows: $zReports->count(),
                    verifiedRows: $verified,
                    failedAtSequence: (int) $zReport->z_number,
                    error: 'Fiscal hash mismatch: Z-report data may have been tampered with',
                );
            }

            $previousHash = $zReport->fiscal_hash;
            $verified++;
        }

        return new Nf525ChainVerificationResult(
            isValid: true,
            totalRows: $zReports->count(),
            verifiedRows: $verified,
            failedAtSequence: null,
            error: null,
        );
    }

    public function fetchReprintLog(Nf525ReprintLogFilter $filter): Nf525ReprintLogPage
    {
        $terminalIds = Terminal::where('company_id', $filter->companyId)
            ->pluck('id')
            ->all();

        $query = ReceiptPrint::with(['receipt', 'terminal', 'user'])
            ->whereIn('terminal_id', $terminalIds);

        if ($filter->terminalId !== null) {
            $query->where('terminal_id', $filter->terminalId);
        }

        if ($filter->fromDate !== null) {
            $query->where('printed_at', '>=', Carbon::parse($filter->fromDate)->startOfDay());
        }

        if ($filter->toDate !== null) {
            $query->where('printed_at', '<=', Carbon::parse($filter->toDate)->endOfDay());
        }

        $paginated = $query->orderByDesc('printed_at')->paginate($filter->perPage);

        /** @var list<array<string, mixed>> $rows */
        $rows = array_map(
            static fn ($model): array => $model->toArray(),
            $paginated->items()
        );

        return new Nf525ReprintLogPage(
            rows: $rows,
            currentPage: $paginated->currentPage(),
            lastPage: $paginated->lastPage(),
            perPage: $paginated->perPage(),
            total: $paginated->total(),
        );
    }

    private function buildCompanyHeader(Company $company): Nf525CompanyHeaderData
    {
        // The Eloquent Company model in this codebase does not currently expose
        // dedicated `siret` / `address` columns; the original Compliance code
        // read them via `??` so missing values fell through. We preserve that
        // behaviour exactly: read the magic property and coerce to nullable
        // string, with no synthesis or fallback.
        /** @var string|null $siret */
        $siret = $this->readNullableString($company, 'siret');
        /** @var string|null $address */
        $address = $this->readNullableString($company, 'address');

        return new Nf525CompanyHeaderData(
            id: (string) $company->id,
            name: $company->name,
            siret: $siret,
            address: $address,
        );
    }

    private function readNullableString(Company $company, string $key): ?string
    {
        $value = $company->getAttribute($key);
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Terminal>  $terminals
     * @return list<Nf525TerminalData>
     */
    private function mapTerminals(\Illuminate\Database\Eloquent\Collection $terminals): array
    {
        $out = [];
        foreach ($terminals as $terminal) {
            $out[] = new Nf525TerminalData(
                id: (string) $terminal->id,
                code: $terminal->code,
                name: $terminal->name,
                genesisSeed: $terminal->genesis_seed,
                currentSequence: (int) $terminal->current_sequence,
                currentYear: (int) $terminal->current_year,
                lastHash: $terminal->last_hash,
            );
        }

        return $out;
    }

    private function mapSaleReceipt(Receipt $receipt): Nf525ReceiptData
    {
        $lines = [];
        if ($receipt->relationLoaded('lines')) {
            foreach ($receipt->lines as $line) {
                $lines[] = $this->mapLine($line);
            }
        }

        $vatDetails = [];
        if ($receipt->relationLoaded('vatDetails')) {
            foreach ($receipt->vatDetails as $vat) {
                $vatDetails[] = $this->mapVatDetail($vat);
            }
        }

        $payments = [];
        if ($receipt->relationLoaded('payments')) {
            foreach ($receipt->payments as $payment) {
                $payments[] = $this->mapPayment($payment);
            }
        }

        return new Nf525ReceiptData(
            id: (string) $receipt->id,
            receiptNumber: $receipt->receipt_number,
            terminalId: (string) $receipt->terminal_id,
            postedAtIso8601: $receipt->posted_at->toIso8601String(),
            chainSequence: (int) $receipt->chain_sequence,
            fiscalHash: $receipt->fiscal_hash,
            previousHash: $receipt->previous_hash,
            subtotal: (string) $receipt->subtotal,
            taxAmount: (string) $receipt->tax_amount,
            discountAmount: (string) $receipt->discount_amount,
            total: (string) $receipt->total,
            currency: $receipt->currency,
            cashierName: $receipt->cashier_name,
            customerName: $receipt->customer_name,
            lines: $lines,
            vatDetails: $vatDetails,
            payments: $payments,
            voidedAtIso8601: null,
            voidedBy: null,
            voidReason: null,
            originalReceiptId: null,
            returnReasonValue: null,
        );
    }

    private function mapVoidedReceipt(Receipt $receipt): Nf525ReceiptData
    {
        return new Nf525ReceiptData(
            id: (string) $receipt->id,
            receiptNumber: $receipt->receipt_number,
            terminalId: (string) $receipt->terminal_id,
            postedAtIso8601: $receipt->posted_at->toIso8601String(),
            chainSequence: (int) $receipt->chain_sequence,
            fiscalHash: $receipt->fiscal_hash,
            previousHash: $receipt->previous_hash,
            subtotal: (string) $receipt->subtotal,
            taxAmount: (string) $receipt->tax_amount,
            discountAmount: (string) $receipt->discount_amount,
            total: (string) $receipt->total,
            currency: $receipt->currency,
            cashierName: $receipt->cashier_name,
            customerName: $receipt->customer_name,
            lines: [],
            vatDetails: [],
            payments: [],
            voidedAtIso8601: $receipt->voided_at?->toIso8601String(),
            voidedBy: $receipt->voided_by,
            voidReason: $receipt->void_reason,
            originalReceiptId: null,
            returnReasonValue: null,
        );
    }

    private function mapReturnReceipt(Receipt $receipt): Nf525ReceiptData
    {
        return new Nf525ReceiptData(
            id: (string) $receipt->id,
            receiptNumber: $receipt->receipt_number,
            terminalId: (string) $receipt->terminal_id,
            postedAtIso8601: $receipt->posted_at->toIso8601String(),
            chainSequence: (int) $receipt->chain_sequence,
            fiscalHash: $receipt->fiscal_hash,
            previousHash: $receipt->previous_hash,
            subtotal: (string) $receipt->subtotal,
            taxAmount: (string) $receipt->tax_amount,
            discountAmount: (string) $receipt->discount_amount,
            total: (string) $receipt->total,
            currency: $receipt->currency,
            cashierName: $receipt->cashier_name,
            customerName: $receipt->customer_name,
            lines: [],
            vatDetails: [],
            payments: [],
            voidedAtIso8601: null,
            voidedBy: null,
            voidReason: null,
            originalReceiptId: $receipt->original_receipt_id,
            returnReasonValue: $receipt->return_reason !== null ? $receipt->return_reason->value : null,
        );
    }

    private function mapLine(ReceiptLine $line): Nf525ReceiptLineData
    {
        return new Nf525ReceiptLineData(
            lineNumber: (int) $line->line_number,
            productCode: $line->product_code,
            productName: $line->product_name,
            quantity: (string) $line->quantity,
            unitPrice: (string) $line->unit_price,
            lineTotal: (string) $line->line_total,
            taxRate: (string) $line->tax_rate,
            taxAmount: (string) $line->tax_amount,
            discountAmount: (string) ($line->discount_amount ?? '0.00'),
        );
    }

    private function mapVatDetail(ReceiptVatDetail $vat): Nf525ReceiptVatDetailData
    {
        return new Nf525ReceiptVatDetailData(
            taxRate: (string) $vat->tax_rate,
            netAmount: (string) $vat->net_amount,
            vatAmount: (string) $vat->vat_amount,
            grossAmount: (string) $vat->gross_amount,
        );
    }

    private function mapPayment(ReceiptPayment $payment): Nf525ReceiptPaymentData
    {
        return new Nf525ReceiptPaymentData(
            paymentType: $payment->payment_type,
            amount: (string) $payment->amount,
        );
    }

    private function mapReceiptPrint(ReceiptPrint $print): Nf525ReceiptPrintData
    {
        return new Nf525ReceiptPrintData(
            id: (string) $print->id,
            receiptId: (string) $print->receipt_id,
            terminalId: (string) $print->terminal_id,
            userId: (string) $print->user_id,
            printType: $print->print_type->value,
            copyNumber: (int) $print->copy_number,
            printMethod: $print->print_method->value,
            printedAtIso8601: $print->printed_at->toIso8601String(),
        );
    }

    private function mapZReport(ZReport $zReport): Nf525ZReportData
    {
        return new Nf525ZReportData(
            id: (string) $zReport->id,
            terminalId: (string) $zReport->terminal_id,
            zNumber: (int) $zReport->z_number,
            fiscalHash: $zReport->fiscal_hash,
            previousZHash: $zReport->previous_z_hash,
            generatedAtIso8601: $zReport->generated_at->toIso8601String(),
            reportData: $zReport->report_data ?? [],
        );
    }

    private function mapGrandTotal(GrandtotalEvent $grandtotal): Nf525GrandTotalData
    {
        return new Nf525GrandTotalData(
            id: (string) $grandtotal->id,
            terminalId: (string) $grandtotal->terminal_id,
            eventType: $grandtotal->event_type,
            sequenceNumber: (int) $grandtotal->sequence_number,
            fiscalHash: $grandtotal->fiscal_hash,
            previousHash: $grandtotal->previous_hash,
            periodStartIso8601: $grandtotal->period_start->toIso8601String(),
            periodEndIso8601: $grandtotal->period_end->toIso8601String(),
            generatedAtIso8601: $grandtotal->generated_at->toIso8601String(),
            periodTotals: $grandtotal->period_totals ?? [],
            perpetualTotals: $grandtotal->perpetual_totals ?? [],
        );
    }

    private function mapCashDrawerOperation(CashDrawerOperation $op): Nf525CashDrawerOperationData
    {
        return new Nf525CashDrawerOperationData(
            id: (string) $op->id,
            shiftId: (string) $op->shift_id,
            operationType: $op->operation_type,
            amount: (string) $op->amount,
            userId: (string) $op->user_id,
            reason: $op->reason,
            createdAtIso8601: $op->created_at->toIso8601String(),
        );
    }

    private function mapShift(Shift $shift): Nf525ShiftData
    {
        return new Nf525ShiftData(
            id: (string) $shift->id,
            terminalId: (string) $shift->terminal_id,
            cashierId: (string) $shift->cashier_id,
            shiftNumber: (int) $shift->shift_number,
            openingCash: (string) $shift->opening_cash,
            openedAtIso8601: $shift->opened_at->toIso8601String(),
            closedAtIso8601: $shift->closed_at?->toIso8601String(),
            expectedCash: $shift->expected_cash !== null ? (string) $shift->expected_cash : null,
            actualCash: $shift->actual_cash !== null ? (string) $shift->actual_cash : null,
            variance: $shift->variance !== null ? (string) $shift->variance : null,
        );
    }

    private function mapTerminalLifecycleEvent(AuditEvent $event): Nf525TerminalLifecycleEventData
    {
        return new Nf525TerminalLifecycleEventData(
            id: (string) $event->id,
            terminalId: (string) $event->aggregate_id,
            eventType: $event->event_type,
            occurredAtIso8601: $event->occurred_at->toIso8601String(),
            payload: $event->payload ?? [],
        );
    }
}
