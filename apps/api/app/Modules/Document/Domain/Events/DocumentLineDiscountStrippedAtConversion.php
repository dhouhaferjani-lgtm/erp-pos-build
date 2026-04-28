<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Modules\Document\Domain\Services\Conversion\StripSubToleranceDiscountsService;
use App\Shared\Domain\Events\DomainEvent;

/**
 * Raised when a document conversion strips a sub-tolerance discount from
 * a line or document header. The strip is performed by
 * {@see StripSubToleranceDiscountsService}
 * BEFORE the new document is hashed (Invoice posting), so the strip never
 * leaves a fiscal-chain inconsistency. This event is the audit-trail
 * record that survives the silent strip.
 *
 * Per spec §7 (anti-abuse): a sub-tolerance discount is treated as the
 * payment-tolerance write-off it actually is. The cashier/AR clerk's
 * "discount" is preserved in business intent but reclassified to GL 658
 * at settlement time (POS A1 / B2B A2), and the discount fields on the
 * destination document are zeroed so VAT base is correctly reported.
 *
 * IMMUTABLE FROM v1 (Rule #8): no field renaming, removal, or restructuring.
 * If a future requirement needs additional context, add a versioned
 * replacement (DocumentLineDiscountStrippedAtConversionV2) rather than
 * editing this class.
 */
final class DocumentLineDiscountStrippedAtConversion extends DomainEvent
{
    /**
     * @param  string|null  $lineId  DocumentLine ID, or null for a header-discount strip.
     * @param  string|null  $sourceLineId  Origin line in the source document, when present.
     * @param  string  $originalDiscountAmount  Decimal string at scale 4 (matching tolerance pipeline).
     * @param  string  $toleranceMargin  Decimal string at scale 4 — the resolved threshold.
     * @param  string  $subtotal  Decimal string at scale 4 — what the margin was computed against.
     * @param  string  $strippedAt  ISO 8601 UTC timestamp.
     */
    public function __construct(
        public readonly ?string $lineId,
        public readonly ?string $sourceLineId,
        public readonly string $sourceDocumentId,
        public readonly string $targetDocumentId,
        public readonly string $sourceDocumentNumber,
        public readonly string $targetDocumentNumber,
        public readonly string $sourceType,
        public readonly string $targetType,
        public readonly string $companyId,
        public readonly string $tenantId,
        public readonly string $originalDiscountAmount,
        public readonly string $toleranceMargin,
        public readonly string $subtotal,
        public readonly string $currencyCode,
        public readonly ?string $userId,
        public readonly string $strippedAt,
    ) {
        // Aggregate root is the target document — its hash chain is the one
        // that gains audit visibility into the silent strip.
        parent::__construct($targetDocumentId);
    }

    public function getEventName(): string
    {
        return 'document.discount.stripped_on_conversion';
    }
}
