<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * Someone tried to replace the line set of a CORRECTION document (DPA V7 / D8).
 *
 * A contra's lines are DERIVED, not authored: `correct()` negates every line of
 * the original and resolves each lot to the one that original line actually
 * MOVED. Two things follow, and both are the reason this refusal exists:
 *
 *  1. D8 makes correcting all-or-nothing. A contra whose lines have been edited
 *     is no longer the inverse of anything, and `reverses_movement_id` — which
 *     G1 must read to recover the original reason — is matched by the line's
 *     (product, variant, lot) tuple, so an edit silently breaks that linkage.
 *  2. It is what CONTAINS the lot-required exemption. That exemption is keyed on
 *     the header's `corrects_adjustment_id`, and `correct()` was not the only
 *     door onto such a header: `updateDraft()` fully replaces the line set, so a
 *     hand-authored lot-less negative that is refused on a new document was
 *     accepted on a contra, and posting it produced Sigma lots > aggregate — the
 *     exact FEFO corruption the exemption's own containment claim denied.
 *     Refusing replacement makes `correct()` the only door, so the header-keyed
 *     exemption becomes airtight by construction rather than by assertion.
 *
 * The NOTE stays editable — only the lines are derived.
 *
 * Re-anchoring a contra is likewise not a thing: its delta is fixed by D8 as the
 * exact negation, so rebasing it to hit a fresh target would make it not a
 * contra. The recovery for a stale correction is "Apply anyway", which is what
 * the release note already documents.
 */
class ContraLinesImmutableException extends DomainException
{
    public function __construct(
        public readonly string $adjustmentId,
        public readonly string $correctsAdjustmentId,
    ) {
        parent::__construct(
            "Stock adjustment {$adjustmentId} is a correction of {$correctsAdjustmentId}; its lines are "
            .'derived from that document and cannot be replaced.'
        );
    }
}
