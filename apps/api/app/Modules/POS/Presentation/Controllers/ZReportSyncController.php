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
            'opening_cash' => ['required', 'numeric'],
            'expected_cash' => ['required', 'numeric'],
            'receipt_snapshots' => ['present', 'array'],
            'grand_totals' => ['present', 'array'],

            // ── Schema v2 fields ──────────────────────────────────────────────
            'cash_counts' => ['sometimes', 'array'],
            'cash_counts.*.payment_method_id' => ['required_with:cash_counts', 'uuid'],
            'cash_counts.*.denomination_id' => ['sometimes', 'nullable', 'uuid'],
            'cash_counts.*.counted_quantity' => ['required_with:cash_counts', 'integer', 'min:0'],
            'cash_counts.*.counted_amount' => ['required_with:cash_counts', 'string'],
            'cash_counts.*.denomination_name' => ['sometimes', 'nullable', 'string'],
            'cash_counts.*.denomination_value' => ['sometimes', 'nullable', 'string'],

            'shift_fields' => ['sometimes', 'nullable', 'array'],
            'shift_fields.variance_reason' => ['sometimes', 'nullable', 'string'],
            'shift_fields.variance_severity' => ['sometimes', 'nullable', 'string', Rule::in(['none', 'low', 'medium', 'high', 'critical', 'info', 'warning'])],
            'shift_fields.actual_cash' => ['sometimes', 'nullable', 'string'],
            'shift_fields.variance_amount' => ['sometimes', 'nullable', 'string'],

            'manager_user_id' => ['sometimes', 'nullable', 'uuid'],

            'tolerance_summary' => ['sometimes', 'nullable', 'array'],
            'tolerance_summary.writeoffCount' => ['sometimes', 'integer'],
            'tolerance_summary.totalAmount' => ['sometimes', 'string'],
            'tolerance_summary.currencyCode' => ['sometimes', 'string', 'size:3'],
        ]);

        $companyId = $this->companyContext->getCompanyId();

        // Verify terminal belongs to company
        /** @var Terminal $terminal */
        $terminal = Terminal::where('company_id', $companyId)
            ->findOrFail($validated['terminal_id']);

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
     * Aggregate denomination-level cash_counts by payment_method_id and build
     * CashCountBreakdownDTO instances that the repository can persist.
     *
     * Because the sync payload only carries the counted (actual) amount —
     * the expected amount is not transmitted as a separate per-method figure —
     * we store expected_amount = 0.0000 and derive variance = actual - 0.
     * The DB CHECK constraint (variance_amount = actual_amount - expected_amount)
     * is satisfied when expected_amount = 0 and variance_amount = actual_amount.
     *
     * @param  array<int, array<string, mixed>>  $cashCounts
     * @return array<int, CashCountBreakdownDTO>
     */
    private function buildBreakdownsFromSyncPayload(array $cashCounts): array
    {
        /** @var array<string, array{amount: numeric-string, qty: int}> $perMethod */
        $perMethod = [];

        foreach ($cashCounts as $entry) {
            $methodId = (string) $entry['payment_method_id'];
            /** @var numeric-string $rawAmount */
            $rawAmount = is_numeric($entry['counted_amount'] ?? '0')
                ? (string) ($entry['counted_amount'] ?? '0')
                : '0';
            $qty = (int) ($entry['counted_quantity'] ?? 0);

            if (! isset($perMethod[$methodId])) {
                $perMethod[$methodId] = ['amount' => '0.0000', 'qty' => 0];
            }

            /** @var numeric-string $runningAmount */
            $runningAmount = $perMethod[$methodId]['amount'];
            $perMethod[$methodId]['amount'] = bcadd($runningAmount, $rawAmount, 4);
            $perMethod[$methodId]['qty'] += $qty;
        }

        $breakdowns = [];

        foreach ($perMethod as $methodId => $totals) {
            /** @var numeric-string $actualAmount */
            $actualAmount = $totals['amount'];
            $expectedAmount = '0.0000';
            // variance = actual - expected = actual - 0 (satisfies DB CHECK)
            $varianceAmount = $actualAmount;

            $breakdowns[] = new CashCountBreakdownDTO(
                paymentMethodId: $methodId,
                currencyCode: 'XXX', // currency not transmitted per-method in sync payload
                expectedAmount: $expectedAmount,
                actualAmount: $actualAmount,
                varianceAmount: $varianceAmount,
                varianceDirection: VarianceDirection::fromSignedAmount($varianceAmount),
                transactionCount: $totals['qty'],
            );
        }

        return $breakdowns;
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
