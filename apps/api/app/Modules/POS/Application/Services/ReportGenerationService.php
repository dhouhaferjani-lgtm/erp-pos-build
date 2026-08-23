<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Application\Services\TaxIdentityResolver;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\DTOs\CashCountValidationResultDTO;
use App\Modules\POS\Application\DTOs\RefundVatDisclosureData;
use App\Modules\POS\Application\DTOs\RefundVatDisclosureRowData;
use App\Modules\POS\Application\Exceptions\CashCountValidationException;
use App\Modules\POS\Application\Exceptions\UnauthorizedManagerException;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\POS\Domain\Events\ZReportGenerated;
use App\Modules\POS\Domain\Exceptions\ServerFiscalAuthoringRetiredException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\GrandtotalService;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\XReport;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Infrastructure\Repositories\ZReportCountRepository;
use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use NumberFormatter;

/**
 * Application service for generating POS reports.
 *
 * Handles:
 * - X Reports: Mid-shift snapshots (non-destructive, read-only)
 * - Z Reports: End-of-day closings (destructive, closes shift, creates grand totals)
 *   Optionally accepts per-tender cash-count inputs (PR-2 cash-counting cluster).
 */
final class ReportGenerationService
{
    public function __construct(
        private readonly ShiftManagementService $shiftManagementService,
        private readonly CashDrawerService $cashDrawerService,
        private readonly ZReportHashService $zReportHashService,
        private readonly GrandtotalService $grandtotalService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly CashCountValidationService $cashCountValidationService,
        private readonly FraudSettingsResolver $fraudSettingsResolver,
        private readonly ZReportCountRepository $zReportCountRepository,
        private readonly PaymentToleranceQueryService $paymentToleranceQueryService,
        private readonly TaxIdentityResolver $taxIdentityResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Absolute value of a decimal string, bcmath only — no float ever touches
     * money (rule 19). Normalises return-receipt amounts across the two refund
     * sign eras (legacy negative, v4 positive).
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function magnitude(string $value): string
    {
        return bccomp($value, '0', $this->scale()) < 0
            ? bcsub('0', $value, $this->scale())
            : $value;
    }

    private function assertServerReportAuthoringAllowed(Terminal $terminal, string $operation): void
    {
        if ((int) ($terminal->fiscal_schema_version ?? 2) >= 3) {
            throw ServerFiscalAuthoringRetiredException::zSessionDeviceAuthority($terminal->id, $operation);
        }
    }

    /**
     * Generate X report (mid-shift snapshot)
     *
     * X reports are non-destructive snapshots that can be generated multiple times
     * during a shift. They show cumulative totals from shift opening.
     *
     * Business Rules:
     * - Can be generated multiple times per shift
     * - Does NOT close the shift
     * - Does NOT affect grand totals
     * - No hash chain (not fiscally critical)
     * - Requires an open shift
     *
     * @param  Terminal  $terminal  The terminal to generate report for
     * @param  User  $generatedBy  User generating the report
     *
     * @throws ShiftNotOpenException If no open shift exists
     */
    public function generateXReport(
        Terminal $terminal,
        User $generatedBy
    ): XReport {
        $this->assertServerReportAuthoringAllowed($terminal, 'X_REPORT');

        // Get current open shift
        $shift = $this->shiftManagementService->getCurrentShift($terminal);

        if (! $shift) {
            throw ShiftNotOpenException::noOpenShift($terminal->id);
        }

        // Calculate snapshot data
        $snapshotData = $this->calculateShiftTotals($terminal, $shift->opened_at, now());

        // Create X report
        return XReport::create([
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'generated_by' => $generatedBy->id,
            'snapshot_data' => $snapshotData,
            'generated_at' => now(),
        ]);
    }

    /**
     * Generate Z report (end-of-day closing)
     *
     * Z reports are fiscally critical closings that:
     * - Close the current shift
     * - Create a GRANDTOTAL_DAILY event
     * - Are hash chained (previous_z_hash)
     * - Have sequential z_number that never resets
     *
     * Business Rules:
     * - Can only be generated once per shift (idempotent: re-call returns existing with was_reused=true)
     * - MUST have an open shift
     * - Sequential z_number never resets
     * - Hash chained to previous Z report
     *
     * Cash-count flow (when $cashCountInputs is non-null):
     * - Validates per-tender amounts via CashCountValidationService (tier: info/warning/critical)
     * - Enforces $varianceReason when severity > Info
     * - Enforces $managerOverrideBy (caller has pre-verified the PIN) when severity = Critical
     *   AND require_manager_pin_above_hard is true on company fraud settings
     * - Stamps report_data.schema_version = 2 for deterministic hashing
     * - Persists pos_z_report_counts rows per tender
     * - Updates pos_shifts metadata (blind_count_used, manager_override_by, variance_severity, notes)
     * - Fires CashCountRecorded event after commit
     *
     * Backwards compatibility: legacy callers pass only ($terminal, $generatedBy) and the
     * cash-count branch is fully skipped.
     *
     * @param  Terminal  $terminal  The terminal to generate report for
     * @param  User  $generatedBy  User generating the report
     * @param  array<int, CashCountInputDTO>|null  $cashCountInputs  Optional per-tender counted amounts
     * @param  string|null  $varianceReason  Required when computed severity > Info
     * @param  string|null  $managerOverrideBy  User id of the manager whose PIN authorised a Critical variance
     * @param  bool  $blindCountUsed  Whether the cashier counted without seeing the expected total
     *
     * @throws ShiftNotOpenException If no open shift exists
     * @throws CashCountValidationException If validation fails or required gates aren't satisfied
     * @throws UnauthorizedManagerException If $managerOverrideBy doesn't carry pos.close_shift_with_variance
     */
    public function generateZReport(
        Terminal $terminal,
        User $generatedBy,
        ?array $cashCountInputs = null,
        ?string $varianceReason = null,
        ?string $managerOverrideBy = null,
        bool $blindCountUsed = false,
    ): ZReport {
        $this->assertServerReportAuthoringAllowed($terminal, 'Z_REPORT');

        return DB::transaction(function () use (
            $terminal,
            $generatedBy,
            $cashCountInputs,
            $varianceReason,
            $managerOverrideBy,
            $blindCountUsed,
        ) {
            // Get current open shift
            $shift = $this->shiftManagementService->getCurrentShift($terminal);

            if (! $shift) {
                throw ShiftNotOpenException::noOpenShift($terminal->id);
            }

            // Idempotency guard: if a Z report already exists for this shift, return it.
            // We deliberately skip ALL cash-count persistence on the second call so callers
            // can safely retry without producing duplicate pos_z_report_counts rows.
            $existing = ZReport::query()->where('shift_id', $shift->id)->first();
            if ($existing !== null) {
                $existing->setAttribute('was_reused', true);

                return $existing;
            }

            // Determine schema version for this Z report based on terminal's fiscal_schema_version.
            // Terminals at v3 emit the full sale/return/voucher split; v2 and below use the
            // existing cash-count path.
            $terminalSchemaVersion = (int) ($terminal->fiscal_schema_version ?? 2);

            // Calculate base shift totals (sales, VAT, payment-type aggregates).
            $reportData = $this->calculateShiftTotals($terminal, $shift->opened_at, now(), $terminalSchemaVersion);

            // Add cash drawer information.
            $reportData['opening_cash'] = $shift->opening_cash;
            $reportData['expected_cash'] = $this->cashDrawerService->calculateExpectedCash($shift);
            $reportData['variance'] = null;  // Will be set when shift is closed

            // Cash-count branch.
            $validation = null;
            $perTenderWithCounts = [];
            $currencyCode = $this->resolveCurrencyCode($terminal);

            // For v3 terminals without cash-count input, stamp schema_version=3 so the
            // hash normalizer picks up the new monetary keys (refunds_amount, vouchers_*).
            if ($terminalSchemaVersion >= 3 && $cashCountInputs === null) {
                $reportData['schema_version'] = 3;
            }

            if ($cashCountInputs !== null) {
                $expectedPerMethod = $this->buildExpectedPerMethod($shift, $cashCountInputs);
                $transactionCounts = $this->buildTransactionCountsPerMethod($shift, $cashCountInputs);

                $settings = $this->fraudSettingsResolver->forCompany($terminal->company_id);

                $validation = $this->cashCountValidationService->validate(
                    $cashCountInputs,
                    $settings,
                    $currencyCode,
                    $expectedPerMethod,
                );

                if (! $validation->isValid()) {
                    throw CashCountValidationException::fromValidationErrors($validation->errors);
                }

                // Enforce orchestration gates.
                if ($validation->needsReason && trim((string) $varianceReason) === '') {
                    throw CashCountValidationException::missingRequirement(
                        code: 'variance_reason_required',
                        field: 'variance_reason',
                        message: 'A variance reason is required when the computed severity is above Info.',
                    );
                }

                if ($validation->needsManagerPin && $managerOverrideBy === null) {
                    throw CashCountValidationException::missingRequirement(
                        code: 'manager_pin_required',
                        field: 'manager_override_by',
                        message: 'Manager PIN authorisation is required for a Critical variance.',
                    );
                }

                // If a manager override is supplied, verify the manager belongs to the same tenant
                // and actually holds the pos.close_shift_with_variance permission.
                if ($managerOverrideBy !== null) {
                    $this->assertManagerCanOverride($managerOverrideBy, $terminal->tenant_id);
                }

                // Stamp transaction counts onto the per-tender breakdowns.
                $perTenderWithCounts = $this->stampTransactionCounts($validation->perTender, $transactionCounts);

                // Stamp cash-count blocks onto report_data.
                // Use schema_version=3 for v3 terminals so the hash normalizer processes
                // the new refunds_amount / vouchers_* keys.
                $reportData['schema_version'] = $terminalSchemaVersion >= 3 ? 3 : 2;
                $reportData['cash_counts'] = array_map(
                    fn (CashCountBreakdownDTO $b): array => [
                        'payment_method_id' => $b->paymentMethodId,
                        'currency_code' => $b->currencyCode,
                        'expected_amount' => $b->expectedAmount,
                        'actual_amount' => $b->actualAmount,
                        'variance_amount' => $b->varianceAmount,
                        'variance_direction' => $b->varianceDirection->value,
                        'transaction_count' => $b->transactionCount,
                    ],
                    $perTenderWithCounts,
                );
                $reportData['variance_summary'] = [
                    'aggregate_amount' => $validation->aggregateVariance->amount,
                    'aggregate_direction' => $validation->aggregateVariance->direction()->value,
                    'severity' => $validation->severity->value,
                    'currency_code' => $currencyCode,
                ];
                // Live tolerance summary from the public Treasury query service (Phase 2 / Task 12).
                // Key shape MUST match the TolerancePaymentTotalsDTO contract (v1.1) exactly:
                // camelCase totalAmount/currencyCode/writeoffCount, with totalAmount as a scale-3
                // decimal STRING. Adding/renaming keys would break every existing Z-report hash.
                // For shifts with zero writeoffs the DTO emits totalAmount='0.000' and
                // writeoffCount=0 — bit-for-bit identical to the Phase-1 placeholder, so the
                // pre-wiring hash is preserved (forward-compat verified by hash-replay test).
                $toleranceTotals = $this->paymentToleranceQueryService->totalForShift($shift->id);
                $reportData['tolerance_summary'] = [
                    'totalAmount' => $toleranceTotals->totalAmount,
                    'currencyCode' => $toleranceTotals->currencyCode,
                    'writeoffCount' => $toleranceTotals->writeoffCount,
                ];
            }

            // Get next Z number and previous Z hash.
            $zNumber = $this->zReportHashService->getNextZNumber($terminal);
            $previousZHash = $this->zReportHashService->getPreviousZHash($terminal);

            // Extend the prior Z's grand_totals with this shift's numbers so that
            // `pullZChainState` has a non-null payload for online-only tenants.
            // Without this, offline POS clients pulling Z-chain state would clobber
            // their locally-accumulated cumulative counters with zeros.
            $grandTotals = $this->computeGrandTotals($terminal, $reportData);

            // Create Z report (without hash initially)
            $zReport = new ZReport([
                'terminal_id' => $terminal->id,
                'shift_id' => $shift->id,
                'z_number' => $zNumber,
                'previous_z_hash' => $previousZHash,
                'report_data' => $reportData,
                'grand_totals' => $grandTotals,
                'generated_by' => $generatedBy->id,
                'generated_at' => now(),
            ]);

            // Calculate fiscal hash.
            $zReport->fiscal_hash = $this->zReportHashService->calculateHash($zReport, $previousZHash);

            // Save Z report.
            $zReport->save();

            // Create GRANDTOTAL_DAILY event.
            $this->grandtotalService->createGrandtotalEvent(
                $terminal,
                'DAILY',
                $shift->opened_at,
                now(),
                $generatedBy
            );

            // Note: Shift will be closed separately via ShiftManagementService::closeShift()
            // Z report generation and shift closing are separate operations to allow
            // for actual cash counting after Z report is printed.

            // Persist per-tender cash-count rows + update shift metadata.
            if ($validation !== null) {
                $this->zReportCountRepository->createMany($zReport->id, $perTenderWithCounts);
                $this->updateShiftWithCashCount(
                    $shift,
                    $perTenderWithCounts,
                    $validation,
                    $blindCountUsed,
                    $managerOverrideBy,
                    $varianceReason,
                );
            }

            /** @var ZReport $freshReport */
            $freshReport = $zReport->fresh();
            $freshReport->setAttribute('was_reused', false);

            DB::afterCommit(function () use ($freshReport, $terminal): void {
                event(new ZReportGenerated(
                    zReportId: $freshReport->id,
                    companyId: $terminal->company_id,
                    terminalId: $terminal->id,
                    zNumber: $freshReport->z_number,
                    fiscalHash: $freshReport->fiscal_hash,
                    generatedAt: $freshReport->generated_at->toIso8601String(),
                ));
            });

            if ($validation !== null) {
                $cashierId = $generatedBy->id;
                $cashierName = $generatedBy->name;
                $tenantId = $terminal->tenant_id;
                $companyId = $terminal->company_id;
                $terminalCode = $terminal->code;
                $zNumber = $freshReport->z_number;

                // Resolve manager name once, inside the transaction, so we have it for the event.
                $managerName = null;
                if ($managerOverrideBy !== null) {
                    /** @var User|null $managerUser */
                    $managerUser = User::find($managerOverrideBy);
                    $managerName = $managerUser?->name;
                }

                DB::afterCommit(function () use (
                    $freshReport,
                    $shift,
                    $terminal,
                    $tenantId,
                    $companyId,
                    $cashierId,
                    $cashierName,
                    $managerOverrideBy,
                    $managerName,
                    $blindCountUsed,
                    $currencyCode,
                    $terminalCode,
                    $zNumber,
                    $validation,
                    $perTenderWithCounts,
                ): void {
                    event(new CashCountRecorded(
                        zReportId: $freshReport->id,
                        shiftId: $shift->id,
                        terminalId: $terminal->id,
                        tenantId: $tenantId,
                        companyId: $companyId,
                        cashierId: $cashierId,
                        managerOverrideBy: $managerOverrideBy,
                        blindCountUsed: $blindCountUsed,
                        currencyCode: $currencyCode,
                        aggregateVariance: $validation->aggregateVariance,
                        varianceDirection: $validation->aggregateVariance->direction(),
                        severity: $validation->severity,
                        tenderBreakdown: $perTenderWithCounts,
                        descriptionCode: 'pos.cash_count.'.$validation->severity->value,
                        descriptionParams: [
                            'severity' => $validation->severity->value,
                            'aggregate_amount' => $validation->aggregateVariance->amount,
                            'currency_code' => $currencyCode,
                            'cashier_name' => $cashierName,
                            'manager_name' => $managerName,
                            'terminal_code' => $terminalCode,
                            'z_number' => $zNumber,
                        ],
                        recordedAt: now()->toIso8601String(),
                    ));
                });
            }

            return $freshReport;
        });
    }

    /**
     * Generate PDF for a Z report.
     *
     * @param  ZReport  $zReport  The Z report to generate PDF for
     */
    public function generatePdf(ZReport $zReport): DomPdf
    {
        $pdf = Pdf::loadView('pos.z-report', $this->viewDataFor($zReport));

        // A4 paper for Z reports (unlike thermal receipt)
        $pdf->setPaper('a4', 'portrait');

        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'DejaVu Sans');

        return $pdf;
    }

    /**
     * Get filename for the Z report PDF.
     */
    public function getZReportFilename(ZReport $zReport): string
    {
        return sprintf('z-report-%s.pdf', $zReport->getFormattedZNumber());
    }

    /**
     * Resolve the currency code that the cash-count submission is denominated in.
     * Falls back to 'EUR' if neither company nor scale resolver can produce one.
     */
    private function resolveCurrencyCode(Terminal $terminal): string
    {
        /** @var Company|null $company */
        $company = Company::find($terminal->company_id);

        if ($company !== null && $company->currency !== '') {
            return $company->currency;
        }

        return 'EUR';
    }

    /**
     * Sum receipt_payments.amount for the shift, grouped by payment_method_id.
     * Cash methods subtract receipt change_due once per receipt to mirror the POS device formula.
     * Always includes a row for every payment_method_id in $inputs (defaulting to '0.0000').
     *
     * Refund payout legs are SUBTRACTED. A v4 refund projects POSITIVE
     * `pos_receipt_payments.amount` rows under a `receipt_type = 'return'`
     * receipt (v3-refund-chain-integration spec §7.7) and no v3+ path writes a
     * `pos_cash_drawer_operations` REFUND row (ticket
     * 2026-07-31-cashdrawer-v3-expected-cash-blind), so this leg is the ONLY
     * record that cash left the drawer — blending it in would inflate expected
     * cash by the refund and hand the cashier a false overage. Legacy returns
     * wrote no receipt-payment rows at all (they routed through
     * PaymentRefundService / the cash drawer), so the return arm is a no-op on
     * legacy data and `-ABS(...)` is applied per row: a shift spanning both
     * eras stays correct. See ticket
     * 2026-08-01-positive-refund-total-consumers (hard pre-enable gate).
     *
     * Shift window driven by pos_receipts.posted_at — see REALIGNMENT-LOG 2026-04-26.
     *
     * @param  array<int, CashCountInputDTO>  $inputs
     * @return array<string, string> payment_method_id → scale-4 numeric-string
     *
     * @deprecated This is a takings-only server-authoring surface with no shipped client.
     *             Production is whole-drawer via the device. Retained only for the
     *             schema-v2 compatibility branch, reachable only by a direct legacy API
     *             caller, and its regression tests.
     *             Opening cash is shift-level, not per-tender: accurate schema, false
     *             business meaning. The defect is a missing join, not a wrong doctrine.
     *             This is the fourth reader to reach it; this annotation stops a fifth.
     */
    private function buildExpectedPerMethod(Shift $shift, array $inputs): array
    {
        $scale = 4;

        /** @var array<string, string> $totals */
        $totals = [];

        $rows = DB::table('pos_receipt_payments')
            ->join('pos_receipts', 'pos_receipt_payments.receipt_id', '=', 'pos_receipts.id')
            ->where('pos_receipts.terminal_id', $shift->terminal_id)
            ->where('pos_receipts.fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.is_training', false)
            ->whereBetween('pos_receipts.posted_at', [$shift->opened_at, now()])
            ->selectRaw("pos_receipt_payments.payment_method_id as payment_method_id, SUM(CASE WHEN pos_receipts.receipt_type = 'return' THEN -ABS(pos_receipt_payments.amount) ELSE pos_receipt_payments.amount END) as total")
            ->groupBy('pos_receipt_payments.payment_method_id')
            ->get();

        $cashReceiptChanges = DB::query()
            ->fromSub(
                DB::table('pos_receipt_payments')
                    ->join('pos_receipts', 'pos_receipt_payments.receipt_id', '=', 'pos_receipts.id')
                    ->where('pos_receipts.terminal_id', $shift->terminal_id)
                    ->where('pos_receipts.fiscal_status', FiscalStatus::Fiscalized->value)
                    ->where('pos_receipts.is_voided', false)
                    ->where('pos_receipts.is_training', false)
                    ->whereBetween('pos_receipts.posted_at', [$shift->opened_at, now()])
                    // Change-due is a SALE concept (money handed back on an
                    // over-tender). A refund's payout leg IS the cash that left
                    // the drawer, so a non-zero change_due on a return row
                    // (possible only via cash-rounding over-tender arithmetic)
                    // must not be subtracted a second time.
                    ->where('pos_receipts.receipt_type', '!=', ReceiptType::Return->value)
                    ->whereRaw('UPPER(pos_receipt_payments.payment_method_code) = ?', ['CASH'])
                    ->selectRaw('pos_receipt_payments.payment_method_id as payment_method_id, pos_receipts.id as receipt_id, MAX(COALESCE(pos_receipts.change_due, 0)) as change_due')
                    ->groupBy('pos_receipt_payments.payment_method_id', 'pos_receipts.id'),
                'cash_receipt_changes',
            )
            ->selectRaw('payment_method_id, SUM(change_due) as total_change_due')
            ->groupBy('payment_method_id')
            ->get()
            ->keyBy('payment_method_id');

        foreach ($rows as $row) {
            /** @var string $pmId */
            $pmId = $row->payment_method_id;
            /** @var string|float|int|null $rawTotal */
            $rawTotal = $row->total;
            /** @var numeric-string $totalString */
            $totalString = (string) ($rawTotal ?? '0');
            $total = bcadd($totalString, '0', $scale);

            $changeRow = $cashReceiptChanges->get($pmId);
            if ($changeRow !== null) {
                /** @var string|float|int|null $rawChangeDue */
                $rawChangeDue = $changeRow->total_change_due;
                $total = bcsub($total, $this->normaliseNumericString($rawChangeDue), $scale);
            }

            $totals[$pmId] = $total;
        }

        // Ensure every input has an entry (default '0.0000' if no receipts yet for that method).
        foreach ($inputs as $input) {
            if (! array_key_exists($input->paymentMethodId, $totals)) {
                $totals[$input->paymentMethodId] = '0.0000';
            }
        }

        return $totals;
    }

    /**
     * @return numeric-string
     */
    private function normaliseNumericString(string|int|float|null $value): string
    {
        if (! is_numeric($value)) {
            return '0';
        }

        return (string) $value;
    }

    /**
     * Count receipt_payments rows for the shift, grouped by payment_method_id.
     * Used to populate CashCountBreakdownDTO::transactionCount, which validation seeds with 0.
     *
     * Shift window driven by pos_receipts.posted_at — see REALIGNMENT-LOG 2026-04-26.
     *
     * @param  array<int, CashCountInputDTO>  $inputs
     * @return array<string, int>
     */
    private function buildTransactionCountsPerMethod(Shift $shift, array $inputs): array
    {
        /** @var array<string, int> $counts */
        $counts = [];

        $rows = DB::table('pos_receipt_payments')
            ->join('pos_receipts', 'pos_receipt_payments.receipt_id', '=', 'pos_receipts.id')
            ->where('pos_receipts.terminal_id', $shift->terminal_id)
            ->where('pos_receipts.fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.is_training', false)
            ->whereBetween('pos_receipts.posted_at', [$shift->opened_at, now()])
            ->selectRaw('pos_receipt_payments.payment_method_id as payment_method_id, COUNT(*) as cnt')
            ->groupBy('pos_receipt_payments.payment_method_id')
            ->get();

        foreach ($rows as $row) {
            /** @var string $pmId */
            $pmId = $row->payment_method_id;
            /** @var int|string|null $cnt */
            $cnt = $row->cnt;
            $counts[$pmId] = (int) ($cnt ?? 0);
        }

        foreach ($inputs as $input) {
            if (! array_key_exists($input->paymentMethodId, $counts)) {
                $counts[$input->paymentMethodId] = 0;
            }
        }

        return $counts;
    }

    /**
     * Re-build the per-tender breakdowns with non-zero transaction counts populated from the shift's
     * receipts. The validation service deliberately seeds them with 0 because it has no DB access.
     *
     * @param  array<int, CashCountBreakdownDTO>  $perTender
     * @param  array<string, int>  $transactionCounts
     * @return array<int, CashCountBreakdownDTO>
     */
    private function stampTransactionCounts(array $perTender, array $transactionCounts): array
    {
        return array_map(
            fn (CashCountBreakdownDTO $b): CashCountBreakdownDTO => new CashCountBreakdownDTO(
                paymentMethodId: $b->paymentMethodId,
                currencyCode: $b->currencyCode,
                expectedAmount: $b->expectedAmount,
                actualAmount: $b->actualAmount,
                varianceAmount: $b->varianceAmount,
                varianceDirection: $b->varianceDirection,
                transactionCount: $transactionCounts[$b->paymentMethodId] ?? 0,
            ),
            $perTender,
        );
    }

    /**
     * Update pos_shifts with cash-count metadata. Called only when cash counts were submitted.
     *
     * @param  array<int, CashCountBreakdownDTO>  $perTender
     */
    private function updateShiftWithCashCount(
        Shift $shift,
        array $perTender,
        CashCountValidationResultDTO $validation,
        bool $blindCountUsed,
        ?string $managerOverrideBy,
        ?string $varianceReason,
    ): void {
        $scale = 4;

        // Sum actual_amount across all (cash-like) tenders for shift.actual_cash. Validation has
        // already enforced that every input row references a physical (is_physical=true) method,
        // so every breakdown here is cash-like by construction.
        /** @var numeric-string $actualCash */
        $actualCash = '0.0000';
        foreach ($perTender as $b) {
            /** @var numeric-string $actualAmount */
            $actualAmount = $b->actualAmount;
            $actualCash = bcadd($actualCash, $actualAmount, $scale);
        }

        $updates = [
            'actual_cash' => $actualCash,
            'variance' => $validation->aggregateVariance->amount,
            'blind_count_used' => $blindCountUsed,
            'manager_override_by' => $managerOverrideBy,
            'variance_severity' => $validation->severity->value,
        ];

        if ($varianceReason !== null && trim($varianceReason) !== '') {
            $existingNotes = $shift->notes;
            $reasonLine = 'Variance reason: '.trim($varianceReason);
            $updates['notes'] = $existingNotes !== null && $existingNotes !== ''
                ? $existingNotes."\n".$reasonLine
                : $reasonLine;
        }

        $shift->update($updates);
    }

    /**
     * Verify the user supplied as $managerOverrideBy actually carries
     * pos.close_shift_with_variance within the correct tenant scope, and that the
     * manager belongs to the same tenant as the terminal being closed.
     *
     * Cross-tenant managers are rejected outright — a manager from a different tenant
     * must never be allowed to authorise a cash-count override on another tenant's terminal.
     * This check is simpler and more secure than relying on Spatie team-scope alone, because
     * the Spatie team id is set from the CURRENT USER (the cashier) via SetPermissionsTeam
     * middleware, which may not be the same as the manager's tenant in edge cases.
     *
     * @throws UnauthorizedManagerException
     */
    private function assertManagerCanOverride(string $managerOverrideBy, string $terminalTenantId): void
    {
        /** @var User|null $manager */
        $manager = User::find($managerOverrideBy);

        if ($manager === null) {
            throw UnauthorizedManagerException::notFound($managerOverrideBy);
        }

        if ($manager->tenant_id !== $terminalTenantId) {
            throw UnauthorizedManagerException::crossTenant($managerOverrideBy);
        }

        if (! $manager->can('pos.close_shift_with_variance')) {
            throw UnauthorizedManagerException::lacksPermission($managerOverrideBy);
        }
    }

    /**
     * Prepare data for the Z report PDF template.
     *
     * @return array<string, mixed>
     */
    /**
     * Prepare the Z-report view data, loading the relations the template and
     * tax-identity resolution need. Public so render paths (and tests) share
     * exactly the data the PDF is built from.
     *
     * @return array<string, mixed>
     */
    public function viewDataFor(ZReport $zReport): array
    {
        $zReport->load(['terminal.location', 'generatedBy']);

        /** @var Terminal $terminal */
        $terminal = $zReport->terminal;

        /** @var Company $company */
        $company = Company::findOrFail($terminal->company_id);

        return $this->preparePdfData($zReport, $company);
    }

    /**
     * DERIVED refund-VAT disclosure for a Z report (owner ruling B-6(ii),
     * Option A2). Display only — nothing here is signed, hashed, or persisted.
     *
     * WHY IT IS DERIVED RATHER THAN READ OFF THE Z. The signed payload cannot be
     * decomposed back into "sales VAT" and "refund VAT": `refunds_totals` carries
     * `{count, amount}` only (a gross TTC magnitude with no VAT split) and
     * `vat_breakdown` is ALREADY net. Adding a key to fix that would either fail
     * `ZReportPayload::PAYLOAD_KEYS` retroactively over the whole sealed corpus,
     * or change canonical bytes and the Z hash. So the split is recovered from
     * the PROJECTIONS instead.
     *
     * WHY THIS SOURCE. `pos_receipt_vat_details` joined to return receipts over
     * the Z's window is the EXACT source and predicate the VAT declaration's
     * OUTPUT arm uses (`EloquentVatDataRepository::aggregateByRateAndDirection`,
     * the POS sub-query) — same table, same `receipt_type = 'return'` selector,
     * same `is_voided` / `is_training` exclusions, same per-row `ABS()`
     * normalisation across the two refund sign eras. The declaration books this
     * figure NEGATIVE (`-ABS(...)`); the Z discloses the same magnitude
     * POSITIVE. The two therefore reconcile by construction rather than by
     * coincidence — which is the whole point of the ruling.
     *
     * WINDOW. `period_start`/`period_end` from `report_data` when the Z is
     * device-authored (v3 — `ZReportProjection::legacyReportData()` stamps them),
     * falling back to the shift's own `opened_at`/`closed_at` for the legacy
     * v1/v2 server-authored shape. Receipts carry no `shift_id`, so the
     * canonical shift→receipts derivation is terminal + `posted_at` window,
     * mirroring `calculateShiftTotals()` and `ZReportProjection::cashRoundingSummary()`.
     *
     * KNOWN WEDGES, stated rather than glossed:
     * - Rates are NOT filtered to `> 0` here (the declaration arm filters them):
     *   a Z's own table carries its rate-0 group and this disclosure has to line
     *   up with the table it sits under. The §3.1 identity against the
     *   declaration holds only for `r > 0`; the reconciliation test pins that
     *   restriction explicitly.
     * - The Z is TERMINAL-scoped, the declaration is COMPANY-scoped. Comparing
     *   the two means summing every terminal's Z over the period.
     * - The last-day-of-period boundary defect
     *   (`docs/superpowers/tickets/2026-08-21-vat-period-last-day-boundary.md`)
     *   is out of scope and untouched here.
     */
    public function refundVatDisclosureFor(ZReport $zReport): RefundVatDisclosureData
    {
        $reportData = $zReport->report_data;
        $scale = $this->scale();

        $salesVat = $this->numericOrZero($reportData['tax_amount'] ?? null);

        // The SIGNED, authoritative net figure — the one a declaration reads.
        $netVat = bcadd('0', '0', $scale);
        $breakdown = $reportData['vat_breakdown'] ?? [];
        if (is_array($breakdown)) {
            foreach ($breakdown as $row) {
                if (! is_array($row)) {
                    continue;
                }
                // Two key shapes are in the wild for the same field: the device
                // payload uses `vat_amount`, the legacy server shape `vat`. The
                // blade already reads both; so must this.
                $netVat = bcadd($netVat, $this->numericOrZero($row['vat_amount'] ?? $row['vat'] ?? null), $scale);
            }
        }

        [$windowStart, $windowEnd] = $this->disclosureWindowFor($zReport);

        $rows = [];
        $refundVat = bcadd('0', '0', $scale);

        if ($windowStart !== null && $windowEnd !== null) {
            $aggregated = DB::table('pos_receipt_vat_details as prvd')
                ->join('pos_receipts as r', 'prvd.receipt_id', '=', 'r.id')
                ->where('r.terminal_id', $zReport->terminal_id)
                ->whereBetween('r.posted_at', [$windowStart, $windowEnd])
                ->where('r.is_voided', false)
                ->where('r.is_training', false)
                ->where('r.receipt_type', ReceiptType::Return->value)
                ->selectRaw('
                    prvd.tax_rate as tax_rate,
                    SUM(ABS(prvd.net_amount)) as net_amount,
                    SUM(ABS(prvd.vat_amount)) as vat_amount,
                    SUM(ABS(prvd.gross_amount)) as gross_amount
                ')
                ->groupBy('prvd.tax_rate')
                ->orderBy('prvd.tax_rate')
                ->get();

            foreach ($aggregated as $row) {
                $vatAmount = $this->numericOrZero($row->vat_amount ?? null);
                $rows[] = new RefundVatDisclosureRowData(
                    // A tax RATE is a percentage, not money — it is never
                    // currency-scaled. `pos_receipt_vat_details.tax_rate` is
                    // decimal(5,2) and `VatAggregation::taxRate` is formatted at
                    // the same 2, which is what lets a caller key both sides of
                    // the §3.1 identity by the same string.
                    // precision-ok: percentage column scale, not a currency scale.
                    tax_rate: bcadd($this->numericOrZero($row->tax_rate ?? null), '0', 2),
                    net_amount: bcadd($this->numericOrZero($row->net_amount ?? null), '0', $scale),
                    vat_amount: bcadd($vatAmount, '0', $scale),
                    gross_amount: bcadd($this->numericOrZero($row->gross_amount ?? null), '0', $scale),
                );
                $refundVat = bcadd($refundVat, $vatAmount, $scale);
            }
        }

        return new RefundVatDisclosureData(
            rows: $rows,
            sales_vat: bcadd($salesVat, '0', $scale),
            refund_vat: $refundVat,
            net_vat: $netVat,
            has_refund_vat: bccomp($refundVat, '0', $scale) !== 0,
            is_reconciled: bccomp(bcsub($salesVat, $refundVat, $scale), $netVat, $scale) === 0,
        );
    }

    /**
     * Resolve the `posted_at` window a Z's derived disclosures are scoped to.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function disclosureWindowFor(ZReport $zReport): array
    {
        $reportData = $zReport->report_data;

        $start = $this->windowBoundary($reportData['period_start'] ?? null);
        $end = $this->windowBoundary($reportData['period_end'] ?? null);

        if ($start !== null && $end !== null) {
            return [$start, $end];
        }

        // Legacy v1/v2 server-authored Z: no period stamps in report_data.
        // `generated_at` closes the window for a shift still marked open (a Z
        // exists, so the shift is over even if `closed_at` was never written).
        //
        // Looked up rather than read off `$zReport->shift`: the relation is
        // declared non-nullable on the model, so a null check against it is
        // dead code to the analyser while still being reachable at runtime (a Z
        // whose shift row was never projected). `find()` is honestly `?Shift`.
        $shift = Shift::query()->find($zReport->shift_id);
        if ($shift === null) {
            return [null, null];
        }

        return [$shift->opened_at, $shift->closed_at ?? $zReport->generated_at];
    }

    private function windowBoundary(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Coerce a `report_data` entry or a query-aggregate column to a numeric
     * string for bcmath. Non-numeric (including null and the JSONB shapes that
     * legitimately omit a key) reads as '0' rather than throwing: this feeds
     * DISPLAY, and a missing key must degrade to a zero line, never a 500 on a
     * fiscal report.
     *
     * Scalars are stringified rather than rejected because the DB drivers
     * disagree about `SUM()`'s PHP type (pdo_pgsql returns a numeric string,
     * pdo_sqlite an int/float) — the same accommodation
     * `ZReportProjection::cashRoundingSummary()` makes. The value goes straight
     * into bcmath afterwards; no float arithmetic happens on it (rule 19).
     *
     * @return numeric-string
     */
    private function numericOrZero(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '0';
        }

        $string = is_bool($value) ? '0' : (string) $value;

        return is_numeric($string) ? $string : '0';
    }

    /**
     * @return array<string, mixed>
     */
    private function preparePdfData(ZReport $zReport, Company $company): array
    {
        $locale = $company->locale ?? 'en';
        $currency = $company->currency ?? 'EUR';
        $reportData = $zReport->report_data;

        // Resolve the seller tax identity from the terminal's establishment
        // (branch override → company fallback). A Z-report is scoped to a single
        // terminal/location (terminals.location_id is NOT NULL), so this is
        // unambiguous. Fiscal events seal SALE_RECEIPT seller identity from the
        // device; this is the render-time presentation of that establishment.
        $taxIdentity = $this->taxIdentityResolver->resolve($zReport->terminal->location);
        $salesCount = $reportData['sales_count'] ?? 0;
        $grossSales = $reportData['gross_sales'] ?? '0.00';

        $averageTicket = $salesCount > 0
            ? bcdiv($grossSales, (string) $salesCount, $this->scale())
            : '0.00';

        return [
            'zReport' => $zReport,
            'company' => $company,
            'terminal' => $zReport->terminal,
            'sellerTaxId' => $taxIdentity->taxId ?? $company->tax_id,
            'sellerTaxLabel' => $taxIdentity->taxIdLabel,
            'generatedByName' => $zReport->generatedBy->name,
            'reportData' => $reportData,
            'vatBreakdown' => $reportData['vat_breakdown'] ?? [],
            'paymentMethods' => $reportData['payment_methods'] ?? [],
            // B-6(ii)/A1+A2: the printed Z stops contradicting itself — the
            // sale-only headline is now labelled as such and shown beside the
            // derived refund VAT and the net figure the declaration reads.
            'refundVatDisclosure' => $this->refundVatDisclosureFor($zReport),
            'averageTicket' => $averageTicket,
            'hasVariance' => $zReport->hasVariance($this->scale()),
            'locale' => $locale,
            'currency' => $currency,
            'formatMoney' => fn (string|float|null $amount) => $this->formatMoney($amount, $currency, $locale),
            'formatDateTime' => fn (Carbon|string|null $date) => $this->formatDateTime($date, $locale),
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => $this->formatNumber($number, $decimals, $locale),
        ];
    }

    /**
     * Format money amount with currency.
     */
    private function formatMoney(string|float|null $amount, string $currency, string $locale): string
    {
        if ($amount === null) {
            return number_format(0, $this->scale(), '.', '');
        }

        $amount = is_string($amount) ? (float) $amount : $amount;

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $result = $formatter->formatCurrency($amount, $currency);

        return $result !== false ? $result : number_format($amount, $this->scale()).' '.$currency;
    }

    /**
     * Format date and time according to locale.
     */
    private function formatDateTime(Carbon|string|null $date, string $locale): string
    {
        if ($date === null) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        /** @var Carbon $localizedCarbon */
        $localizedCarbon = $carbon->locale($locale);

        return $localizedCarbon->isoFormat('L LT');
    }

    /**
     * Format number with locale-specific formatting.
     */
    private function formatNumber(string|float|null $number, int $decimals, string $locale): string
    {
        if ($number === null) {
            return '0';
        }

        $number = is_string($number) ? (float) $number : $number;

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);

        $result = $formatter->format($number);

        return $result !== false ? $result : number_format($number, $decimals);
    }

    /**
     * Compute cumulative grand totals by extending the previous Z-report's
     * grand_totals with this shift's numbers.
     *
     * Shape must match the `ZChainStateResponse` contract consumed by the
     * POS client (apps/pos/src/lib/sync/syncService.ts → pullZChainState).
     *
     * `cumulative_refunds` is a MAGNITUDE accumulator and the prior value is
     * normalised as it is read forward. Rationale, and why normalising here is
     * safe:
     * - This output is DERIVED, not sealed. `ZReportHashService::serializeForHashing()`
     *   hashes `z_number|terminal_id|generated_at|report_data_json` only —
     *   `grand_totals` is NOT a hash input, and no sealed row is rewritten.
     *   (The v3+ device path is different: there `grand_totals` comes from the
     *   SIGNED `grand_totals_after` payload key via ZReportProjection, but
     *   this method never runs for v3+ — server Z authoring is refused at
     *   `fiscal_schema_version >= 3`.)
     * - Every prior accumulator reachable here is <= 0: pre-release addends were
     *   sums of legacy NEGATIVE return totals, and v4 refunds cannot reach this
     *   path. So ABS converts a pure-legacy accumulator EXACTLY, and is
     *   idempotent on the positive values written from this release onward.
     * - `perpetual_grand_total` is deliberately NOT normalised: it is a NET
     *   figure that may legitimately be negative (refunds exceeding sales).
     *
     * Ruling: fix-forward, no backfill (no production tenant predates the
     * change; tenant #1 provisions fresh at v3). Past over-counts baked into a
     * prior `perpetual_grand_total` stay as they are — see the release note in
     * docs/sessions/LANE-C-wave4-report.md.
     *
     * @param  array<string, mixed>  $reportData  Output of calculateShiftTotals()
     * @return array{
     *     cumulative_sales: string,
     *     cumulative_tax: string,
     *     cumulative_refunds: string,
     *     perpetual_grand_total: string,
     *     receipt_count_lifetime: int,
     * }
     */
    private function computeGrandTotals(Terminal $terminal, array $reportData): array
    {
        $previous = ZReport::where('terminal_id', $terminal->id)
            ->orderByDesc('z_number')
            ->first();

        // Defensive numeric coercion — if a prior Z's grand_totals entry is ever
        // written with a non-numeric value (external sync, manual DB fix), bcadd
        // would emit a warning and silently return '0', zeroing the cumulative
        // chain. Reject explicitly instead.
        $priorSales = $this->priorNumeric($previous?->grand_totals['cumulative_sales'] ?? null);
        $priorTax = $this->priorNumeric($previous?->grand_totals['cumulative_tax'] ?? null);
        // Era normalisation (see the docblock): |legacy negative| == the same
        // magnitude the post-release convention accumulates.
        $priorRefunds = $this->magnitude($this->priorNumeric($previous?->grand_totals['cumulative_refunds'] ?? null));
        $priorPerpetual = $this->priorNumeric($previous?->grand_totals['perpetual_grand_total'] ?? null);
        $priorCount = (int) ($previous?->grand_totals['receipt_count_lifetime'] ?? 0);

        /** @var numeric-string $shiftSales */
        $shiftSales = (string) ($reportData['gross_sales'] ?? '0');
        /** @var numeric-string $shiftTax */
        $shiftTax = (string) ($reportData['tax_amount'] ?? '0');
        /** @var numeric-string $shiftRefunds */
        $shiftRefunds = (string) ($reportData['refunds_amount'] ?? '0');
        $shiftCount = (int) ($reportData['sales_count'] ?? 0);

        $scale = $this->scale();
        $netDelta = bcsub($shiftSales, $shiftRefunds, $scale);

        return [
            'cumulative_sales' => bcadd($priorSales, $shiftSales, $scale),
            'cumulative_tax' => bcadd($priorTax, $shiftTax, $scale),
            'cumulative_refunds' => bcadd($priorRefunds, $shiftRefunds, $scale),
            'perpetual_grand_total' => bcadd($priorPerpetual, $netDelta, $scale),
            'receipt_count_lifetime' => $priorCount + $shiftCount,
        ];
    }

    /**
     * Coerce a prior Z's grand_totals field to a numeric-string, treating null
     * and non-numeric as zero rather than silently feeding bad data into bcadd.
     *
     * @return numeric-string
     */
    private function priorNumeric(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $stringified = is_scalar($value) ? (string) $value : '';

        return is_numeric($stringified) ? $stringified : '0';
    }

    /**
     * Calculate shift totals for reports
     *
     * Aggregates all receipts in the time period.
     * Sale receipts (type=sale) contribute to gross_sales / net_sales / sales_count.
     * Return receipts (type=return) contribute to refunds_count / refunds_amount.
     * Voided receipts are counted but excluded from monetary totals.
     *
     * Two sign-era rules, both taken from the device Z contract that this
     * server-side aggregation must reproduce byte-for-byte in meaning:
     * - `refunds_amount` is a POSITIVE MAGNITUDE block, never sign-bearing and
     *   never folded into gross/net sales (spec §3.1/§7.3). It was summing
     *   `$receipt->total` raw, which is right for a v4 refund (positive) and
     *   wrong for a legacy return (negative) — and it feeds
     *   computeGrandTotals() as `gross − refunds`, so a legacy return used to
     *   ADD itself to `perpetual_grand_total`.
     * - `payment_methods` is NET of refund payout legs (spec §7.3, pinned by
     *   ReceiptReturnRefactorV3Test: "Z cash must be net of the refund payout,
     *   not gross"). Return receipts used to be skipped entirely here, so the
     *   printed Z showed CASH gross-of-refunds while the legacy takings-only
     *   reconciliation figure showed the net — two numbers on the same Z that
     *   disagreed by the refund.
     *
     * VAT breakdown is NET of refunds (B-6(ii) / Option A3, 2026-08-23). It used
     * to be SALE-ONLY here while the device authored a NET one, so the same
     * `vat_breakdown` key meant two different things depending on which terminal
     * wrote the Z. Refund rows are now normalised with magnitude()-then-subtract,
     * matching `EloquentVatDataRepository`'s `-ABS()` so the Z and the VAT
     * declaration reconcile by construction (§3.1). The headline `tax_amount`
     * remains SALE-ONLY by design — see the return branch's own comment.
     *
     * Shift window driven by pos_receipts.posted_at — see REALIGNMENT-LOG 2026-04-26.
     *
     * @param  Terminal  $terminal  The terminal to calculate for
     * @param  \Illuminate\Support\Carbon  $startTime  Period start
     * @param  \Illuminate\Support\Carbon  $endTime  Period end
     * @param  int  $schemaVersion  Report schema version (2 = cash-count, 3 = v3 with voucher counters)
     * @return array<string, mixed> Snapshot data array
     */
    private function calculateShiftTotals(Terminal $terminal, $startTime, $endTime, int $schemaVersion = 1): array
    {
        // Get all production receipts in period (exclude training).
        // Uses posted_at (the fiscal timestamp) as the canonical shift-window column
        // to match PaymentToleranceQueryService::shiftReceiptsQuery.
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->where('is_training', false)
            ->whereBetween('posted_at', [$startTime, $endTime])
            ->with(['vatDetails', 'payments'])
            ->get();

        $grossSales = '0.00';
        $netSales = '0.00';
        $taxAmount = '0.00';
        $salesCount = 0;
        $refundsCount = 0;
        $refundsAmount = '0.00';
        $voidedCount = 0;

        // VAT breakdown by rate
        $vatBreakdown = [];

        // Payment methods breakdown
        $paymentMethods = [];

        foreach ($receipts as $receipt) {
            if ($receipt->is_voided) {
                $voidedCount++;

                continue;
            }

            if ($receipt->receipt_type === ReceiptType::Return) {
                // Return receipts: their own POSITIVE-MAGNITUDE block (both
                // sign eras), never folded into gross/net sales.
                $refundsCount++;
                $refundsAmount = bcadd($refundsAmount, $this->magnitude($receipt->total), $this->scale());

                // B-6(ii) / Option A3 — but their VAT IS netted into the per-rate
                // table, because that table is the DECLARATION surface (§3.1) and
                // a refund reverses VAT already collected.
                //
                // Until this fix the loop below did not exist: a return `continue`d
                // straight past the sale branch's `vatDetails` aggregation, so a
                // LEGACY (v1/v2, server-authored) Z reported a SALE-ONLY per-rate
                // table while every DEVICE-authored Z reported a NET one
                // (`zReportService.ts:872-874`) — two incompatible meanings for the
                // same `vat_breakdown` key depending on which terminal wrote the Z,
                // and the §3.1 identity against `EloquentVatDataRepository` (whose
                // OUTPUT arm deducts return rows) failed on every legacy terminal
                // that took a return.
                //
                // `magnitude()`-then-SUBTRACT, never a bare `bcsub` of the raw row:
                // the two POS writers store OPPOSITE signs for the same refund —
                // `PosCoreReceiptProjection::writeVatBreakdown` mirrors the
                // canonical non-negative `vat_breakdown[]` (POSITIVE rows) while the
                // legacy `ReceiptReturnService::buildReturnLines` derives from a
                // negated line total (NEGATIVE rows). This is the same reason
                // `EloquentVatDataRepository.php:112-113` normalises with `-ABS()`
                // rather than `-`, and it is why this arm must not be "simplified"
                // into the sale branch's plain `bcadd`.
                //
                // The sale-only headline `tax_amount` is deliberately left
                // untouched (§3.2 wedge 6): it is never a declaration input, and
                // the refund VAT is exactly the wedge between it and this table —
                // which is what {@see self::refundVatDisclosureFor()} discloses.
                foreach ($receipt->vatDetails as $vatDetail) {
                    $rate = (string) $vatDetail->tax_rate;
                    if (! isset($vatBreakdown[$rate])) {
                        $vatBreakdown[$rate] = [
                            'tax_rate' => $vatDetail->tax_rate,
                            'net_amount' => '0.00',
                            'vat_amount' => '0.00',
                            'gross_amount' => '0.00',
                        ];
                    }
                    $vatBreakdown[$rate]['net_amount'] = bcsub($vatBreakdown[$rate]['net_amount'], $this->magnitude($vatDetail->net_amount), $this->scale());
                    $vatBreakdown[$rate]['vat_amount'] = bcsub($vatBreakdown[$rate]['vat_amount'], $this->magnitude($vatDetail->vat_amount), $this->scale());
                    $vatBreakdown[$rate]['gross_amount'] = bcsub($vatBreakdown[$rate]['gross_amount'], $this->magnitude($vatDetail->gross_amount), $this->scale());
                }

                // ... and their payout legs DO move the drawer, so the payment
                // breakdown is net of them.
                foreach ($receipt->payments as $payment) {
                    $method = $payment->payment_type;
                    if (! isset($paymentMethods[$method])) {
                        $paymentMethods[$method] = [
                            'payment_type' => $payment->payment_type,
                            'total_amount' => '0.00',
                            'transaction_count' => 0,
                        ];
                    }
                    $paymentMethods[$method]['total_amount'] = bcsub($paymentMethods[$method]['total_amount'], $this->magnitude($payment->amount), $this->scale());
                    $paymentMethods[$method]['transaction_count']++;
                }

                continue;
            }

            // Sale receipt (default path)
            $salesCount++;
            $grossSales = bcadd($grossSales, $receipt->total, $this->scale());
            $taxAmount = bcadd($taxAmount, $receipt->tax_amount, $this->scale());
            $netSales = bcadd($netSales, $receipt->subtotal, $this->scale());

            // Aggregate VAT breakdown
            foreach ($receipt->vatDetails as $vatDetail) {
                $rate = (string) $vatDetail->tax_rate;
                if (! isset($vatBreakdown[$rate])) {
                    $vatBreakdown[$rate] = [
                        'tax_rate' => $vatDetail->tax_rate,
                        'net_amount' => '0.00',
                        'vat_amount' => '0.00',
                        'gross_amount' => '0.00',
                    ];
                }
                $vatBreakdown[$rate]['net_amount'] = bcadd($vatBreakdown[$rate]['net_amount'], $vatDetail->net_amount, $this->scale());
                $vatBreakdown[$rate]['vat_amount'] = bcadd($vatBreakdown[$rate]['vat_amount'], $vatDetail->vat_amount, $this->scale());
                $vatBreakdown[$rate]['gross_amount'] = bcadd($vatBreakdown[$rate]['gross_amount'], $vatDetail->gross_amount, $this->scale());
            }

            // Aggregate payment methods
            foreach ($receipt->payments as $payment) {
                $method = $payment->payment_type;
                if (! isset($paymentMethods[$method])) {
                    $paymentMethods[$method] = [
                        'payment_type' => $payment->payment_type,
                        'total_amount' => '0.00',
                        'transaction_count' => 0,
                    ];
                }
                $paymentMethods[$method]['total_amount'] = bcadd($paymentMethods[$method]['total_amount'], $payment->amount, $this->scale());
                $paymentMethods[$method]['transaction_count']++;
            }
        }

        $result = [
            'sales_count' => $salesCount,
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'tax_amount' => $taxAmount,
            'refunds_count' => $refundsCount,
            'refunds_amount' => $refundsAmount,
            'voided_count' => $voidedCount,
            'vat_breakdown' => array_values($vatBreakdown),
            'payment_methods' => array_values($paymentMethods),
        ];

        // v3 reports include voucher counters from voucher_ledger for the window.
        if ($schemaVersion >= 3) {
            $voucherCounters = $this->calculateVoucherCounters($terminal, $startTime, $endTime);
            $result = array_merge($result, $voucherCounters);
        }

        return $result;
    }

    /**
     * Calculate voucher counters for the shift window from voucher_ledger.
     * Called only for schema_version >= 3 reports.
     *
     * @param  \Illuminate\Support\Carbon  $startTime
     * @param  \Illuminate\Support\Carbon  $endTime
     * @return array{
     *     vouchers_issued_count: int,
     *     vouchers_issued_amount: string,
     *     vouchers_redeemed_count: int,
     *     vouchers_redeemed_amount: string,
     * }
     */
    private function calculateVoucherCounters(Terminal $terminal, $startTime, $endTime): array
    {
        $issuedCount = 0;
        $issuedAmount = '0.00';
        $redeemedCount = 0;
        $redeemedAmount = '0.00';

        // Issued events: positive amount entries for this terminal in the window.
        // VoucherEvent::Issued has a positive amount (credit issued).
        $issuedRows = VoucherLedger::where('terminal_id', $terminal->id)
            ->whereIn('event', [VoucherEvent::Issued->value])
            ->whereBetween('occurred_at', [$startTime, $endTime])
            ->get(['amount']);

        foreach ($issuedRows as $row) {
            $issuedCount++;
            /** @var numeric-string $amount */
            $amount = (string) $row->amount;
            $issuedAmount = bcadd($issuedAmount, $amount, $this->scale());
        }

        // Redeemed events: negative amount entries for this terminal in the window.
        // Use absolute value for the counter.
        $redeemedRows = VoucherLedger::where('terminal_id', $terminal->id)
            ->whereIn('event', [VoucherEvent::Redeemed->value, VoucherEvent::PartiallyRedeemed->value])
            ->whereBetween('occurred_at', [$startTime, $endTime])
            ->get(['amount']);

        foreach ($redeemedRows as $row) {
            $redeemedCount++;
            /** @var numeric-string $amount */
            $amount = (string) $row->amount;
            // Redemption amounts are stored as negative; use absolute value for the report.
            $absAmount = bccomp($amount, '0', $this->scale()) < 0
                ? bcmul($amount, '-1', $this->scale())
                : $amount;
            $redeemedAmount = bcadd($redeemedAmount, $absAmount, $this->scale());
        }

        return [
            'vouchers_issued_count' => $issuedCount,
            'vouchers_issued_amount' => $issuedAmount,
            'vouchers_redeemed_count' => $redeemedCount,
            'vouchers_redeemed_amount' => $redeemedAmount,
        ];
    }
}
