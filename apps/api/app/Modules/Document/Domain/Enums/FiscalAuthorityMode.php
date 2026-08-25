<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

use App\Modules\Document\Domain\CountryDocumentSettings;

/**
 * Does this COUNTRY require a fiscal authority to accept a document before it may
 * be posted? (SPEC §1 fiscal row · §2.3.)
 *
 * A **seeded, per-country** policy living in
 * `country_document_settings.fiscal_authority_mode`, exactly like its sibling
 * {@see PreDeliveryInvoicingPolicy}: nothing about the rule is hardcoded, and —
 * F-112 — **no DB default and no code default may decide it**. That is why this
 * enum deliberately ships NO `systemDefault()`: a country with no seeded row is a
 * typed refusal (`COUNTRY_DOCUMENT_SETTINGS_NOT_SEEDED`, C-QR0b), never a guess.
 *
 * ── UNACTIVATED IN THIS LANE (C-QR0a) ──
 * The column exists and is NULL everywhere. The seed rows (TN `required`, every
 * other catalog country `not_required`), the row initialiser, the backfill, the
 * resolver and the posting guard are all C-QR0b. Nothing reads this enum yet
 * except the Eloquent cast on
 * {@see CountryDocumentSettings}.
 */
enum FiscalAuthorityMode: string
{
    /**
     * No authority stands between `confirmed` and `posted`. Every catalog country
     * other than TN is seeded to this value in C-QR0b.
     */
    case NotRequired = 'not_required';

    /**
     * The authority must ACCEPT before the sealing edge may be taken. TN.
     * A document created before a flip to `required` cannot satisfy fiscal
     * completion afterwards — posting consults the CURRENT mode (SPEC §2.3).
     */
    case Required = 'required';

    public function requiresAuthority(): bool
    {
        return $this === self::Required;
    }
}
