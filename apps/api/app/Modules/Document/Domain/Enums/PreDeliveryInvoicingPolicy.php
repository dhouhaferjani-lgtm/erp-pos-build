<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

use App\Modules\Document\Domain\Exceptions\PreDeliveryInvoicingNotSupportedException;

/**
 * May a definitive goods invoice be POSTED before the goods are delivered?
 *
 * A **seeded, per-country, tenant-editable** policy (Wave 3 D-18′ / D-27, owner
 * rider binding). Nothing about this rule is hardcoded: the value lives in
 * `country_document_settings`, with a nullable `companies` override, and is read
 * through `PreDeliveryInvoicingPolicyResolver`.
 *
 * ── WHY THE RULE EXISTS (expert rulings, 2026-08-10) ──
 *  - **NCT 03**: revenue on a sale of goods is recognised at transfer of risks
 *    and rewards. An invoice issued beforehand is a **liability** (472 / 419),
 *    never revenue.
 *  - **Code de la TVA, Art. 18**: whoever mentions VAT on an invoice owes it by
 *    the mere fact of issuance — immediately, delivery or not.
 *  - Posting an invoice also SEALS it into the fiscal hash chain, so a
 *    non-compliant posting is irreversible by construction.
 *
 * ── WHY `Allow` IS PRESENT BUT UNREACHABLE ──
 * Representing a pre-delivery invoice correctly requires 472/419 deferred-revenue
 * machinery, which **does not exist in this codebase**. The enum admits the case
 * and the CHECK constraint admits the string — so enabling it later needs no DDL
 * on a live tenant database — but the resolver REFUSES it
 * ({@see PreDeliveryInvoicingNotSupportedException}).
 * Fail-closed, deliberately: the same three-layer shape D-14 uses for the
 * `periodic` valuation mode.
 */
enum PreDeliveryInvoicingPolicy: string
{
    /**
     * The goods must have left before their invoice is posted. The system
     * default and the seeded value for every country.
     */
    case RequireDeliveryFirst = 'require_delivery_first';

    /**
     * Reserved. REFUSED at the resolver until 472/419 deferred-revenue posting
     * exists — see the class docblock. Two preconditions are recorded against
     * enabling it: (1) the 472 machinery, and (2) a decision on batch-tracked
     * products, whose batch attribution has no meaning before an exit movement.
     */
    case Allow = 'allow';

    /**
     * The fail-closed system default used when no company override and no country
     * row resolve. No caller may invent a policy.
     */
    public static function systemDefault(): self
    {
        return self::RequireDeliveryFirst;
    }

    public function requiresDeliveryBeforeInvoicing(): bool
    {
        return $this === self::RequireDeliveryFirst;
    }
}
