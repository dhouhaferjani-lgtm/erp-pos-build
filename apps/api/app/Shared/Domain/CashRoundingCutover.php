<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The ONE cash-rounding cutover discriminator (spec §4.5 / §4.6).
 *
 * `fiscal_events.event_version >= CashRoundingCutover::EVENT_VERSION` is the
 * single gate for every rounding-era behaviour on the server:
 *
 *   - `PosCoreReceiptProjection` — writes `cash_rounding_adjustment`,
 *     `cash_rounding_denomination`, `change_due` and `tolerance_writeoff`,
 *     and nets the loyalty earn base;
 *   - `TreasuryReceiptBridge` — nets the customer's change off the cash tender
 *     legs so `payments.amount` carries the RETAINED amount, and suppresses a
 *     fully-netted leg entirely.
 *
 * **Why it is shared rather than duplicated.** The read model and the ledger
 * must agree, receipt for receipt, about which events are rounding-era. Two
 * private copies of the literal `3` is exactly how one side starts netting an
 * event the other side does not, and the two tables then disagree about the
 * same fiscal event forever — with no error anywhere.
 *
 * **Below the cutover, nothing changes — ever.** A v1/v2 event must produce
 * byte-identical rows on FIRST APPLY and on REPLAY. The replay half is the
 * sharp edge: `TreasuryMovementService` throws `IdempotencyConflictException`
 * when an existing movement key is re-recorded at a different amount, so a
 * historical event redelivered into netting logic would become a permanently-
 * failing queue job rather than a wrong number. Guard first, compute second.
 *
 * This is a versioning constant, not a tunable: it is baked into how already-
 * signed receipts are interpreted. Bumping it re-interprets history.
 */
final class CashRoundingCutover
{
    /**
     * First `fiscal_events.event_version` that carries cash-rounding
     * semantics (the two `cash_rounding_*` payload keys, over-tender change,
     * and tolerance shortfall).
     */
    public const int EVENT_VERSION = 3;

    /**
     * True when the given event version is at or above the cutover.
     *
     * Callers may compare against {@see EVENT_VERSION} directly; this helper
     * exists so the `>=` (never `===`) direction is stated once — a future
     * v4 payload is still a rounding-era payload.
     */
    public static function applies(int $eventVersion): bool
    {
        return $eventVersion >= self::EVENT_VERSION;
    }
}
