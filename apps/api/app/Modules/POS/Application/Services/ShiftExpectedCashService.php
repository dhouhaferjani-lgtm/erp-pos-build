<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Application\Projections\ZSessionLifecycleProjection;
use App\Modules\POS\Domain\DTOs\ShiftExpectedCashBreakdown;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ShiftCashMovementSource;
use App\Modules\POS\Domain\Exceptions\UnsignableCashMovementException;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE server-side derivation of a shift's per-tender expected totals and of the
 * expected cash in its drawer.
 *
 * # Why this exists as a shared service (O-30 gate r1, CRITICAL 1)
 *
 * The per-tender query below used to live inline in
 * {@see ReportGenerationService::buildExpectedPerMethod()}, and the drawer
 * formula lived inline in {@see CashDrawerService::calculateExpectedCash()}.
 * When `pos:shift:close-orphaned` needed an expected-cash figure it grew a
 * THIRD copy, and that copy summed `pos_cash_drawer_operations` — a table a
 * device-authored (v3) shift never populates. The resulting number could not
 * include the day's cash takings or the device's own drops, and it was written
 * into `pos_shifts.expected_cash`, which `Nf525DataProvider::mapShift()` exports
 * to the NF525 JET as `EspecesAttendues` with `Ecart = 0` against the ORIGINAL
 * cashier. A wrong number in a certified export is not a labelling problem.
 *
 * One derivation, one place, so the orphan-close figure and the Z figure cannot
 * drift apart again.
 *
 * # The two movement sources are decided by the terminal, never by preference
 *
 * See {@see ShiftCashMovementSource}. A v3 shift's movements are fiscal events
 * in `pos_z_session_events`; a v2 shift's are `pos_cash_drawer_operations` rows.
 * Reading the wrong table returns a plausible number from an empty set, which
 * is precisely how the defect above stayed invisible.
 *
 * # The shift window
 *
 * `pos_receipts` carries no `shift_id`, so the receipt window is
 * `terminal_id` + `posted_at BETWEEN shift.opened_at AND $until` — the same
 * window `buildExpectedPerMethod()` has always used (REALIGNMENT-LOG
 * 2026-04-26). Movements are keyed by `shift_id` directly and need no window.
 */
final class ShiftExpectedCashService
{
    /**
     * `pos_z_session_events` rows that MOVE cash, and the direction each moves
     * it — mirroring the device, which is authoritative for a v3 drawer.
     *
     * `CASH_IN` is the device's `deposit` and `CASH_OUT` its `payout`
     * (`apps/pos/src/api/cashDrawerApi.ts:177`), summed by the device's own Z as
     * `deposit = +, payout = −` (`apps/pos/src/lib/offline/zReportService.ts:264-276`).
     * `SAFE_DROP` takes cash OUT of the drawer to the safe, matching the sign
     * `CashDrawerService` gives its `DEPOSIT` (a safe deposit) on the v2 side.
     *
     * `OPENING_FLOAT` is deliberately ABSENT: the float is read from
     * `pos_shifts.opening_cash`, and adding both would double it.
     *
     * `CASH_CORRECTION` is deliberately ABSENT TOO — it is the only movement
     * whose direction lives in the amount's sign rather than in its type, it has
     * no device authoring path today, and guessing a direction for a correction
     * would be the same class of silent error this service exists to end. It is
     * handled by {@see self::signedMovementAmount()} as an explicit refusal.
     *
     * @var array<string, string> movement type => '+' | '-'
     */
    private const MOVEMENT_DIRECTIONS = [
        'CASH_IN' => '+',
        'CASH_OUT' => '-',
        'SAFE_DROP' => '-',
    ];

    /**
     * `pos_cash_drawer_operations` types and their direction, identical to
     * {@see CashDrawerService::calculateExpectedCash()}
     * so a v2 orphan close and a v2 Z report agree by construction.
     *
     * `OPENING` is excluded here for the same reason as `OPENING_FLOAT` above
     * (the float comes from the shift row); `CLOSING` is the closing count, not
     * a movement, and an orphan has none by definition.
     *
     * @var array<string, string>
     */
    private const LEGACY_OPERATION_DIRECTIONS = [
        'SALE' => '+',
        'REFUND' => '-',
        'DEPOSIT' => '-',
        'PAYOUT' => '-',
    ];

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Expected total per payment method for the shift window, net of returns and
     * of cash change handed back.
     *
     * This is the exact query `ReportGenerationService` uses to validate a cash
     * count; it is here so the orphan-close path cannot use a different one.
     *
     * @return array<string, numeric-string> payment_method_id => total
     */
    public function expectedPerPaymentMethod(Shift $shift, ?CarbonInterface $until = null): array
    {
        $scale = 4;
        $until ??= Carbon::now();

        /** @var array<string, numeric-string> $totals */
        $totals = [];

        $rows = $this->shiftReceiptPaymentsQuery($shift, $until)
            ->selectRaw("pos_receipt_payments.payment_method_id as payment_method_id, SUM(CASE WHEN pos_receipts.receipt_type = 'return' THEN -ABS(pos_receipt_payments.amount) ELSE pos_receipt_payments.amount END) as total")
            ->groupBy('pos_receipt_payments.payment_method_id')
            ->get();

        $changeByMethod = $this->cashChangeDuePerPaymentMethod($shift, $until);

        foreach ($rows as $row) {
            /** @var string $pmId */
            $pmId = $row->payment_method_id;
            $total = bcadd($this->numeric($row->total), '0', $scale);

            if (array_key_exists($pmId, $changeByMethod)) {
                $total = bcsub($total, $changeByMethod[$pmId], $scale);
            }

            $totals[$pmId] = $total;
        }

        return $totals;
    }

    /**
     * Net CASH tendered on the shift's receipts — returns subtracted, change
     * handed back removed.
     *
     * Identified by `payment_method_code`, not by id: the code is what every
     * other cash-aware query in this module keys on (the id is per-company
     * data).
     *
     * @return numeric-string
     */
    public function cashTenderedNetOfChange(Shift $shift, CarbonInterface $until, int $scale): string
    {
        $tendered = $this->shiftReceiptPaymentsQuery($shift, $until)
            ->whereRaw('UPPER(pos_receipt_payments.payment_method_code) = ?', ['CASH'])
            ->selectRaw("SUM(CASE WHEN pos_receipts.receipt_type = 'return' THEN -ABS(pos_receipt_payments.amount) ELSE pos_receipt_payments.amount END) as total")
            ->value('total');

        $net = bcadd($this->numeric($tendered), '0', $scale);

        foreach ($this->cashChangeDuePerPaymentMethod($shift, $until) as $changeDue) {
            $net = bcsub($net, $changeDue, $scale);
        }

        return $net;
    }

    /**
     * Expected cash in the drawer, with every term it was built from.
     *
     * v3: opening float + net cash tendered on the shift's receipts + the signed
     * sum of its `pos_z_session_events` movements.
     * v2: opening float + the signed sum of its `pos_cash_drawer_operations`
     * (whose `SALE` rows ARE the v2 sales term), i.e. the figure
     * `CashDrawerService::calculateExpectedCash()` produces for the v2 Z report.
     *
     * @param  string  $currencyCode  REQUIRED, never resolved from CompanyContext:
     *                                callers include a console command run under
     *                                `tenants:run`, where no company is bound and a
     *                                bare `getScale()` throws (rule 19).
     *
     * @throws UnsignableCashMovementException when a movement row carries a type this service cannot sign
     */
    public function breakdown(
        Shift $shift,
        Terminal $terminal,
        string $currencyCode,
        ?CarbonInterface $until = null,
    ): ShiftExpectedCashBreakdown {
        $scale = $this->scaleResolver->getScale($currencyCode);
        $until ??= Carbon::now();

        // Intermediates one digit wider than the column; rounded once, at the
        // end (rule 19).
        $working = $scale + 1;

        $openingFloat = CurrencyScale::bcformatStrict((string) $shift->opening_cash, $working);

        $deviceAuthoritative = (int) ($terminal->fiscal_schema_version ?? 2) >= 3;

        if ($deviceAuthoritative) {
            $cashSales = $this->cashTenderedNetOfChange($shift, $until, $working);
            [$movementsNet, $movementCount] = $this->zSessionMovements($shift, $working);
            $source = ShiftCashMovementSource::ZSessionEvents;
        } else {
            $cashSales = CurrencyScale::bcformatStrict('0', $working);
            [$movementsNet, $movementCount] = $this->legacyDrawerMovements($shift, $working);
            $source = ShiftCashMovementSource::CashDrawerOperations;
        }

        $expected = bcadd(bcadd($openingFloat, $cashSales, $working), $movementsNet, $working);

        return new ShiftExpectedCashBreakdown(
            openingFloat: CurrencyScale::bcformatStrict($openingFloat, $scale),
            cashSales: CurrencyScale::bcformatStrict($cashSales, $scale),
            movementsNet: CurrencyScale::bcformatStrict($movementsNet, $scale),
            expectedCash: CurrencyScale::bcformatStrict($expected, $scale),
            movementSource: $source,
            movementCount: $movementCount,
            currencyCode: $currencyCode,
            scale: $scale,
        );
    }

    /**
     * The shift's fiscalized, non-void, non-training receipt payments.
     *
     * ONE builder factory behind both public reads, so the per-method totals and
     * the cash-only total cannot diverge in their filters — which is the whole
     * point of extracting this.
     */
    private function shiftReceiptPaymentsQuery(Shift $shift, CarbonInterface $until): Builder
    {
        return DB::table('pos_receipt_payments')
            ->join('pos_receipts', 'pos_receipt_payments.receipt_id', '=', 'pos_receipts.id')
            ->where('pos_receipts.terminal_id', $shift->terminal_id)
            ->where('pos_receipts.fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.is_training', false)
            ->whereBetween('pos_receipts.posted_at', [$shift->opened_at, $until]);
    }

    /**
     * Cash change handed back, per payment method.
     *
     * Aggregated per RECEIPT first (`MAX(change_due)` in the sub-select) because
     * `change_due` lives on the receipt, not the payment line: a receipt with
     * two cash lines would otherwise have its change counted twice.
     *
     * Change-due is a SALE concept — money handed back on an over-tender. A
     * refund's payout leg IS the cash that left the drawer, so a non-zero
     * `change_due` on a return row (reachable only through cash-rounding
     * over-tender arithmetic) must not be subtracted a second time.
     *
     * @return array<string, numeric-string>
     */
    private function cashChangeDuePerPaymentMethod(Shift $shift, CarbonInterface $until): array
    {
        $rows = DB::query()
            ->fromSub(
                $this->shiftReceiptPaymentsQuery($shift, $until)
                    ->where('pos_receipts.receipt_type', '!=', ReceiptType::Return->value)
                    ->whereRaw('UPPER(pos_receipt_payments.payment_method_code) = ?', ['CASH'])
                    ->selectRaw('pos_receipt_payments.payment_method_id as payment_method_id, pos_receipts.id as receipt_id, MAX(COALESCE(pos_receipts.change_due, 0)) as change_due')
                    ->groupBy('pos_receipt_payments.payment_method_id', 'pos_receipts.id'),
                'cash_receipt_changes',
            )
            ->selectRaw('payment_method_id, SUM(change_due) as total_change_due')
            ->groupBy('payment_method_id')
            ->get();

        /** @var array<string, numeric-string> $byMethod */
        $byMethod = [];
        foreach ($rows as $row) {
            /** @var string $pmId */
            $pmId = $row->payment_method_id;
            $byMethod[$pmId] = $this->numeric($row->total_change_due);
        }

        return $byMethod;
    }

    /**
     * v3 movements, from the canonical fiscal projection.
     *
     * Joined to `fiscal_events` on `integrity_status` rather than trusted on
     * sight: a row only reaches `pos_z_session_events` while its event is
     * Verified ({@see ZSessionLifecycleProjection::apply()}),
     * but an event can be quarantined AFTER projection, and a quarantined
     * movement must not move a number that lands in a certified export. Full
     * hash re-verification is `pos:verify-chains`' job, not a per-close cost.
     *
     * Training sessions are excluded by `chain_context`: their money is not real.
     *
     * @return array{0: numeric-string, 1: int}
     */
    private function zSessionMovements(Shift $shift, int $scale): array
    {
        $rows = DB::table('pos_z_session_events')
            ->join('fiscal_events', 'pos_z_session_events.fiscal_event_id', '=', 'fiscal_events.id')
            ->where('pos_z_session_events.shift_id', $shift->id)
            ->where('pos_z_session_events.chain_context', 'z_session')
            ->where('fiscal_events.integrity_status', 'verified')
            ->whereIn('pos_z_session_events.event_type', ['CASH_IN', 'CASH_OUT', 'SAFE_DROP', 'CASH_CORRECTION'])
            ->orderBy('pos_z_session_events.event_time_device')
            ->get(['pos_z_session_events.event_type as event_type', 'pos_z_session_events.payload as payload']);

        $net = CurrencyScale::bcformatStrict('0', $scale);
        $count = 0;

        foreach ($rows as $row) {
            $payload = $this->decodePayload($row->payload);
            $movementType = $payload['movement_type'] ?? $row->event_type;
            $amount = $payload['amount'] ?? null;

            if (! is_string($movementType) || ! is_string($amount) || ! is_numeric($amount)) {
                throw UnsignableCashMovementException::forIncompletePayload($shift->id);
            }

            if ($movementType === 'OPENING_FLOAT') {
                // The float is taken from pos_shifts.opening_cash; an
                // OPENING_FLOAT event carrying it again must not double it.
                continue;
            }

            $net = bcadd($net, $this->signedMovementAmount($shift->id, $movementType, $amount, $scale), $scale);
            $count++;
        }

        return [$net, $count];
    }

    /**
     * v2 movements, from the server-side drawer register.
     *
     * @return array{0: numeric-string, 1: int}
     */
    private function legacyDrawerMovements(Shift $shift, int $scale): array
    {
        $rows = DB::table('pos_cash_drawer_operations')
            ->where('shift_id', $shift->id)
            ->whereIn('operation_type', array_keys(self::LEGACY_OPERATION_DIRECTIONS))
            ->orderBy('created_at')
            ->get(['operation_type', 'amount']);

        $net = CurrencyScale::bcformatStrict('0', $scale);
        $count = 0;

        foreach ($rows as $row) {
            $amount = CurrencyScale::bcformatStrict((string) $row->amount, $scale);
            $net = self::LEGACY_OPERATION_DIRECTIONS[(string) $row->operation_type] === '+'
                ? bcadd($net, $amount, $scale)
                : bcsub($net, $amount, $scale);
            $count++;
        }

        return [$net, $count];
    }

    /**
     * Sign one v3 movement, or REFUSE.
     *
     * Fails closed on an unknown type deliberately. The alternative — treating
     * an unrecognised movement as zero — is exactly the failure mode this
     * service was written to end: a number that looks reasonable, is silently
     * short by whatever the unknown movement was worth, and is exported to a
     * certified JET as a balanced count.
     *
     * @return numeric-string
     */
    private function signedMovementAmount(string $shiftId, string $movementType, string $amount, int $scale): string
    {
        $magnitude = CurrencyScale::bcformatStrict($amount, $scale);

        if (! array_key_exists($movementType, self::MOVEMENT_DIRECTIONS)) {
            throw UnsignableCashMovementException::forShift(
                $shiftId,
                $movementType,
                implode(', ', array_keys(self::MOVEMENT_DIRECTIONS)),
            );
        }

        return self::MOVEMENT_DIRECTIONS[$movementType] === '+'
            ? $magnitude
            : bcsub(CurrencyScale::bcformatStrict('0', $scale), $magnitude, $scale);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return numeric-string
     */
    private function numeric(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
