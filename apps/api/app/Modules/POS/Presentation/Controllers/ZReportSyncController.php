<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Infrastructure\Repositories\ZReportCountRepository;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Handles Z-report synchronization from offline POS terminals.
 *
 * Accepts locally-generated Z-reports, verifies hash chain continuity,
 * and stores them in the server database for fiscal export.
 *
 * Schema v2 adds:
 *  - cash_counts[]        — per-denomination entries from the physical count
 *  - shift_fields         — aggregate variance metadata to stamp on pos_shifts
 *  - manager_user_id      — UUID of manager who approved the count
 *  - tolerance_summary    — write-off totals for the shift period
 */
final class ZReportSyncController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ZReportHashService $zReportHashService,
        private readonly ZReportCountRepository $zReportCountRepository,
    ) {}

    /**
     * Sync a Z-report from an offline terminal.
     *
     * POST /api/v1/pos/reports/z/sync
     *
     * Validates hash chain continuity and stores the Z-report.
     * Returns 201 on success, 409 on duplicate, 422 on chain break.
     */
    public function sync(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $identity = $request->validate([
            'terminal_id' => ['required', 'string', 'uuid'],
        ]);
        $companyId = $this->companyContext->getCompanyId();

        // Verify terminal belongs to company before running the legacy payload
        // validator. Cutover terminals sync canonical fiscal events through the
        // fiscal-event ingest path, not this legacy Z-report mirror endpoint.
        /** @var Terminal $terminal */
        $terminal = Terminal::where('company_id', $companyId)
            ->findOrFail($identity['terminal_id']);

        if ((int) ($terminal->fiscal_schema_version ?? 2) >= 3) {
            return response()->json([
                'error' => [
                    'code' => 'Z_SESSION_DEVICE_AUTHORITY_REQUIRED',
                    'message' => sprintf(
                        'Legacy Z-report sync is retired for cutover terminal %s. Sync device-authored Z-session fiscal events instead.',
                        $terminal->id,
                    ),
                ],
            ], 409);
        }

        $validated = $request->validate([
            // ── Core (v1) fields ──────────────────────────────────────────────
            'id' => ['required', 'string', 'uuid'],
            'terminal_id' => ['required', 'string', 'uuid'],
            'shift_id' => ['required', 'string', 'uuid'],
            'z_number' => ['required', 'integer', 'min:1'],
            'formatted_z_number' => ['required', 'string'],
            'generated_at' => ['required', 'string'],
            'fiscal_hash' => ['required', 'string', 'size:64'],
            'previous_hash' => ['required', 'string'],
            'hash_sequence' => ['required', 'integer', 'min:1'],
            'report_data' => ['required', 'array'],
            'opening_cash' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,4})?$/'],
            'expected_cash' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,4})?$/'],
            'receipt_snapshots' => ['present', 'array'],
            // INGRESS-ONLY scale ceilings (money 3dp). `numeric` keeps
            // well-formed device numeric-strings/numbers valid; the anchored
            // regex rejects an over-precise device value (422) so the POS app
            // learns what to fix instead of the server silently rounding it.
            // This does NOT touch hash/canonical-byte computation — receipt
            // snapshots are still archived verbatim (device authority).
            'receipt_snapshots.*.subtotal' => ['sometimes', 'numeric', 'regex:/^-?\d+(?:\.\d{1,3})?$/'],
            'receipt_snapshots.*.tax_amount' => ['sometimes', 'numeric', 'regex:/^-?\d+(?:\.\d{1,3})?$/'],
            'receipt_snapshots.*.total' => ['sometimes', 'numeric', 'regex:/^-?\d+(?:\.\d{1,3})?$/'],
            'receipt_snapshots.*.discount_amount' => ['sometimes', 'numeric', 'regex:/^-?\d+(?:\.\d{1,3})?$/'],
            'grand_totals' => ['present', 'array'],

            // ── Schema v2 fields ──────────────────────────────────────────────
            'cash_counts' => ['sometimes', 'array'],
            'cash_counts.*.payment_method_id' => ['required', 'uuid', 'distinct'],
            'cash_counts.*.denomination_id' => ['sometimes', 'nullable', 'uuid'],
            'cash_counts.*.counted_quantity' => ['sometimes', 'integer', 'min:0'],
            'cash_counts.*.counted_amount' => ['sometimes', 'string'],
            'cash_counts.*.denomination_name' => ['sometimes', 'nullable', 'string'],
            'cash_counts.*.denomination_value' => ['sometimes', 'nullable', 'string'],
            'cash_counts.*.currency_code' => ['required', 'string', 'size:3'],
            'cash_counts.*.expected_amount' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'cash_counts.*.actual_amount' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'cash_counts.*.variance_amount' => ['required', 'regex:/^-?\d+(?:\.\d{1,4})?$/'],
            'cash_counts.*.variance_direction' => ['required', 'string', Rule::in(['over', 'under', 'balanced'])],
            'cash_counts.*.transaction_count' => ['required', 'integer', 'min:0'],

            'shift_fields' => ['sometimes', 'nullable', 'array'],
            'shift_fields.variance_reason' => ['sometimes', 'nullable', 'string'],
            'shift_fields.variance_severity' => ['sometimes', 'nullable', 'string', Rule::in(['none', 'low', 'medium', 'high', 'critical', 'info', 'warning'])],
            'shift_fields.actual_cash' => ['sometimes', 'nullable', 'string'],
            'shift_fields.variance_amount' => ['sometimes', 'nullable', 'string'],

            'manager_user_id' => ['sometimes', 'nullable', 'uuid'],

            'tolerance_summary' => ['sometimes', 'nullable', 'array'],
            'tolerance_summary.writeoffCount' => ['sometimes', 'integer'],
            // Money 3dp ceiling (INGRESS-ONLY, stored verbatim into report_data
            // JSONB — does NOT affect hash computation).
            'tolerance_summary.totalAmount' => ['sometimes', 'string', 'regex:/^-?\d+(?:\.\d{1,3})?$/'],
            'tolerance_summary.currencyCode' => ['sometimes', 'string', 'size:3'],
        ]);

        /** @var array<int, array<string, mixed>> $cashCountsForValidation */
        $cashCountsForValidation = isset($validated['cash_counts']) && is_array($validated['cash_counts'])
            ? $validated['cash_counts']
            : [];
        $cashCountErrors = $this->validateCashCountBreakdownArithmetic($cashCountsForValidation);
        if ($cashCountErrors !== []) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'The synced cash-count breakdown is internally inconsistent.',
                    'errors' => $cashCountErrors,
                ],
            ], 422);
        }

        // Check for duplicate (idempotent sync)
        $existing = ZReport::forTerminal($terminal->id)
            ->byZNumber((int) $validated['z_number'])
            ->first();

        if ($existing !== null) {
            if ($existing->fiscal_hash === $validated['fiscal_hash']) {
                return response()->json([
                    'data' => [
                        'status' => 'duplicate',
                        'id' => $existing->id,
                    ],
                ], 200);
            }

            return response()->json([
                'error' => [
                    'code' => 'HASH_MISMATCH',
                    'message' => 'Z-report with same z_number exists but fiscal_hash differs.',
                ],
            ], 409);
        }

        // Verify hash chain continuity
        $previousZHash = $this->zReportHashService->getPreviousZHash($terminal);
        $expectedPrevious = $previousZHash ?? 'GENESIS';

        if ($validated['previous_hash'] !== $expectedPrevious) {
            return response()->json([
                'error' => [
                    'code' => 'CHAIN_BREAK',
                    'message' => 'Z-report previous_hash does not match server chain.',
                    'expected' => $expectedPrevious,
                    'received' => $validated['previous_hash'],
                ],
            ], 422);
        }

        // Merge tolerance_summary into report_data before storage so the
        // JSONB column carries the full schema-v2 content.
        /** @var array<string, mixed> $reportData */
        $reportData = (array) $validated['report_data'];
        if (isset($validated['tolerance_summary']) && is_array($validated['tolerance_summary'])) {
            $reportData['tolerance_summary'] = $validated['tolerance_summary'];
        }

        /** @var array<int, array<string, mixed>>|null $rawCashCounts */
        $rawCashCounts = isset($validated['cash_counts']) && is_array($validated['cash_counts'])
            ? $validated['cash_counts']
            : null;

        /** @var array<string, mixed>|null $shiftFields */
        $shiftFields = isset($validated['shift_fields']) && is_array($validated['shift_fields'])
            ? $validated['shift_fields']
            : null;

        /** @var string|null $managerUserId */
        $managerUserId = isset($validated['manager_user_id']) && is_string($validated['manager_user_id'])
            ? $validated['manager_user_id']
            : null;

        // Store Z-report within a transaction, then persist any schema-v2 extras.
        $zReport = DB::transaction(function () use ($validated, $terminal, $reportData, $rawCashCounts, $shiftFields, $managerUserId): ZReport {
            $zReport = ZReport::create([
                'id' => $validated['id'],
                'terminal_id' => $terminal->id,
                'shift_id' => $validated['shift_id'],
                'z_number' => $validated['z_number'],
                'fiscal_hash' => $validated['fiscal_hash'],
                'previous_z_hash' => $validated['previous_hash'] === 'GENESIS' ? null : $validated['previous_hash'],
                'report_data' => $reportData,
                'receipt_snapshots' => $validated['receipt_snapshots'],
                'grand_totals' => $validated['grand_totals'],
                'generated_by' => auth()->id(),
                'generated_at' => Carbon::parse($validated['generated_at']),
            ]);

            // ── Persist per-denomination cash counts ─────────────────────────
            if ($rawCashCounts !== null && $rawCashCounts !== []) {
                $breakdowns = $this->buildBreakdownsFromSyncPayload($rawCashCounts);
                $this->zReportCountRepository->createMany($zReport->id, $breakdowns);
            }

            // ── Stamp shift_fields onto pos_shifts ───────────────────────────
            if ($shiftFields !== null) {
                $this->applyShiftFields(
                    (string) $validated['shift_id'],
                    $shiftFields,
                    $managerUserId,
                );
            }

            return $zReport;
        });

        // ── Dispatch CashCountRecorded event (outside transaction) ────────────
        if ($rawCashCounts !== null && $rawCashCounts !== []) {
            $this->dispatchCashCountRecorded($zReport, $terminal, $validated, $rawCashCounts, $shiftFields, $managerUserId);
        }

        return response()->json([
            'data' => [
                'status' => 'synced',
                'id' => $zReport->id,
            ],
        ], 201);
    }

    /**
     * Build CashCountBreakdownDTO instances from the POS-device Z-report payload.
     * The offline POS is the source of truth for this path, so the server archives
     * each per-method breakdown verbatim instead of recomputing expected/variance.
     *
     * @param  array<int, array<string, mixed>>  $cashCounts
     * @return array<int, CashCountBreakdownDTO>
     */
    private function buildBreakdownsFromSyncPayload(array $cashCounts): array
    {
        $breakdowns = [];

        foreach ($cashCounts as $entry) {
            $expectedAmount = $this->normaliseNumericString($entry['expected_amount'] ?? null);
            $actualAmount = $this->normaliseNumericString($entry['actual_amount'] ?? null);
            $varianceAmount = $this->normaliseNumericString($entry['variance_amount'] ?? null);

            $breakdowns[] = new CashCountBreakdownDTO(
                paymentMethodId: (string) $entry['payment_method_id'],
                currencyCode: (string) $entry['currency_code'],
                expectedAmount: bcadd($expectedAmount, '0', 4),
                actualAmount: bcadd($actualAmount, '0', 4),
                varianceAmount: bcadd($varianceAmount, '0', 4),
                varianceDirection: VarianceDirection::from((string) $entry['variance_direction']),
                transactionCount: (int) $entry['transaction_count'],
            );
        }

        return $breakdowns;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cashCounts
     * @return array<string, list<string>>
     */
    private function validateCashCountBreakdownArithmetic(array $cashCounts): array
    {
        $errors = [];

        foreach ($cashCounts as $index => $entry) {
            $expectedAmount = $this->normaliseNumericString($entry['expected_amount'] ?? null);
            $actualAmount = $this->normaliseNumericString($entry['actual_amount'] ?? null);
            $varianceAmount = $this->normaliseNumericString($entry['variance_amount'] ?? null);
            $calculatedVariance = bcsub($actualAmount, $expectedAmount, 4);
            $normalisedVariance = bcadd($varianceAmount, '0', 4);

            if (bccomp($calculatedVariance, $normalisedVariance, 4) !== 0) {
                $errors["cash_counts.{$index}.variance_amount"] = [
                    'The variance_amount must equal actual_amount minus expected_amount.',
                ];
            }

            $direction = is_string($entry['variance_direction'] ?? null)
                ? $entry['variance_direction']
                : '';
            $expectedDirection = VarianceDirection::fromSignedAmount($calculatedVariance)->value;

            if ($direction !== $expectedDirection) {
                $errors["cash_counts.{$index}.variance_direction"] = [
                    'The variance_direction must match the sign of actual_amount minus expected_amount.',
                ];
            }
        }

        return $errors;
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
     * Apply shift_fields (and optional manager_user_id) to the pos_shifts row.
     *
     * @param  array<string, mixed>  $shiftFields
     */
    private function applyShiftFields(string $shiftId, array $shiftFields, ?string $managerUserId): void
    {
        $shift = Shift::find($shiftId);

        if (! $shift instanceof Shift) {
            return;
        }

        /** @var array<string, mixed> $updates */
        $updates = [];

        if (array_key_exists('actual_cash', $shiftFields) && $shiftFields['actual_cash'] !== null) {
            $updates['actual_cash'] = (string) $shiftFields['actual_cash'];
        }

        if (array_key_exists('variance_amount', $shiftFields) && $shiftFields['variance_amount'] !== null) {
            $updates['variance'] = (string) $shiftFields['variance_amount'];
        }

        if (array_key_exists('variance_severity', $shiftFields) && $shiftFields['variance_severity'] !== null) {
            $updates['variance_severity'] = $this->normaliseVarianceSeverity((string) $shiftFields['variance_severity']);
        }

        if (array_key_exists('variance_reason', $shiftFields) && $shiftFields['variance_reason'] !== null) {
            $existingNotes = is_string($shift->notes) ? $shift->notes : '';
            $separator = $existingNotes !== '' ? "\n" : '';
            $updates['notes'] = $existingNotes.$separator.'Variance reason: '.(string) $shiftFields['variance_reason'];
        }

        if ($managerUserId !== null) {
            $updates['manager_override_by'] = $managerUserId;
        }

        if ($updates !== []) {
            $shift->update($updates);
        }
    }

    /**
     * Normalise variance_severity from the POS (which may send 'none'/'low'/'medium'/'high'/'critical')
     * to the three-value set accepted by the DB CHECK constraint: 'info', 'warning', 'critical'.
     */
    private function normaliseVarianceSeverity(string $severity): string
    {
        return match ($severity) {
            'none', 'low' => VarianceSeverity::Info->value,
            'medium' => VarianceSeverity::Warning->value,
            'high',
            'critical' => VarianceSeverity::Critical->value,
            default => $severity, // 'info', 'warning', 'critical' pass through
        };
    }

    /**
     * Build and dispatch the CashCountRecorded domain event.
     *
     * Several fields required by the event (tenantId, companyId, currencyCode) are
     * loaded from the already-persisted ZReport / Terminal rather than re-passed
     * through the HTTP payload to avoid duplication of trusted data.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, array<string, mixed>>  $rawCashCounts
     * @param  array<string, mixed>|null  $shiftFields
     */
    private function dispatchCashCountRecorded(
        ZReport $zReport,
        Terminal $terminal,
        array $validated,
        array $rawCashCounts,
        ?array $shiftFields,
        ?string $managerUserId,
    ): void {
        /** @var numeric-string $varianceRaw */
        $varianceRaw = ($shiftFields !== null && isset($shiftFields['variance_amount']) && is_string($shiftFields['variance_amount']))
            ? $shiftFields['variance_amount']
            : '0.0000';

        $severityRaw = ($shiftFields !== null && isset($shiftFields['variance_severity']) && is_string($shiftFields['variance_severity']))
            ? $this->normaliseVarianceSeverity($shiftFields['variance_severity'])
            : VarianceSeverity::Info->value;

        $company = Company::find($terminal->company_id);
        $currencyCode = ($company instanceof Company) ? $company->currency : 'XXX';
        $tenantId = (string) $terminal->tenant_id;

        $aggregateVariance = new VarianceAmount(
            amount: $varianceRaw,
            currencyCode: $currencyCode,
        );

        $breakdowns = $this->buildBreakdownsFromSyncPayload($rawCashCounts);

        $severity = VarianceSeverity::tryFrom($severityRaw) ?? VarianceSeverity::Info;
        $varianceDirection = VarianceDirection::fromSignedAmount($varianceRaw);

        $cashierId = (string) (auth()->id() ?? $zReport->generated_by);

        Event::dispatch(new CashCountRecorded(
            zReportId: $zReport->id,
            shiftId: (string) $validated['shift_id'],
            terminalId: $terminal->id,
            tenantId: $tenantId,
            companyId: $terminal->company_id,
            cashierId: $cashierId,
            managerOverrideBy: $managerUserId,
            blindCountUsed: false,
            currencyCode: $currencyCode,
            aggregateVariance: $aggregateVariance,
            varianceDirection: $varianceDirection,
            severity: $severity,
            tenderBreakdown: $breakdowns,
            descriptionCode: 'pos.cash_count.'.$severity->value,
            descriptionParams: [
                'severity' => $severity->value,
                'aggregate_amount' => $aggregateVariance->abs(),
                'currency_code' => $currencyCode,
                'cashier_name' => null,
                'manager_name' => null,
                'terminal_code' => $terminal->code,
                'z_number' => $zReport->z_number,
            ],
            recordedAt: Carbon::parse($validated['generated_at'])->toIso8601String(),
        ));
    }
}
