<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\POS\Application\Projections\ZSessionLifecycleProjection;
use App\Modules\POS\Domain\DTOs\ShiftExpectedCashBreakdown;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ShiftCashMovementSource;
use App\Modules\POS\Domain\Exceptions\UnattributableAccountCollectionException;
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
 * WHAT IS AND IS NOT SHARED, precisely — the earlier "one derivation, one place"
 * claim overstated it. Genuinely shared: {@see self::expectedPerPaymentMethod()},
 * which `ReportGenerationService` now calls for its cash-count validation, so the
 * per-tender figures cannot drift. NOT shared: `ReportGenerationService` still
 * takes its `expected_cash` from {@see CashDrawerService::calculateExpectedCash()},
 * and that is harmless ONLY because `generateZReport()` refuses a v3 terminal
 * outright (`assertServerReportAuthoringAllowed`) — so the v3 arm below has no
 * second consumer to agree with. If that refusal is ever lifted, the two MUST be
 * unified first; do not read this file as evidence that they already are.
 *
 * # The two movement sources are decided by the terminal, never by preference
 *
 * See {@see ShiftCashMovementSource}. A v3 shift's movements are fiscal events
 * in `pos_z_session_events`; a v2 shift's are `pos_cash_drawer_operations` rows.
 * Reading the wrong table returns a plausible number from an empty set, which
 * is precisely how the defect above stayed invisible.
 *
 * # The shift window, and why `$until` is not optional in practice (gate r2)
 *
 * `pos_receipts` carries no `shift_id`, so the receipt window is
 * `terminal_id` + `posted_at BETWEEN shift.opened_at AND $until` — the same
 * window `buildExpectedPerMethod()` has always used (REALIGNMENT-LOG
 * 2026-04-26). Z-session movements are keyed by `shift_id` and need no window.
 *
 * For a LIVE shift `$until = now()` is right, which is why it is the default and
 * why `ReportGenerationService` passes nothing. For an ORPHANED shift it is
 * badly wrong: nothing stops a REPLACEMENT device claiming the terminal and
 * selling for weeks while the orphan sits OPEN — `pos_receipts` has no shift
 * column and `PosCoreReceiptProjection` never looks at `pos_shifts`, so the
 * replacement's `SALE_RECEIPT` events project normally even while its
 * `SESSION_OPEN` is being dropped. An unbounded window sweeps another till's
 * takings into the orphan's `expected_cash`, which the JET then exports as
 * `EspecesAttendues` with `Ecart = 0` against the ORIGINAL cashier. So
 * `pos:shift:close-orphaned` passes the authorising release's `occurred_at`:
 * after a forced release the orphan's device no longer holds the terminal, and
 * `posted_at` is DEVICE time, so the dead device's late-synced receipts still
 * fall inside while the replacement's never do.
 */
final class ShiftExpectedCashService
{
    /**
     * The `z_session` fiscal event types that MOVE cash. Enum cases, not string
     * literals (rule 9) — `event_type` is a `FiscalEventType` column and the
     * four names below drift silently as bare strings.
     *
     * `CASH_CORRECTION` is included in the READ so the derivation SEES it and
     * can refuse; it is deliberately absent from {@see self::movementDirection()},
     * which is what turns seeing it into a refusal rather than a silent zero.
     *
     * @var list<FiscalEventType>
     */
    private const CASH_MOVEMENT_EVENT_TYPES = [
        FiscalEventType::CASH_IN,
        FiscalEventType::CASH_OUT,
        FiscalEventType::SAFE_DROP,
        FiscalEventType::CASH_CORRECTION,
    ];

    /**
     * The live (non-training) z-session chain.
     *
     * A bare string because `chain_context` has NO enum anywhere in this
     * codebase — `ZSessionLifecycleProjection::apply()` and
     * `FiscalPayloadConstraintValidator` both compare it as a literal. Named
     * here so this file has one definition rather than three, and so the day an
     * enum arrives there is one line to change.
     */
    private const LIVE_Z_SESSION_CHAIN = 'z_session';

    /**
     * The subset of {@see self::CASH_MOVEMENT_EVENT_TYPES} this server can sign.
     * Named for the refusal message, so an operator is told what IS known.
     *
     * @var list<FiscalEventType>
     */
    private const SIGNABLE_MOVEMENT_TYPES = [
        FiscalEventType::CASH_IN,
        FiscalEventType::CASH_OUT,
        FiscalEventType::SAFE_DROP,
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
     * sum of its `pos_z_session_events` movements + cash collected against
     * customer accounts — the device's own four terms
     * (`apps/pos/src/lib/offline/zReportService.ts:277-289`), minus the fifth
     * (`cashRefundImpact`), which reads a device-LOCAL table written only by the
     * legacy refund path and has no server mirror; a v4 return is already netted
     * out of the receipts term.
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
     * @throws UnattributableAccountCollectionException when an account collection cannot be attributed to a shift
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
            [$accountCollections, $collectionCount] = $this->cashAccountCollections($shift, $terminal, $until, $working);
            $source = ShiftCashMovementSource::ZSessionEvents;
        } else {
            // A v2 terminal has no ACCOUNT_PAYMENT authoring path — that is a v3
            // device fiscal event — so there is no collection term to add, and
            // its SALE drawer rows already carry its sales.
            $cashSales = CurrencyScale::bcformatStrict('0', $working);
            [$movementsNet, $movementCount] = $this->legacyDrawerMovements($shift, $working);
            $accountCollections = CurrencyScale::bcformatStrict('0', $working);
            $collectionCount = 0;
            $source = ShiftCashMovementSource::CashDrawerOperations;
        }

        $expected = bcadd(
            bcadd(bcadd($openingFloat, $cashSales, $working), $movementsNet, $working),
            $accountCollections,
            $working,
        );

        return new ShiftExpectedCashBreakdown(
            openingFloat: CurrencyScale::bcformatStrict($openingFloat, $scale),
            cashSales: CurrencyScale::bcformatStrict($cashSales, $scale),
            movementsNet: CurrencyScale::bcformatStrict($movementsNet, $scale),
            accountCollections: CurrencyScale::bcformatStrict($accountCollections, $scale),
            expectedCash: CurrencyScale::bcformatStrict($expected, $scale),
            movementSource: $source,
            movementCount: $movementCount,
            accountCollectionCount: $collectionCount,
            currencyCode: $currencyCode,
            scale: $scale,
            windowEnd: $until->toIso8601String(),
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
            ->where('pos_z_session_events.chain_context', self::LIVE_Z_SESSION_CHAIN)
            ->where('fiscal_events.integrity_status', IntegrityStatus::Verified->value)
            ->whereIn('pos_z_session_events.event_type', array_map(
                static fn (FiscalEventType $type): string => $type->value,
                self::CASH_MOVEMENT_EVENT_TYPES,
            ))
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
     * Cash collected against customer credit accounts during the shift.
     *
     * # Why this term exists (gate r2)
     *
     * A customer paying down their account in cash physically fills this drawer,
     * and the device folds it into its own expected cash
     * (`apps/pos/src/lib/offline/zReportService.ts:272-275`). Omitting it makes
     * the server figure short by exactly that amount on every shift that took
     * one — and short money is exported to the NF525 JET as a BALANCED count.
     *
     * The first version of this service left the term out on the stated grounds
     * that `pos_account_payment_receipts` "carries no shift_id and no tender
     * breakdown". The COLUMN has none; the ROW does.
     * `AccountPaymentReceiptProjection` stores `payload_snapshot =>
     * $payload->toArray()`, and `AccountPaymentPayload::toArray()` emits
     * `shift_id` and `payment` (with `method_code` and `amount`) — written by the
     * device, which REQUIRES `shift_id` on every ACCOUNT_PAYMENT it authors.
     *
     * # Attribution, and the refusal
     *
     * Rows are found by TERMINAL + window (through `fiscal_events`, which also
     * carries the integrity status), then attributed by
     * `payload_snapshot->shift_id`:
     *
     *   - belongs to this shift + tendered in CASH → added;
     *   - belongs to this shift + tendered any other way → ignored (it never
     *     entered the drawer);
     *   - belongs to ANOTHER shift → ignored;
     *   - carries NO shift_id → {@see UnattributableAccountCollectionException}.
     *
     * That last branch is the same fail-closed standard `CASH_CORRECTION` gets.
     * The alternatives are guessing that a grandfathered row belongs to this
     * shift, or silently dropping cash that is sitting in the drawer; both write
     * a wrong number into a certified export.
     *
     * Training collections are skipped — training money is not real.
     *
     * @return array{0: numeric-string, 1: int}
     */
    private function cashAccountCollections(Shift $shift, Terminal $terminal, CarbonInterface $until, int $scale): array
    {
        $rows = DB::table('pos_account_payment_receipts')
            ->join('fiscal_events', 'pos_account_payment_receipts.fiscal_event_id', '=', 'fiscal_events.id')
            ->where('fiscal_events.terminal_id', $terminal->id)
            ->where('fiscal_events.integrity_status', IntegrityStatus::Verified->value)
            ->whereBetween('fiscal_events.event_time_device', [$shift->opened_at, $until])
            ->orderBy('fiscal_events.event_time_device')
            ->get([
                'pos_account_payment_receipts.id as id',
                'pos_account_payment_receipts.payload_snapshot as payload_snapshot',
            ]);

        $net = CurrencyScale::bcformatStrict('0', $scale);
        $count = 0;

        foreach ($rows as $row) {
            $snapshot = $this->decodePayload($row->payload_snapshot);

            if (($snapshot['training_flag'] ?? null) === true) {
                continue;
            }

            $rowShiftId = $snapshot['shift_id'] ?? null;
            if (! is_string($rowShiftId) || $rowShiftId === '') {
                throw UnattributableAccountCollectionException::forShift($shift->id, (string) $row->id);
            }

            if ($rowShiftId !== $shift->id) {
                continue;
            }

            $payment = $snapshot['payment'] ?? null;
            if (! is_array($payment)) {
                throw UnattributableAccountCollectionException::forShift($shift->id, (string) $row->id);
            }

            $methodCode = $payment['method_code'] ?? null;
            $amount = $payment['amount'] ?? null;

            if (! is_string($methodCode) || ! is_string($amount) || ! is_numeric($amount)) {
                throw UnattributableAccountCollectionException::forShift($shift->id, (string) $row->id);
            }

            if (strtoupper($methodCode) !== 'CASH') {
                continue;
            }

            $net = bcadd($net, CurrencyScale::bcformatStrict($amount, $scale), $scale);
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
     * `CASH_IN` is the device's `deposit` and `CASH_OUT` its `payout`
     * (`apps/pos/src/api/cashDrawerApi.ts:177`), summed by the device's own Z as
     * `deposit = +, payout = −` (`apps/pos/src/lib/offline/zReportService.ts:264-276`).
     *
     * `SAFE_DROP` is signed `-` because cash physically leaves the drawer for
     * the safe — but be honest about the basis: that matches the sign
     * `CashDrawerService` gives its v2 `DEPOSIT`, NOT an observed device
     * behaviour, because the device has no `SAFE_DROP` authoring path at all
     * (its only Z cash-movement writer emits `CASH_IN`/`CASH_OUT`). The day one
     * is authored, this sign and the device's own expected cash must be
     * reconciled before they are trusted to agree.
     *
     * `OPENING_FLOAT` never reaches here — the float is read from
     * `pos_shifts.opening_cash` and adding both would double it.
     *
     * `CASH_CORRECTION` is refused: it is the only movement whose direction
     * lives in the amount's SIGN rather than in its type, and it has no device
     * authoring path, so there is no observed convention to encode. Fails closed
     * deliberately — treating an unrecognised movement as zero would return a
     * figure that looks reasonable, is short by whatever the movement was worth,
     * and is exported to a certified JET as a balanced count.
     *
     * @return numeric-string
     */
    private function signedMovementAmount(string $shiftId, string $movementType, string $amount, int $scale): string
    {
        $magnitude = CurrencyScale::bcformatStrict($amount, $scale);
        $direction = $this->movementDirection($movementType);

        if ($direction === null) {
            throw UnsignableCashMovementException::forShift(
                $shiftId,
                $movementType,
                implode(', ', array_map(
                    static fn (FiscalEventType $type): string => $type->value,
                    self::SIGNABLE_MOVEMENT_TYPES,
                )),
            );
        }

        return $direction === '+'
            ? $magnitude
            : bcsub(CurrencyScale::bcformatStrict('0', $scale), $magnitude, $scale);
    }

    /**
     * @return '+'|'-'|null null = no agreed direction on the server
     */
    private function movementDirection(string $movementType): ?string
    {
        return match (FiscalEventType::tryFrom($movementType)) {
            FiscalEventType::CASH_IN => '+',
            FiscalEventType::CASH_OUT, FiscalEventType::SAFE_DROP => '-',
            default => null,
        };
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
