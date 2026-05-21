<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\LineItemDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\VatBreakdownDTO;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
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
use App\Shared\Contracts\Compliance\DTOs\Nf525VoucherLedgerEntryData;
use App\Shared\Contracts\Compliance\DTOs\Nf525ZReportData;
use App\Shared\Contracts\Compliance\Nf525DataProviderContract;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
        private readonly ConnectionInterface $db,
        private readonly FiscalIntegrityProvider $integrityProvider,
        private readonly CanonicalPayloadReader $canonicalReader,
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

        $quarantineSection = $this->buildQuarantineSection($companyId, $from, $to);

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
                quarantineSection: $quarantineSection,
                tamperedSection: [],
            );
        }

        // Round-3 (Task 30 T30-R2-B1): tampered receipts (canonical_bytes
        // rehash != fiscal_events.current_hash) are routed here instead of
        // the regular sale/void/return sections. Spec §8 audit-visibility:
        // auditors see a crisp tampered partition; the regular sections
        // remain a clean view of verified, authoritative receipts.
        $tamperedSection = [];

        // Sale receipts (non-voided, non-return, non-training, fiscalized only)
        $salesQuery = Receipt::with(['lines', 'vatDetails', 'payments', 'voucherLedgerEntries'])
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
            // Phase 1 §5.0 D1 (Task 30 round-3): for fiscal_event-backed
            // receipts, source authoritative monetary fields from
            // fiscal_events.payload (not pos_receipts mirror). Verify
            // canonical_bytes rehash against fiscal_events.current_hash
            // (NOT pos_receipts.fiscal_hash — closes the round-2 defect
            // where the comparison reverted to the mirror). Tampered
            // receipts go to $tamperedSection and are EXCLUDED from
            // $sales: tamper detection ≠ tamper exclusion.
            $verifyResult = $this->verifyCanonicalBytesAndLoadPayload($receipt, 'sale');
            if ($verifyResult['tampered']) {
                $tamperedSection[] = $this->renderTamperedEntry($receipt, $verifyResult, 'sale');

                continue;
            }
            $sales[] = $this->mapSaleReceipt($receipt, $verifyResult['payload']);
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
            $verifyResult = $this->verifyCanonicalBytesAndLoadPayload($receipt, 'voided');
            if ($verifyResult['tampered']) {
                $tamperedSection[] = $this->renderTamperedEntry($receipt, $verifyResult, 'voided');

                continue;
            }
            $voidedReceipts[] = $this->mapVoidedReceipt($receipt, $verifyResult['payload']);
        }

        // Return receipts (non-voided, non-training, fiscalized only)
        $returnsQuery = Receipt::with(['voucherLedgerEntries'])
            ->where('company_id', $companyId)
            ->where('receipt_type', ReceiptType::Return)
            ->where('fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->whereBetween('posted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('posted_at')
            ->get();
        $returnReceipts = [];
        foreach ($returnsQuery as $receipt) {
            $verifyResult = $this->verifyCanonicalBytesAndLoadPayload($receipt, 'return');
            if ($verifyResult['tampered']) {
                $tamperedSection[] = $this->renderTamperedEntry($receipt, $verifyResult, 'return');

                continue;
            }
            $returnReceipts[] = $this->mapReturnReceipt($receipt, $verifyResult['payload']);
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
            quarantineSection: $quarantineSection,
            tamperedSection: $tamperedSection,
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
        // Phase 1 §5.0 D1 (Task 30 round-2 BLOCKER closure): the LIVE
        // verify-chains endpoint (Nf525ExportController::verifyChains)
        // routes through this method. Pre-rebuild it recomputed every
        // receipt's hash from POS Domain model fields — a §5.0 D1 violation
        // because it would FALSELY report tampering on any pos_receipts
        // mirror-column tamper (e.g. `total = 999.99` set off-path) and
        // would MISS canonical_bytes tampering on the authoritative
        // fiscal_events row. Rebuild splits the two arms:
        //
        //   - **fiscal_events arm** — receipts with `fiscal_event_id IS
        //     NOT NULL` are verified by re-hashing the linked
        //     `fiscal_events.canonical_bytes` via
        //     ReceiptHashService::verifyTerminalChain. Tampering on
        //     pos_receipts.total is a no-op (mirror); tampering on
        //     canonical_bytes is caught.
        //
        //   - **legacy arm** — pre-Task-21 rows with `fiscal_event_id IS
        //     NULL` are still verified by the original
        //     calculateHash() recomputation (Task 21 R2 carve-out
        //     pattern). These rows are bounded by the projection-row
        //     backfill date and age out post-Phase 1.
        //
        // T2.7 / Codex round-2 P1 (2026-05-10): training receipts
        // (is_training=true) are not part of the production fiscal chain
        // and are excluded from both arms — see
        // ReceiptHashService::verifyTerminalChain + the legacy arm filter
        // below.
        /** @var Terminal|null $terminal */
        $terminal = Terminal::find($terminalId);

        // Total rows + verified rows are scoped to the legacy arm for
        // backward compatibility with the DTO + UI surface. The
        // fiscal_events arm returns a single boolean (verified/failed)
        // via ReceiptHashService; if it fails we surface that as a
        // chain-break result without trying to pinpoint which legacy
        // sequence (the legacy DTO field is the LEGACY chain sequence;
        // the structured Log::error in ReceiptHashService carries the
        // fiscal_events diagnostic).
        $legacyReceipts = Receipt::where('terminal_id', $terminalId)
            ->whereNull('fiscal_event_id')
            ->where('is_training', false)
            ->orderBy('chain_sequence')
            ->get();

        // (1) Fiscal-events arm — delegate to the rebuilt verifier.
        if ($terminal !== null) {
            $fiscalEventsArmOk = $this->receiptHashService->verifyTerminalChainFiscalArm($terminal);
            if (! $fiscalEventsArmOk) {
                return new Nf525ChainVerificationResult(
                    isValid: false,
                    totalRows: $legacyReceipts->count(),
                    verifiedRows: 0,
                    failedAtSequence: null,
                    error: 'Fiscal-events chain break: canonical_bytes rehash or linkage mismatch (see structured log entry for diagnostic)',
                );
            }
        }

        // (2) Legacy arm — verify pre-Task-21 rows via the original path.
        if ($legacyReceipts->isEmpty()) {
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

        foreach ($legacyReceipts as $receipt) {
            if ($receipt->previous_hash !== $previousHash) {
                return new Nf525ChainVerificationResult(
                    isValid: false,
                    totalRows: $legacyReceipts->count(),
                    verifiedRows: $verified,
                    failedAtSequence: (int) $receipt->chain_sequence,
                    error: 'Chain linkage broken: previous_hash mismatch',
                );
            }

            $expected = $this->receiptHashService->calculateHash($receipt, $previousHash);
            if ($expected !== $receipt->fiscal_hash) {
                return new Nf525ChainVerificationResult(
                    isValid: false,
                    totalRows: $legacyReceipts->count(),
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
            totalRows: $legacyReceipts->count(),
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

    /**
     * @param  array<string, mixed>|null  $canonicalPayload  Authoritative server-parsed
     *                                                       payload from fiscal_events.payload (round-3 T30-R2-B1). When non-null,
     *                                                       monetary fields (subtotal / taxAmount / discountAmount / total /
     *                                                       currency) are sourced FROM THIS DICT, not from pos_receipts mirror
     *                                                       fields. NULL for legacy rows (fiscal_event_id IS NULL) and for
     *                                                       fiscal_event-backed rows whose payload_parse_status != 'parsed'
     *                                                       (e.g. SALE_RECEIPT events still pending strict parse) — those
     *                                                       fall back to the mirror per Task 21 R2 carve-out semantics.
     */
    private function mapSaleReceipt(Receipt $receipt, ?array $canonicalPayload = null): Nf525ReceiptData
    {
        // Pass 2A.PHP.2 (synthesis v5 §8.B) — bifurcate by fiscal_event_id.
        // Fiscal-event-backed receipts source per-line / per-payment /
        // per-vat-row data from the canonical payload via CanonicalPayloadReader,
        // capturing canonical-only fields (gtin, tax_category_code, etc.).
        // Legacy (fiscal_event_id IS NULL) receipts fall through to
        // mapSaleReceiptLegacy(), which preserves the pre-Pass-2A.PHP.2
        // pos_receipt_lines / pos_receipt_payments / pos_receipt_vat_details
        // Eloquent read path.
        if ($receipt->fiscal_event_id !== null) {
            $event = FiscalEvent::query()->find($receipt->fiscal_event_id);
            // Defensive: a missing fiscal_events row (orphaned projection)
            // falls back to the legacy path. The tamper-verification
            // step upstream (verifyCanonicalBytesAndLoadPayload) already
            // routed tampered rows to the tamperedSection, but a hard
            // missing row defaults gracefully here.
            if ($event !== null && $event->payload !== null) {
                return $this->mapSaleReceiptFromCanonical($receipt, $event, $canonicalPayload);
            }
        }

        return $this->mapSaleReceiptLegacy($receipt, $canonicalPayload);
    }

    /**
     * Pass 2A.PHP.2 fiscal-event-backed path — synthesis v5 §8.B.
     *
     * @param  array<string, mixed>|null  $canonicalPayload  legacy resolveMonetaryFields fallback shape (round-3 T30-R2-B1).
     */
    private function mapSaleReceiptFromCanonical(Receipt $receipt, FiscalEvent $event, ?array $canonicalPayload): Nf525ReceiptData
    {
        $view = $this->canonicalReader->forSaleReceipt($event);

        $lines = [];
        foreach ($view->lineItems as $i => $line) {
            $lines[] = $this->mapLineFromCanonical($line, $i + 1);
        }
        $payments = array_map([$this, 'mapPaymentFromCanonical'], $view->payments);
        $vatDetails = array_map([$this, 'mapVatDetailFromCanonical'], $view->vatBreakdown);

        $voucherLedgerEntries = $this->mapVoucherLedgerEntries($receipt);

        // Use the canonical payload directly for monetary fields. The
        // legacy `resolveMonetaryFields` translates 10-key shape; the
        // canonical view exposes the 27-key keys.
        $payload = $view->payload;
        $subtotal = $payload->subtotal;
        $taxAmount = $payload->vatTotal;
        $discountAmount = $payload->transactionDiscountAmount;
        $total = $payload->total;
        $currency = $payload->currencyCode;

        return new Nf525ReceiptData(
            id: (string) $receipt->id,
            receiptNumber: $receipt->receipt_number,
            terminalId: (string) $receipt->terminal_id,
            postedAtIso8601: $receipt->posted_at->toIso8601String(),
            chainSequence: (int) $receipt->chain_sequence,
            fiscalHash: $receipt->fiscal_hash,
            previousHash: $receipt->previous_hash,
            subtotal: $subtotal,
            taxAmount: $taxAmount,
            discountAmount: $discountAmount,
            total: $total,
            currency: $currency,
            cashierName: $receipt->cashier_name,
            customerName: $receipt->customer_name,
            lines: $lines,
            vatDetails: $vatDetails,
            payments: $payments,
            voidedAtIso8601: null,
            voidedBy: null,
            voidReason: null,
            originalReceiptId: $view->originalReceiptReference?->fiscalEventId,
            returnReasonValue: $view->originalReceiptReference?->refundReason,
            exchangeGroupId: $receipt->exchange_group_id,
            voucherLedgerEntries: $voucherLedgerEntries,
        );
    }

    /**
     * Legacy `mapSaleReceipt` path — fiscal_event_id IS NULL receipts.
     * Preserves the pre-Pass-2A.PHP.2 Eloquent traversal of POS Domain
     * relations. Carry-forward for pre-Task-21 rows that pre-date the
     * fiscal-event linkage.
     *
     * @param  array<string, mixed>|null  $canonicalPayload  See mapSaleReceipt.
     */
    private function mapSaleReceiptLegacy(Receipt $receipt, ?array $canonicalPayload): Nf525ReceiptData
    {
        $lines = [];
        if ($receipt->relationLoaded('lines')) {
            foreach ($receipt->lines as $line) {
                $lines[] = $this->mapLineLegacy($line);
            }
        }

        $vatDetails = [];
        if ($receipt->relationLoaded('vatDetails')) {
            foreach ($receipt->vatDetails as $vat) {
                $vatDetails[] = $this->mapVatDetailLegacy($vat);
            }
        }

        $payments = [];
        if ($receipt->relationLoaded('payments')) {
            foreach ($receipt->payments as $payment) {
                $payments[] = $this->mapPaymentLegacy($payment);
            }
        }

        $voucherLedgerEntries = $this->mapVoucherLedgerEntries($receipt);

        $monetary = $this->resolveMonetaryFields($receipt, $canonicalPayload);

        return new Nf525ReceiptData(
            id: (string) $receipt->id,
            receiptNumber: $receipt->receipt_number,
            terminalId: (string) $receipt->terminal_id,
            postedAtIso8601: $receipt->posted_at->toIso8601String(),
            chainSequence: (int) $receipt->chain_sequence,
            fiscalHash: $receipt->fiscal_hash,
            previousHash: $receipt->previous_hash,
            subtotal: $monetary['subtotal'],
            taxAmount: $monetary['tax_amount'],
            discountAmount: $monetary['discount_amount'],
            total: $monetary['total'],
            currency: $monetary['currency'],
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
            exchangeGroupId: $receipt->exchange_group_id,
            voucherLedgerEntries: $voucherLedgerEntries,
        );
    }

    /**
     * @param  array<string, mixed>|null  $canonicalPayload  See mapSaleReceipt.
     */
    private function mapVoidedReceipt(Receipt $receipt, ?array $canonicalPayload = null): Nf525ReceiptData
    {
        // Pass 2A.PHP.2 (synthesis v5 §8.B) — bifurcate by fiscal_event_id.
        // Voided receipts use the same monetary-fields surface as sale
        // receipts; the canonical payload bypass picks the authoritative
        // 27-key values; legacy receipts fall through to the projection
        // mirror via resolveMonetaryFields.
        if ($receipt->fiscal_event_id !== null) {
            $event = FiscalEvent::query()->find($receipt->fiscal_event_id);
            if ($event !== null && $event->payload !== null) {
                return $this->mapVoidedReceiptFromCanonical($receipt, $event);
            }
        }

        return $this->mapVoidedReceiptLegacy($receipt, $canonicalPayload);
    }

    private function mapVoidedReceiptFromCanonical(Receipt $receipt, FiscalEvent $event): Nf525ReceiptData
    {
        $view = $this->canonicalReader->forSaleReceipt($event);
        $payload = $view->payload;

        return new Nf525ReceiptData(
            id: (string) $receipt->id,
            receiptNumber: $receipt->receipt_number,
            terminalId: (string) $receipt->terminal_id,
            postedAtIso8601: $receipt->posted_at->toIso8601String(),
            chainSequence: (int) $receipt->chain_sequence,
            fiscalHash: $receipt->fiscal_hash,
            previousHash: $receipt->previous_hash,
            subtotal: $payload->subtotal,
            taxAmount: $payload->vatTotal,
            discountAmount: $payload->transactionDiscountAmount,
            total: $payload->total,
            currency: $payload->currencyCode,
            cashierName: $receipt->cashier_name,
            customerName: $receipt->customer_name,
            lines: [],
            vatDetails: [],
            payments: [],
            voidedAtIso8601: $receipt->voided_at?->toIso8601String(),
            voidedBy: $receipt->voided_by,
            voidReason: $receipt->void_reason,
            originalReceiptId: $view->originalReceiptReference?->fiscalEventId,
            returnReasonValue: $view->originalReceiptReference?->refundReason,
        );
    }

    /**
     * @param  array<string, mixed>|null  $canonicalPayload  See mapSaleReceipt.
     */
    private function mapVoidedReceiptLegacy(Receipt $receipt, ?array $canonicalPayload): Nf525ReceiptData
    {
        $monetary = $this->resolveMonetaryFields($receipt, $canonicalPayload);

        return new Nf525ReceiptData(
            id: (string) $receipt->id,
            receiptNumber: $receipt->receipt_number,
            terminalId: (string) $receipt->terminal_id,
            postedAtIso8601: $receipt->posted_at->toIso8601String(),
            chainSequence: (int) $receipt->chain_sequence,
            fiscalHash: $receipt->fiscal_hash,
            previousHash: $receipt->previous_hash,
            subtotal: $monetary['subtotal'],
            taxAmount: $monetary['tax_amount'],
            discountAmount: $monetary['discount_amount'],
            total: $monetary['total'],
            currency: $monetary['currency'],
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

    /**
     * @param  array<string, mixed>|null  $canonicalPayload  See mapSaleReceipt.
     */
    private function mapReturnReceipt(Receipt $receipt, ?array $canonicalPayload = null): Nf525ReceiptData
    {
        // Pass 2A.PHP.2 (synthesis v5 §8.B) — bifurcate by fiscal_event_id.
        // Canonical refunds use `invoice_type_code='REFUND'` +
        // `original_receipt_reference` for the audit-side linkage; legacy
        // returns rely on `pos_receipts.original_receipt_id` +
        // `return_reason` enum columns.
        if ($receipt->fiscal_event_id !== null) {
            $event = FiscalEvent::query()->find($receipt->fiscal_event_id);
            if ($event !== null && $event->payload !== null) {
                return $this->mapReturnReceiptFromCanonical($receipt, $event);
            }
        }

        return $this->mapReturnReceiptLegacy($receipt, $canonicalPayload);
    }

    private function mapReturnReceiptFromCanonical(Receipt $receipt, FiscalEvent $event): Nf525ReceiptData
    {
        $view = $this->canonicalReader->forSaleReceipt($event);
        $payload = $view->payload;

        $lines = [];
        foreach ($view->lineItems as $i => $line) {
            $lines[] = $this->mapLineFromCanonical($line, $i + 1);
        }
        $payments = array_map([$this, 'mapPaymentFromCanonical'], $view->payments);
        $vatDetails = array_map([$this, 'mapVatDetailFromCanonical'], $view->vatBreakdown);

        $voucherLedgerEntries = $this->mapVoucherLedgerEntries($receipt);

        return new Nf525ReceiptData(
            id: (string) $receipt->id,
            receiptNumber: $receipt->receipt_number,
            terminalId: (string) $receipt->terminal_id,
            postedAtIso8601: $receipt->posted_at->toIso8601String(),
            chainSequence: (int) $receipt->chain_sequence,
            fiscalHash: $receipt->fiscal_hash,
            previousHash: $receipt->previous_hash,
            subtotal: $payload->subtotal,
            taxAmount: $payload->vatTotal,
            discountAmount: $payload->transactionDiscountAmount,
            total: $payload->total,
            currency: $payload->currencyCode,
            cashierName: $receipt->cashier_name,
            customerName: $receipt->customer_name,
            lines: $lines,
            vatDetails: $vatDetails,
            payments: $payments,
            voidedAtIso8601: null,
            voidedBy: null,
            voidReason: null,
            // Canonical refunds source linkage from
            // original_receipt_reference. The legacy column `original_receipt_id`
            // may also be populated locally (PosCoreReceiptProjection
            // best-effort resolution), but the canonical fiscal_event_id is
            // authoritative for cross-terminal refunds. PHPStan narrows
            // `$view->originalReceiptReference` to non-null on the canonical
            // refund path because mapReturnReceiptFromCanonical is only
            // dispatched for receipts whose canonical payload carries
            // invoice_type_code IN {REFUND, VOID} + non-null
            // original_receipt_reference per synthesis v5 §3.
            originalReceiptId: $view->originalReceiptReference !== null
                ? $view->originalReceiptReference->fiscalEventId
                : ($receipt->original_receipt_id !== null ? (string) $receipt->original_receipt_id : null),
            returnReasonValue: $view->originalReceiptReference !== null
                ? $view->originalReceiptReference->refundReason
                : ($receipt->return_reason !== null ? $receipt->return_reason->value : null),
            exchangeGroupId: $receipt->exchange_group_id,
            authorizedByUserId: $receipt->authorized_by_user_id,
            overrideReason: $receipt->override_reason,
            outOfWindow: $receipt->out_of_window,
            voucherLedgerEntries: $voucherLedgerEntries,
        );
    }

    /**
     * @param  array<string, mixed>|null  $canonicalPayload  See mapSaleReceipt.
     */
    private function mapReturnReceiptLegacy(Receipt $receipt, ?array $canonicalPayload): Nf525ReceiptData
    {
        $voucherLedgerEntries = $this->mapVoucherLedgerEntries($receipt);
        $monetary = $this->resolveMonetaryFields($receipt, $canonicalPayload);

        return new Nf525ReceiptData(
            id: (string) $receipt->id,
            receiptNumber: $receipt->receipt_number,
            terminalId: (string) $receipt->terminal_id,
            postedAtIso8601: $receipt->posted_at->toIso8601String(),
            chainSequence: (int) $receipt->chain_sequence,
            fiscalHash: $receipt->fiscal_hash,
            previousHash: $receipt->previous_hash,
            subtotal: $monetary['subtotal'],
            taxAmount: $monetary['tax_amount'],
            discountAmount: $monetary['discount_amount'],
            total: $monetary['total'],
            currency: $monetary['currency'],
            cashierName: $receipt->cashier_name,
            customerName: $receipt->customer_name,
            lines: [],
            vatDetails: [],
            payments: [],
            voidedAtIso8601: null,
            voidedBy: null,
            voidReason: null,
            originalReceiptId: $receipt->original_receipt_id !== null ? (string) $receipt->original_receipt_id : null,
            returnReasonValue: $receipt->return_reason !== null ? $receipt->return_reason->value : null,
            exchangeGroupId: $receipt->exchange_group_id,
            authorizedByUserId: $receipt->authorized_by_user_id,
            overrideReason: $receipt->override_reason,
            outOfWindow: $receipt->out_of_window,
            voucherLedgerEntries: $voucherLedgerEntries,
        );
    }

    /**
     * Phase 1 §5.0 D1 (Task 30 round-3 T30-R2-B1): source the monetary
     * fields from the AUTHORITATIVE server-parsed payload
     * (`fiscal_events.payload`) when available — NOT from the
     * `pos_receipts` projection mirror. Closes Codex's deeper ask:
     * a tampered `pos_receipts.total = '999999.99'` would otherwise
     * surface in the JET XML even though the chain-verify pass
     * detected the tamper.
     *
     * Mapping:
     *   payload['subtotal']        → Nf525ReceiptData::$subtotal
     *   payload['tax_total']       → Nf525ReceiptData::$taxAmount
     *   payload['discount_total']  → Nf525ReceiptData::$discountAmount
     *   payload['total']           → Nf525ReceiptData::$total
     *   payload['currency']        → Nf525ReceiptData::$currency
     *
     * Falls back to the projection-row mirror when the payload is
     * missing the field (defensive) or when no canonical payload was
     * supplied (legacy / parse-pending rows). Fields the canonical
     * envelope does NOT carry (receipt_number, ID, terminal_id,
     * cashier/customer names, line-level data, line-level VAT
     * detail, etc.) ALWAYS come from the projection — the canonical
     * SaleReceiptPayload contract (§4) doesn't include them.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{subtotal: string, tax_amount: string, discount_amount: string, total: string, currency: string}
     */
    private function resolveMonetaryFields(Receipt $receipt, ?array $payload): array
    {
        // Defaults from the projection mirror.
        $values = [
            'subtotal' => (string) $receipt->subtotal,
            'tax_amount' => (string) $receipt->tax_amount,
            'discount_amount' => (string) $receipt->discount_amount,
            'total' => (string) $receipt->total,
            'currency' => (string) $receipt->currency,
        ];

        if ($payload === null) {
            return $values;
        }

        // Override with authoritative payload values when present.
        // The canonical SaleReceiptPayload uses 'tax_total' /
        // 'discount_total'; the NF525 DTO uses 'taxAmount' /
        // 'discountAmount'. We translate the contract here.
        if (isset($payload['subtotal']) && is_string($payload['subtotal'])) {
            $values['subtotal'] = $payload['subtotal'];
        }
        if (isset($payload['tax_total']) && is_string($payload['tax_total'])) {
            $values['tax_amount'] = $payload['tax_total'];
        }
        if (isset($payload['discount_total']) && is_string($payload['discount_total'])) {
            $values['discount_amount'] = $payload['discount_total'];
        }
        if (isset($payload['total']) && is_string($payload['total'])) {
            $values['total'] = $payload['total'];
        }
        if (isset($payload['currency']) && is_string($payload['currency'])) {
            $values['currency'] = $payload['currency'];
        }

        return $values;
    }

    // -----------------------------------------------------------------
    // Pass 2A.PHP.2 canonical-payload helpers — synthesis v5 §8.B.
    // Used by the fiscal-event-backed map* paths; emit canonical-only
    // fields (gtin / tax_category_code / non_collected_subtype /
    // foreign_currency_* ) per the v5 §3 contract.
    // -----------------------------------------------------------------

    private function mapLineFromCanonical(LineItemDTO $line, int $lineNumber): Nf525ReceiptLineData
    {
        // Per-line line_number is NOT on the canonical payload — it is an
        // export-side concern. Caller threads a 1-based index for
        // deterministic ordering.
        return new Nf525ReceiptLineData(
            lineNumber: $lineNumber,
            productCode: $line->sku !== '' ? $line->sku : null,
            productName: $line->name,
            quantity: $line->quantity,
            unitPrice: $line->unitPrice,
            lineTotal: $line->lineSubtotal,
            taxRate: $line->vatRate,
            taxAmount: $line->lineVat,
            discountAmount: $line->lineDiscountAmount,
            gtin: $line->gtin,
            taxCategoryCode: $line->taxCategoryCode !== '' ? $line->taxCategoryCode : null,
            nonCollectedSubtype: $line->nonCollectedSubtype,
        );
    }

    private function mapPaymentFromCanonical(PaymentDTO $payment): Nf525ReceiptPaymentData
    {
        return new Nf525ReceiptPaymentData(
            // The canonical payload's `method_code` is the audit-stable axis;
            // the legacy `payment_type` is the projection-snapshot display
            // name. We emit the method_code here so canonical round-trip
            // tests stay deterministic (the snapshot is opaque per-tenant).
            paymentType: $payment->methodCode,
            amount: $payment->amount,
            instrumentType: $payment->instrumentType,
            instrumentSerial: $payment->instrumentSerial,
            foreignCurrencyAmount: $payment->foreignCurrencyAmount,
            foreignCurrencyCode: $payment->foreignCurrencyCode,
        );
    }

    private function mapVatDetailFromCanonical(VatBreakdownDTO $vat): Nf525ReceiptVatDetailData
    {
        return new Nf525ReceiptVatDetailData(
            taxRate: $vat->rate,
            netAmount: $vat->netAmount,
            vatAmount: $vat->vatAmount,
            grossAmount: $vat->grossAmount,
            taxCategoryCode: $vat->taxCategoryCode !== '' ? $vat->taxCategoryCode : null,
        );
    }

    // -----------------------------------------------------------------
    // Legacy helpers — fiscal_event_id IS NULL receipts.
    // -----------------------------------------------------------------

    private function mapLineLegacy(ReceiptLine $line): Nf525ReceiptLineData
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

    private function mapVatDetailLegacy(ReceiptVatDetail $vat): Nf525ReceiptVatDetailData
    {
        return new Nf525ReceiptVatDetailData(
            taxRate: (string) $vat->tax_rate,
            netAmount: (string) $vat->net_amount,
            vatAmount: (string) $vat->vat_amount,
            grossAmount: (string) $vat->gross_amount,
        );
    }

    private function mapPaymentLegacy(ReceiptPayment $payment): Nf525ReceiptPaymentData
    {
        return new Nf525ReceiptPaymentData(
            paymentType: $payment->payment_type,
            amount: (string) $payment->amount,
            instrumentType: $payment->instrument_type?->value,
            instrumentSerial: $payment->instrument_serial,
        );
    }

    /**
     * Map voucher-ledger rows attached to a receipt to the NF525 DTO shape.
     * Returns an empty array for receipts with no voucher activity.
     *
     * Voucher ledger entries are sorted by voucher_code (i.e. the voucher's code attribute)
     * but since we only have the voucher_id here, we sort by id as the tie-breaker for
     * deterministic ordering without an extra join. The spec only requires a stable order.
     *
     * @return list<Nf525VoucherLedgerEntryData>
     */
    private function mapVoucherLedgerEntries(Receipt $receipt): array
    {
        if (! $receipt->relationLoaded('voucherLedgerEntries')) {
            return [];
        }

        $entries = [];
        // The voucherLedgerEntries relation is already ordered by voucher_id (Receipt model).
        foreach ($receipt->voucherLedgerEntries as $ledger) {
            // Use the enum case name (PascalCase, e.g. "Issued") as the DTO event
            // string per the Nf525VoucherLedgerEntryData contract docblock.
            $entries[] = new Nf525VoucherLedgerEntryData(
                voucherId: (string) $ledger->voucher_id,
                voucherCode: '',   // Code is on the Voucher model; we avoid loading it here
                // to prevent a N+1. The export consumers use voucherId for
                // lookup. A future enhancement can eager-load the code.
                event: $ledger->event->name,
                amount: (string) $ledger->amount,
                glJournalEntryId: $ledger->gl_journal_entry_id,
            );
        }

        return $entries;
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

    /**
     * Phase 1 §5.0 D1 / §8 (Task 30 round-3 T30-R2-B1): per-receipt
     * canonical_bytes verification + authoritative payload load.
     *
     * Returns a verification result:
     *   - `tampered` (bool): true if the canonical_bytes re-hash does
     *     NOT match `fiscal_events.current_hash` — the authoritative
     *     chain truth (NOT `pos_receipts.fiscal_hash`, which is a
     *     mirror per §5.0 D1).
     *   - `payload` (array|null): the authoritative server-parsed
     *     payload from `fiscal_events.payload` when (a) the receipt is
     *     fiscal_event-backed AND (b) the linked row's
     *     `payload_parse_status === 'parsed'`. The map functions use
     *     this to source monetary fields from the canonical payload
     *     rather than the projection mirror.
     *
     * Behaviour:
     *   - Legacy rows (`fiscal_event_id IS NULL`): no-op result
     *     `{tampered: false, payload: null}` — the legacy verifier arm
     *     in `Nf525DataProvider::verifyReceiptChain` covers them.
     *   - Phase-1 rows (`fiscal_event_id IS NOT NULL`): load the
     *     linked fiscal_events row (canonical_bytes + current_hash +
     *     payload + payload_parse_status). Rehash canonical_bytes,
     *     compare to current_hash. On mismatch → emit a structured
     *     Log::error('chain_verification_failed', ...) and return
     *     `{tampered: true, payload: null}`. On match → return
     *     `{tampered: false, payload: <parsed-payload-or-null>}`.
     *
     * SECURITY-DESIGN: The structured log entries below are explicit
     * about what's loggable. canonical_bytes content and full payload
     * dicts are NEVER logged — they contain transactional details
     * (amounts, customer ids, payment instruments). Only IDs, hashes,
     * and tamper diagnostics flow to Log::error.
     *
     * @param  'sale'|'voided'|'return'  $bucket
     * @return array{tampered: bool, payload: array<string, mixed>|null, reason: string|null, expected_hash: string|null, actual_hash: string|null}
     */
    private function verifyCanonicalBytesAndLoadPayload(Receipt $receipt, string $bucket): array
    {
        if ($receipt->fiscal_event_id === null) {
            return ['tampered' => false, 'payload' => null, 'reason' => null, 'expected_hash' => null, 'actual_hash' => null];
        }

        $row = $this->db->table('fiscal_events')
            ->where('id', $receipt->fiscal_event_id)
            ->first(['canonical_bytes', 'current_hash', 'payload', 'payload_parse_status']);

        if ($row === null) {
            // The fiscal_events row vanished — projection orphaned. Log
            // diagnostic, treat as tampered (cannot prove integrity).
            Log::error('chain_verification_failed', [
                'bucket' => $bucket,
                'receipt_id' => $receipt->id,
                'fiscal_event_id' => $receipt->fiscal_event_id,
                'terminal_id' => $receipt->terminal_id,
                'company_id' => $receipt->company_id,
                'failure_mode' => 'fiscal_event_missing',
            ]);

            return [
                'tampered' => true,
                'payload' => null,
                'reason' => 'fiscal_event_missing',
                'expected_hash' => null,
                'actual_hash' => null,
            ];
        }

        $canonicalBytes = $row->canonical_bytes;
        if ($canonicalBytes === null || $canonicalBytes === '') {
            // Empty canonical_bytes is impossible per the migration
            // (NOT NULL + the ingestor never writes empty), but defend
            // explicitly so we don't silently hash sha256('').
            Log::error('chain_verification_failed', [
                'bucket' => $bucket,
                'receipt_id' => $receipt->id,
                'fiscal_event_id' => $receipt->fiscal_event_id,
                'failure_mode' => 'canonical_bytes_empty',
            ]);

            return [
                'tampered' => true,
                'payload' => null,
                'reason' => 'canonical_bytes_empty',
                'expected_hash' => null,
                'actual_hash' => null,
            ];
        }

        $canonicalBytesStr = $this->stringifyCanonicalBytes($canonicalBytes);
        $rehashed = strtolower($this->integrityProvider->computeHash($canonicalBytesStr));
        $stored = strtolower((string) $row->current_hash);

        if (! hash_equals($rehashed, $stored)) {
            // §5.0 D1 contract: compare against fiscal_events.current_hash
            // (the authoritative chain head), NOT pos_receipts.fiscal_hash
            // (the projection mirror).
            Log::error('chain_verification_failed', [
                'bucket' => $bucket,
                'receipt_id' => $receipt->id,
                'fiscal_event_id' => $receipt->fiscal_event_id,
                'terminal_id' => $receipt->terminal_id,
                'company_id' => $receipt->company_id,
                'expected_hash' => $stored,
                'actual_hash' => $rehashed,
                'failure_mode' => 'canonical_bytes_rehash_mismatch',
            ]);

            return [
                'tampered' => true,
                'payload' => null,
                'reason' => 'canonical_bytes_rehash_mismatch',
                'expected_hash' => $stored,
                'actual_hash' => $rehashed,
            ];
        }

        // Verified. Load the authoritative payload if the strict parser
        // has run — `payload_parse_status === 'parsed'` per spec §7.6.
        // For legacy rows or events still pending parse, return null
        // and the map functions fall back to mirror values.
        $parseStatus = (string) ($row->payload_parse_status ?? '');
        $payload = null;
        if ($parseStatus === 'parsed') {
            $payloadRaw = $row->payload;
            if (is_array($payloadRaw)) {
                /** @var array<string, mixed> $payloadRaw */
                $payload = $payloadRaw;
            } elseif (is_string($payloadRaw) && $payloadRaw !== '') {
                $decoded = json_decode($payloadRaw, true);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    $payload = $decoded;
                }
            }
        }

        return [
            'tampered' => false,
            'payload' => $payload,
            'reason' => null,
            'expected_hash' => $stored,
            'actual_hash' => $rehashed,
        ];
    }

    /**
     * Render a tamper diagnostic entry for the export's `tamperedSection`.
     * Auditors see the receipt id + the failure mode + the linked
     * fiscal_event_id — enough to triage without leaking canonical_bytes.
     *
     * @param  array{tampered: bool, payload: array<string, mixed>|null, reason: string|null, expected_hash: string|null, actual_hash: string|null}  $verifyResult
     * @return array<string, mixed>
     */
    private function renderTamperedEntry(Receipt $receipt, array $verifyResult, string $bucket): array
    {
        return [
            'receipt_id' => (string) $receipt->id,
            'receipt_number' => (string) $receipt->receipt_number,
            'fiscal_event_id' => (string) $receipt->fiscal_event_id,
            'terminal_id' => (string) $receipt->terminal_id,
            'bucket' => $bucket,
            'failure_mode' => $verifyResult['reason'] ?? 'unknown',
            'expected_hash' => $verifyResult['expected_hash'],
            'actual_hash' => $verifyResult['actual_hash'],
            'posted_at' => $receipt->posted_at->toIso8601String(),
        ];
    }

    /**
     * Phase 1 §8 (Task 30 round-3 T30-R2-P3): collect non-admissible
     * envelopes scoped to (company, period). Reader-side surface — the
     * JET XML builder emits these in the `<EvenementsQuarantaine>`
     * section only when non-empty.
     *
     * Spec §8 requires the export reconciliation to span BOTH quarantine
     * surfaces ("never silent exclusion ... export reconciliation must
     * span both quarantine surfaces"):
     *   1. `fiscal_event_quarantine` table — non-admissible envelopes
     *      (sequence_conflict, malformed_envelope). Source-tagged
     *      `quarantine_table`.
     *   2. `fiscal_events` rows that landed admissible but were flagged
     *      AT INGESTION as quarantined or parse-failed
     *      (integrity_status='quarantined' OR
     *      payload_parse_status='failed'). Source-tagged
     *      `fiscal_events_in_table`.
     *
     * Filter:
     *   - tenant scoping is implicit via the company FK (the company
     *     row owns the tenant_id; tenant isolation upstream rejects
     *     cross-tenant company_ids).
     *   - period filter: `server_received_at` between [from, to]
     *     inclusive at day boundaries — same window semantics as the
     *     rest of the export.
     *
     * @return list<array<string, mixed>>
     */
    private function buildQuarantineSection(string $companyId, Carbon $from, Carbon $to): array
    {
        $section = [];

        // (1) fiscal_event_quarantine — non-admissible partition.
        $quarantineRows = $this->db->table('fiscal_event_quarantine')
            ->where('company_id', $companyId)
            ->whereBetween('server_received_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('claimed_sequence_number')
            ->get([
                'envelope_event_id',
                'terminal_id',
                'claimed_sequence_number',
                'integrity_exception_class',
                'integrity_exception_reason',
                'server_received_at',
                'raw_envelope',
                'resolved_at',
                'previous_hash',
                'current_hash',
                'event_type',
                'event_version',
                'signature_version',
                'business_date',
                'payload_parse_status',
            ]);

        foreach ($quarantineRows as $row) {
            $section[] = [
                'source' => 'quarantine_table',
                'envelope_id' => (string) $row->envelope_event_id,
                'terminal_id' => (string) $row->terminal_id,
                'claimed_sequence_number' => (int) $row->claimed_sequence_number,
                'integrity_exception_class' => (string) $row->integrity_exception_class,
                'integrity_reason' => (string) $row->integrity_exception_reason,
                'server_received_at' => $this->normalizeTimestamp($row->server_received_at),
                'business_date' => $row->business_date === null
                    ? null
                    : $this->normalizeBusinessDate($row->business_date),
                'event_type' => (string) ($row->event_type ?? ''),
                'event_version' => (int) ($row->event_version ?? 0),
                'signature_version' => (string) ($row->signature_version ?? ''),
                'previous_hash' => (string) $row->previous_hash,
                'current_hash' => (string) $row->current_hash,
                'payload_parse_status' => $row->payload_parse_status === null
                    ? null
                    : (string) $row->payload_parse_status,
                'integrity_status' => 'quarantined', // partition by construction
                'raw_envelope' => $this->decodeRawEnvelope($row->raw_envelope),
                'resolved_at' => $row->resolved_at === null
                    ? null
                    : $this->normalizeTimestamp($row->resolved_at),
            ];
        }

        // (2) fiscal_events with integrity_status='quarantined' OR
        // payload_parse_status='failed' — verified non-admissible per
        // spec §8 ("In-table 'integrity_status=quarantined' covers
        // admitted-but-flagged events"). These landed in the chain but
        // cannot be projected; auditors need visibility.
        $inTableRows = $this->db->table('fiscal_events')
            ->where('company_id', $companyId)
            ->whereBetween('server_received_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(function ($q): void {
                $q->where('integrity_status', '<>', 'verified')
                    ->orWhere('payload_parse_status', 'failed');
            })
            ->orderBy('sequence_number')
            ->get([
                'id',
                'terminal_id',
                'sequence_number',
                'integrity_exception_class',
                'integrity_exception_reason',
                'integrity_status',
                'server_received_at',
                'business_date',
                'event_type',
                'event_version',
                'signature_version',
                'previous_hash',
                'current_hash',
                'payload_parse_status',
            ]);

        foreach ($inTableRows as $row) {
            $section[] = [
                'source' => 'fiscal_events_in_table',
                'envelope_id' => (string) $row->id,
                'terminal_id' => (string) $row->terminal_id,
                'claimed_sequence_number' => (int) $row->sequence_number,
                'integrity_exception_class' => (string) ($row->integrity_exception_class ?? ''),
                'integrity_reason' => (string) ($row->integrity_exception_reason ?? ''),
                'integrity_status' => (string) ($row->integrity_status ?? ''),
                'server_received_at' => $this->normalizeTimestamp($row->server_received_at),
                'business_date' => $row->business_date === null
                    ? null
                    : $this->normalizeBusinessDate($row->business_date),
                'event_type' => (string) ($row->event_type ?? ''),
                'event_version' => (int) ($row->event_version ?? 0),
                'signature_version' => (string) ($row->signature_version ?? ''),
                'previous_hash' => (string) $row->previous_hash,
                'current_hash' => (string) $row->current_hash,
                'payload_parse_status' => (string) ($row->payload_parse_status ?? ''),
                // In-table rows have NO raw_envelope — canonical_bytes
                // is the verbatim record; we don't emit it here
                // (forensic surface is the dedicated diagnostic
                // command). Keep null for shape consistency.
                'raw_envelope' => null,
                'resolved_at' => null,
            ];
        }

        return $section;
    }

    /**
     * `business_date` is DATE on PG/SQLite. Coerce to YYYY-MM-DD.
     */
    private function normalizeBusinessDate(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }
        $s = (string) $value;
        if ($s === '') {
            return '';
        }

        return Carbon::parse($s)->toDateString();
    }

    /**
     * `canonical_bytes` is BYTEA on PG (driver-typed string or resource)
     * and BLOB on SQLite (string). Coerce both shapes to a PHP string.
     */
    private function stringifyCanonicalBytes(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
    }

    /**
     * Decode `raw_envelope` from JSON storage. The column is `jsonb` on
     * PG (auto-decoded by some drivers, returned as a JSON string by
     * others) and `text` on SQLite. Normalize to an array for the export
     * shape; fall back to the raw string on parse failure so auditors
     * still see *something*.
     *
     * @return array<string, mixed>|string
     */
    private function decodeRawEnvelope(mixed $value): array|string
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }

            return $value;
        }

        return [];
    }

    /**
     * Normalize a DB-driver timestamp value to an ISO-8601 string.
     * Carbon::parse handles both string + Carbon inputs.
     */
    private function normalizeTimestamp(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
