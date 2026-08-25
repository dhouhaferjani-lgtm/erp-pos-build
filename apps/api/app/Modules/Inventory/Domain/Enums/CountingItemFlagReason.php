<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Reasons an InventoryCountingItem's `flag_reasons` jsonb array may contain.
 *
 * Flag semantics (normative for B2/B3/B4/D3): `is_flagged=true` iff
 * `flag_reasons` contains a BLOCKING reason (basket_window,
 * negative_at_apply, clock_skew, pending_opening_cost). `normalized_agreement`
 * is informational only — it is appended to `flag_reasons` but NEVER sets
 * `is_flagged`.
 *
 * `is_flagged` (REVIEW visibility) and {@see self::blocksStockApplication()}
 * (POSTING) are two different questions and a reason may answer them
 * differently. `basket_window` is the case that proves it: campaign W4-6 showed
 * it blocking every line that had moved within ±`ambiguity_window_minutes` of
 * the count instant — which, in a shop that keeps selling while it counts, is
 * every line — so real shrinkage was flagged and discarded. The replay
 * (`expected_now = counted + Σ[as_of, now]`) is what keeps an in-window sale
 * counted exactly ONCE; the near-movement itself is evidence for the reviewer,
 * never a reason to leave the shelf and the ledger disagreeing. It therefore
 * still raises `is_flagged`, and no longer blocks the stock write.
 *
 * ## What that decides, stated rather than left implicit (gate r1 F-2)
 *
 * The ± window is symmetric but the replay is not, and the asymmetry is the
 * point. Movements from the count instant ONWARDS are neutralised, so they
 * cannot move the variance. Movements STRICTLY BEFORE it are part of the
 * baseline — the counter is taken to have counted the shelf as it stood — so a
 * `basket_window` line whose only nearby movement is in the PRE-count half now
 * resolves by POSTING its counted-vs-expected delta.
 *
 * That is correct whenever the goods were on (or off) the shelf before the
 * counter reached it, and wrong when they were booked before the count but
 * moved physically after it. There is no in-product reversal beyond a fresh
 * count, and once
 * `inventory.count_correction_gl_posting_enabled` is flipped it also becomes a
 * journal entry — which is why the flag raises `is_flagged` and the reviewer
 * sees the chip. Carried on the LEDGER next to the OQ-12 flag-flip row.
 *
 * ## `missing_boundary_marker` — the third question (lane P-1, gate r1 F-2)
 *
 * W4-6 gate r2 NEW-1 resolved the same-second tie by insertion ORDER, using a
 * per-item `final_qty_movement_marker`. When that marker is NULL — a line
 * submitted before the marker columns shipped on that tenant, i.e. a count in
 * flight across the upgrade — `MovementReplayService::signedDelta()` falls back
 * to the r1 inclusive-boundary semantics, and a movement stamped in the SAME
 * SECOND as the count is subtracted twice. Once count-correction GL posting is
 * ON (this lane), that guess also becomes a journal entry.
 *
 * So a marker-less line whose boundary second actually carries a movement gets
 * this reason: `isBlocking()` true (the reviewer sees it), stock application
 * NOT blocked (the shelf is still corrected), {@see self::blocksGlPosting()}
 * true (the ledger is not). A marker-less line with an EMPTY boundary second is
 * not ambiguous at all — the marker branch is a no-op for it — and is left
 * alone.
 *
 * `pending_opening_cost` (D3) is raised when an onboarding first-count line has
 * no resolvable positive opening cost (item override unset AND the product's
 * `cost_price` is ≤ 0). Posting it would silently establish a zero-cost opening
 * balance, so the line is BLOCKED and left for review until the cost is
 * backfilled via the opening-cost endpoint (an explicit endpoint-set cost of 0
 * is a deliberate zero-cost opening and is NOT blocked).
 */
enum CountingItemFlagReason: string
{
    case BasketWindow = 'basket_window';
    case NegativeAtApply = 'negative_at_apply';
    case ClockSkew = 'clock_skew';
    case PendingOpeningCost = 'pending_opening_cost';
    case NormalizedAgreement = 'normalized_agreement';
    case MissingBoundaryMarker = 'missing_boundary_marker';

    /**
     * Whether this reason alone forces `is_flagged=true` on the item.
     */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::BasketWindow, self::NegativeAtApply, self::ClockSkew, self::PendingOpeningCost,
            self::MissingBoundaryMarker => true,
            self::NormalizedAgreement => false,
        };
    }

    /**
     * Whether the replay apply listener leaves the stock grain untouched.
     *
     * `basket_window` is deliberately ABSENT (W4-6): an ambiguous movement near
     * the count instant is annotated and still applied.
     */
    public function blocksStockApplication(): bool
    {
        return match ($this) {
            self::NegativeAtApply, self::PendingOpeningCost => true,
            self::BasketWindow, self::ClockSkew, self::NormalizedAgreement,
            self::MissingBoundaryMarker => false,
        };
    }

    /**
     * Whether this reason withholds the line's JOURNAL ENTRY while still letting
     * the stock correction through (lane P-1, gate r1 F-2).
     *
     * This is the THIRD question the enum answers, and it exists because the two
     * that came before it cannot express the case. `missing_boundary_marker`
     * names a line whose replay is provably ambiguous at the boundary second and
     * which therefore may have moved stock by the wrong amount. Refusing the
     * stock write would strand the operator with a shelf nobody can correct;
     * writing the journal entry would put an unprovable number in the ledger,
     * where it is far more expensive to undo. So: correct the shelf, withhold
     * the value, flag the line for review.
     */
    public function blocksGlPosting(): bool
    {
        return match ($this) {
            self::MissingBoundaryMarker => true,
            self::BasketWindow, self::NegativeAtApply, self::ClockSkew,
            self::PendingOpeningCost, self::NormalizedAgreement => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BasketWindow => 'Sale in Basket Window',
            self::NegativeAtApply => 'Negative Stock at Apply',
            self::ClockSkew => 'Clock Skew Detected',
            self::PendingOpeningCost => 'Opening Cost Required',
            self::NormalizedAgreement => 'Normalized Agreement',
            self::MissingBoundaryMarker => 'Boundary Marker Missing',
        };
    }
}
