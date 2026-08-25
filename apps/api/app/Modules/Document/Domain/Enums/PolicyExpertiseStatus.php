<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

use App\Shared\Domain\CountryDocumentDefaults;

/**
 * How much expert authority stands behind a seeded country policy row
 * (SPEC §2.3, F-134). Stored in `country_document_settings.policy_expertise_status`.
 *
 * Every provisionable ISO-3166 country in the catalog gets an EXPLICIT row in
 * C-QR0b — no country is left to a DDL default or a code fallback. But "explicit"
 * is not "verified": TN's values come from the 2026-08-10 expert rulings, while a
 * country nobody has asked a professional about is seeded on a conservative reading.
 * Conflating the two is how a guess silently acquires the standing of a ruling
 * (see {@see CountryDocumentDefaults}'s FR note, which is
 * exactly this situation written in a comment instead of a column).
 *
 * So the distinction is a COLUMN, and a `provisional` row REFUSES posting for that
 * country with a typed `COUNTRY_POLICY_PROVISIONAL` — before any company override
 * is consulted. Fail closed.
 *
 * ── UNACTIVATED IN THIS LANE (C-QR0a) ──
 * The column exists and is NULL everywhere; the refusal is C-QR0b.
 */
enum PolicyExpertiseStatus: string
{
    /** Backed by a recorded professional ruling. Posting proceeds. */
    case Approved = 'approved';

    /** A conservative reading nobody has certified. Posting is REFUSED. */
    case Provisional = 'provisional';

    public function permitsPosting(): bool
    {
        return $this === self::Approved;
    }
}
