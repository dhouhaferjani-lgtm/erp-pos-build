<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\POS\Domain\Shift;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentBreakdownDTO;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentReceiptDTO;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentTotalsDTO;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PaymentToleranceQueryService
 *
 * Public Treasury surface for shift-level tolerance write-off reporting.
 * Consumed by the cash-counting Z-report to populate the `tolerance_summary`
 * block. Read-only — never mutates state, never raises events.
 *
 * Method signatures and DTO shapes are frozen as of Payment Tolerance
 * contract v1.1; non-breaking additions allowed without a bump.
 *
 * @see docs/superpowers/coordination/2026-04-24-payment-tolerance-shift-interface.md §"Public Treasury service"
 */
final class PaymentToleranceQueryService
{
    private const DEFAULT_CURRENCY = 'EUR';

    private const SCALE = 3;

    /**
     * Aggregate tolerance write-off totals for a shift.
     * Receipts with NULL or zero `tolerance_writeoff` are excluded.
     *
     * Time-window: posted_at BETWEEN shift.opened_at AND (closed_at OR now()).
     * When the shift is still open, the upper bound is wall-clock now — callers
     * needing a stable cutoff (Z-report consumers) should call only after the
     * shift has been closed. X-report-style intra-shift previews are reproducibility-
     * hostile by design and that is intentional.
     */
    public function totalForShift(string $shiftId): TolerancePaymentTotalsDTO
    {
        $shift = $this->loadShift($shiftId);

        if ($shift === null) {
            return new TolerancePaymentTotalsDTO(
                totalAmount: '0.000',
                currencyCode: self::DEFAULT_CURRENCY,
                writeoffCount: 0,
            );
        }

        // SUM/COUNT/MAX always return one row even with no matches, so $row is non-null.
        $row = (array) $this->shiftReceiptsQuery($shift)
            ->selectRaw('COALESCE(SUM(tolerance_writeoff), 0) AS total, COUNT(*) AS cnt, MAX(currency) AS currency')
            ->first();

        $total = $this->stringField($row, 'total', '0');
        $count = $this->intField($row, 'cnt', 0);
        $currency = $this->stringField($row, 'currency', self::DEFAULT_CURRENCY);

        return new TolerancePaymentTotalsDTO(
            totalAmount: $this->formatScaled($total),
            currencyCode: $currency === '' ? self::DEFAULT_CURRENCY : $currency,
            writeoffCount: $count,
        );
    }

    /**
     * Per-cashier breakdown of tolerance write-offs for a shift.
     * Grouped by (cashier, currency). Returns one DTO per group.
     *
     * Time-window: posted_at BETWEEN shift.opened_at AND (closed_at OR now()).
     * When the shift is still open, the upper bound is wall-clock now — callers
     * needing a stable cutoff (Z-report consumers) should call only after the
     * shift has been closed. X-report-style intra-shift previews are reproducibility-
     * hostile by design and that is intentional.
     *
     * @return array<int, TolerancePaymentBreakdownDTO>
     */
    public function breakdownForShift(string $shiftId): array
    {
        $shift = $this->loadShift($shiftId);

        if ($shift === null) {
            return [];
        }

        // NOTE: groupBy includes cashier_name (a denormalized snapshot per receipt)
        // so a mid-shift rename produces two rows for the same cashier_id. This is
        // intentional — preserves audit fidelity. Consumers wanting a collapsed view
        // should aggregate on userId client-side.
        $rows = $this->shiftReceiptsQuery($shift)
            ->groupBy('cashier_id', 'cashier_name', 'currency')
            ->selectRaw('cashier_id, cashier_name, currency, SUM(tolerance_writeoff) AS total, COUNT(*) AS cnt')
            ->get();

        return $rows->map(function (object $row): TolerancePaymentBreakdownDTO {
            $data = (array) $row;

            return new TolerancePaymentBreakdownDTO(
                userId: $this->stringField($data, 'cashier_id', ''),
                userName: $this->stringField($data, 'cashier_name', ''),
                totalAmount: $this->formatScaled($this->stringField($data, 'total', '0')),
                currencyCode: $this->stringField($data, 'currency', self::DEFAULT_CURRENCY),
                writeoffCount: $this->intField($data, 'cnt', 0),
            );
        })->all();
    }

    /**
     * Receipt-level drill-down: one DTO per receipt that has a non-zero
     * tolerance write-off, ordered by `posted_at` ascending.
     *
     * Shift window driven by pos_receipts.posted_at — see REALIGNMENT-LOG 2026-04-26.
     *
     * Time-window: posted_at BETWEEN shift.opened_at AND (closed_at OR now()).
     * When the shift is still open, the upper bound is wall-clock now — callers
     * needing a stable cutoff (Z-report consumers) should call only after the
     * shift has been closed. X-report-style intra-shift previews are reproducibility-
     * hostile by design and that is intentional.
     *
     * @return array<int, TolerancePaymentReceiptDTO>
     */
    public function receiptsWithToleranceForShift(string $shiftId): array
    {
        $shift = $this->loadShift($shiftId);

        if ($shift === null) {
            return [];
        }

        $rows = $this->shiftReceiptsQuery($shift)
            ->orderBy('posted_at')
            ->select([
                'receipt_number',
                'cashier_id',
                'cashier_name',
                'tolerance_writeoff',
                'currency',
                'posted_at',
            ])
            ->get();

        return $rows->map(function (object $row): TolerancePaymentReceiptDTO {
            $data = (array) $row;

            return new TolerancePaymentReceiptDTO(
                receiptNumber: $this->stringField($data, 'receipt_number', ''),
                userId: $this->stringField($data, 'cashier_id', ''),
                userName: $this->stringField($data, 'cashier_name', ''),
                writeoffAmount: $this->formatScaled($this->stringField($data, 'tolerance_writeoff', '0')),
                currencyCode: $this->stringField($data, 'currency', self::DEFAULT_CURRENCY),
                occurredAt: $this->toIso8601Utc($this->stringField($data, 'posted_at', '')),
            );
        })->all();
    }

    /**
     * Shared subquery: receipts in this shift's terminal+time window that
     * carry a non-zero tolerance write-off. Voided and training receipts
     * are excluded.
     *
     * Note: receipts have no `shift_id` FK; the canonical shift→receipts
     * derivation is by terminal + time-window.
     *
     * Time-window derivation: posted_at BETWEEN shift.opened_at AND closed_at.
     * Uses posted_at (the fiscal timestamp per Receipt model line 49), which is
     * the canonical shift-window column across all shift aggregators.
     * See REALIGNMENT-LOG 2026-04-26.
     *
     * Training filter: `is_training = false` matches every other receipt
     * aggregator in the codebase (ReportGenerationService::calculateShiftTotals,
     * VerifyPosChainCommand, GrandtotalService) — training-mode receipts must
     * never pollute production fiscal/tolerance reports.
     */
    /**
     * Does this shift contain a tolerance write-off the DEVICE could not have
     * attributed to a tender? — DPA lane G3, gate finding C2.
     *
     * The offline Z report is device-authoritative: the server archives the
     * device's `expected_amount` verbatim instead of recomputing it. The device
     * normally derives its cash expectation from the TENDERED amounts, which is
     * why a per-receipt tolerance write-off (Dr 658 / Cr ProductRevenue, no cash
     * leg) is already netted out of `expected` and cannot be re-booked by the
     * shift-close variance. Its LEGACY fallback branch, however, attributes
     * `receipt.total` when a receipt carries no per-payment breakdown — and for
     * a receipt with a non-zero `tolerance_writeoff`, `total != tendered`, so
     * `expected` is inflated by exactly the shortfall, an honest count reads
     * short, and the shift-variance entry would re-book that shortfall to 658.
     *
     * The server-visible shadow of that branch is precise: a shift receipt with
     * a non-zero `tolerance_writeoff` and NO `pos_receipt_payments` rows. This
     * predicate finds it so the Treasury listener can refuse to book (never
     * block) rather than post a double count. No device change is involved.
     *
     * Non-breaking addition to the frozen v1.1 surface (new method, no existing
     * signature or DTO touched).
     */
    public function hasUnattributableToleranceForShift(string $shiftId): bool
    {
        $shift = $this->loadShift($shiftId);

        if ($shift === null) {
            return false;
        }

        return $this->shiftReceiptsQuery($shift)
            ->whereNotExists(function (Builder $query): void {
                $query->select(DB::raw('1'))
                    ->from('pos_receipt_payments')
                    ->whereColumn('pos_receipt_payments.receipt_id', 'pos_receipts.id');
            })
            ->exists();
    }

    private function shiftReceiptsQuery(Shift $shift): Builder
    {
        // Closed shifts use the recorded close time; open shifts use now().
        $endTime = $shift->closed_at ?? Carbon::now();

        return DB::table('pos_receipts')
            ->where('terminal_id', $shift->terminal_id)
            ->whereBetween('posted_at', [$shift->opened_at, $endTime])
            ->where('is_voided', false)
            ->where('is_training', false)
            ->whereNotNull('tolerance_writeoff')
            ->where('tolerance_writeoff', '>', 0);
    }

    private function loadShift(string $shiftId): ?Shift
    {
        /** @var Shift|null $shift */
        $shift = Shift::query()->find($shiftId);

        return $shift;
    }

    /**
     * Coerce a raw DB row field to string with a default when missing or null.
     *
     * @param  array<string, mixed>  $row
     */
    private function stringField(array $row, string $key, string $default): string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Coerce a raw DB row field to int with a default when missing or null.
     *
     * @param  array<string, mixed>  $row
     */
    private function intField(array $row, string $key, int $default): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Normalise any numeric string to scale-3 decimal string. Falls back to
     * "0.000" if the input is not numeric (defensive — should not occur for
     * SUM aggregates which always return numeric).
     */
    private function formatScaled(string $value): string
    {
        if (! is_numeric($value)) {
            return '0.000';
        }

        /** @var numeric-string $value */
        return bcadd($value, '0', self::SCALE);
    }

    /**
     * Render a Carbon-parseable timestamp as an ISO-8601 UTC string
     * (e.g. "2026-04-25T14:32:11Z"). Matches the contract for
     * TolerancePaymentReceiptDTO::$occurredAt.
     */
    private function toIso8601Utc(string $postedAt): string
    {
        if ($postedAt === '') {
            return '';
        }

        return Carbon::parse($postedAt)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
