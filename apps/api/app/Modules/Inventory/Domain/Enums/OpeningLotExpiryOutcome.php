<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * What actually happened to the `expiry_date` an operator supplied on an opening
 * line (W4-1 gate r1).
 *
 * The lane exists to stop the system inventing facts about stock. Silently
 * DISCARDING a fact the operator did supply is the same defect wearing the other
 * coat, so every opening line reports which of these five things happened and the
 * import surfaces it as a row warning instead of reporting a bare `ok`.
 */
enum OpeningLotExpiryOutcome: string
{
    /** No expiry was supplied on the line. Nothing to report. */
    case NotSupplied = 'not_supplied';

    /** The supplied expiry is on the lot — either a fresh mint or a set-once fill. */
    case Applied = 'applied';

    /**
     * The DEFAULT lot already existed with NO expiry and the supplied date filled
     * it. One row per product+variant across ALL locations, so this is the second
     * line of a multi-location opening for the same SKU.
     */
    case FilledExistingLot = 'filled_existing_lot';

    /**
     * The DEFAULT lot already carried a DIFFERENT expiry. The existing date is
     * kept — rewriting a lot that already holds stock and movements would be a
     * silent ledger correction — and the operator is told their date was not used.
     */
    case ConflictExistingLot = 'expiry_conflict_existing_lot';

    /**
     * The product is not batch-tracked, so there is no lot to carry an expiry.
     * Accepted by validation, echoed in the preview, and then necessarily dropped
     * — which the operator has to be told rather than left to discover.
     */
    case IgnoredNotBatchTracked = 'expiry_ignored_not_batch_tracked';

    /**
     * The product IS batch-tracked, but no DEFAULT lot was minted for this opening:
     * real, operator-supplied lots already account for the whole quantity, so there
     * is no untracked remainder to back and nothing for the date to attach to.
     *
     * Distinct from {@see self::IgnoredNotBatchTracked} on purpose (gate r1
     * MINOR-4): telling a parapharmacy that its batch-tracked product "is not
     * batch-tracked" is a false statement about their catalogue, and this lane's
     * whole thesis is that operator-facing facts must be true.
     */
    case IgnoredNoDefaultLot = 'expiry_ignored_no_default_lot';

    /**
     * Does this outcome need to be said out loud?
     *
     * True for every case where the date the operator supplied is NOT what the lot
     * ended up carrying. `NotSupplied` and `Applied` are the silent ones: nothing
     * was lost, so there is nothing to report.
     */
    public function isNoteworthy(): bool
    {
        return match ($this) {
            self::NotSupplied, self::Applied, self::FilledExistingLot => false,
            self::ConflictExistingLot, self::IgnoredNotBatchTracked, self::IgnoredNoDefaultLot => true,
        };
    }
}
