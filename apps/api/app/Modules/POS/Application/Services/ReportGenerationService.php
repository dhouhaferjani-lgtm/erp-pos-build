<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\DTOs\CashCountValidationResultDTO;
use App\Modules\POS\Application\Exceptions\CashCountValidationException;
use App\Modules\POS\Application\Exceptions\UnauthorizedManagerException;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\POS\Domain\Events\ZReportGenerated;
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
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
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

            // Calculate base shift totals (sales, VAT, payment-type aggregates).
            $reportData = $this->calculateShiftTotals($terminal, $shift->opened_at, now());

            // Add cash drawer information.
            $reportData['opening_cash'] = $shift->opening_cash;
            $reportData['expected_cash'] = $this->cashDrawerService->calculateExpectedCash($shift);
            $reportData['variance'] = null;  // Will be set when shift is closed

            // Cash-count branch.
            $validation = null;
            $perTenderWithCounts = [];
            $currencyCode = $this->resolveCurrencyCode($terminal);

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
                $reportData['schema_version'] = 2;
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
        $zReport->load(['terminal', 'generatedBy']);

        /** @var Terminal $terminal */
        $terminal = $zReport->terminal;

        /** @var Company $company */
        $company = Company::findOrFail($terminal->company_id);

        $data = $this->preparePdfData($zReport, $company);

        $pdf = Pdf::loadView('pos.z-report', $data);

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
     * Always includes a row for every payment_method_id in $inputs (defaulting to '0.0000').
     *
     * Shift window driven by pos_receipts.posted_at — see REALIGNMENT-LOG 2026-04-26.
     *
     * @param  array<int, CashCountInputDTO>  $inputs
     * @return array<string, string> payment_method_id → scale-4 numeric-string
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
            ->selectRaw('pos_receipt_payments.payment_method_id as payment_method_id, SUM(pos_receipt_payments.amount) as total')
            ->groupBy('pos_receipt_payments.payment_method_id')
            ->get();

        foreach ($rows as $row) {
            /** @var string $pmId */
            $pmId = $row->payment_method_id;
            /** @var string|float|int|null $rawTotal */
            $rawTotal = $row->total;
            /** @var numeric-string $totalString */
            $totalString = (string) ($rawTotal ?? '0');
            $totals[$pmId] = bcadd($totalString, '0', $scale);
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
    private function preparePdfData(ZReport $zReport, Company $company): array
    {
        $locale = $company->locale ?? 'en';
        $currency = $company->currency ?? 'EUR';
        $reportData = $zReport->report_data;
        $salesCount = $reportData['sales_count'] ?? 0;
        $grossSales = $reportData['gross_sales'] ?? '0.00';

        $averageTicket = $salesCount > 0
            ? bcdiv($grossSales, (string) $salesCount, $this->scale())
            : '0.00';

        return [
            'zReport' => $zReport,
            'company' => $company,
            'terminal' => $zReport->terminal,
            'generatedByName' => $zReport->generatedBy->name,
            'reportData' => $reportData,
            'vatBreakdown' => $reportData['vat_breakdown'] ?? [],
            'paymentMethods' => $reportData['payment_methods'] ?? [],
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
        $priorRefunds = $this->priorNumeric($previous?->grand_totals['cumulative_refunds'] ?? null);
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
     *
     * Shift window driven by pos_receipts.posted_at — see REALIGNMENT-LOG 2026-04-26.
     *
     * @param  Terminal  $terminal  The terminal to calculate for
     * @param  \Illuminate\Support\Carbon  $startTime  Period start
     * @param  \Illuminate\Support\Carbon  $endTime  Period end
     * @return array<string, mixed> Snapshot data array
     */
    private function calculateShiftTotals(Terminal $terminal, $startTime, $endTime): array
    {
        // Get all production receipts in period (exclude training).
        // Uses posted_at (the fiscal timestamp) as the canonical shift-window column
        // to match PaymentToleranceQueryService::shiftReceiptsQuery.
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->where('is_training', false)
            ->whereBetween('posted_at', [$startTime, $endTime])
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

        return [
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
    }
}
