<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * The verdicts the unified delivery-compliance gate can return (Wave 3 T25f).
 *
 * Before T25f the same four verdicts existed twice — once as thrown
 * `\DomainException` message strings in `DocumentPostingService`, once as
 * untyped `['status' => '…']` arrays in `InvoiceController` — with two different
 * spellings for the same condition and no way for a caller to branch on them.
 * The machine codes below are what the HTTP layer maps to a response code, so
 * the refusal no longer depends on which entry point raised it.
 */
enum DeliveryComplianceCode: string
{
    /** Nothing to enforce, or everything the policy asks for is satisfied. */
    case Compliant = 'COMPLIANT';

    /**
     * The invoice is linked to a source order that carries no delivery notes at
     * all — the operator has to create one before the invoice can be posted.
     */
    case NoDeliveryNotes = 'NO_DELIVERY_NOTES';

    /**
     * Delivery notes exist but are still DRAFT: goods have NOT left. This is the
     * verdict the guided "confirm & post" modal is built for.
     */
    case DraftDeliveryNotes = 'DRAFT_DELIVERY_NOTES';

    /**
     * A linked delivery note records less delivered than ordered on at least one
     * line — a short delivery that has to be reconciled first.
     */
    case IncompleteDelivery = 'INCOMPLETE_DELIVERY';

    public function isCompliant(): bool
    {
        return $this === self::Compliant;
    }
}
