<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

use App\Modules\Document\Domain\Document;

/**
 * Where ONE document stands with the fiscal authority (SPEC §1 fiscal row · §2.3).
 *
 * Stored in `documents.fiscal_authority_status`. It is the fourth dimension's
 * second axis: `fiscal_status` says whether the row is sealed, this says whether
 * the authority has blessed it. The **fiscal completion predicate** (F-61) is
 * `fiscal_status = SEALED AND fiscal_authority_status IN (not_required, accepted)`,
 * and `confirmed→posted` requires it for fiscal types.
 *
 * FROZEN once the seal fact exists (F-101): only the QR acquisition service writes
 * it, and only while the document is still unsealed. **That freeze is not enforced
 * anywhere yet** (gate r1 F-5): `enforce_document_immutability()` is a column
 * BLACKLIST that does not name this column, and the seal hash covers only
 * `document_number`, `posted_at`, `total` and `currency` — so flipping a sealed row
 * from `rejected` to `accepted` breaks no chain today. The data-boundary triggers
 * that make F-101 real belong to C-3a1a (SPEC §2.3 / F-133), which lands AFTER
 * C-QR0b ships the writer; until then the freeze is a stated invariant, not a
 * guarded one.
 *
 * `not_required` is shared with {@see FiscalAuthorityMode} as a STRING but not as a
 * type: DeliveryNote and ReturnNote initialise to `not_required` regardless of the
 * country mode (they seal on `draft→confirmed`, outside the authority's
 * applicability matrix — F-108), as do CorrectingEntry and every non-fiscal type.
 *
 * ── UNACTIVATED IN THIS LANE (C-QR0a) ──
 * The column exists and is NULL on every row. No initialiser, no backfill, no
 * guard, no refusal — all C-QR0b. Nothing reads this enum yet except the Eloquent
 * cast on {@see Document}.
 */
enum FiscalAuthorityStatus: string
{
    /** The authority does not apply to this document (type or country). */
    case NotRequired = 'not_required';

    /** Submitted, or awaiting submission; posting is blocked. */
    case Pending = 'pending';

    /** Blessed. Together with `SEALED` this makes the document fiscally complete. */
    case Accepted = 'accepted';

    /** Refused by the authority; posting stays blocked. */
    case Rejected = 'rejected';

    /**
     * The completion half of F-61. The SEALED half is `fiscal_status`'s business —
     * this method deliberately answers only for its own axis so no caller can
     * mistake one for the whole predicate.
     */
    public function satisfiesFiscalCompletion(): bool
    {
        return $this === self::NotRequired || $this === self::Accepted;
    }
}
