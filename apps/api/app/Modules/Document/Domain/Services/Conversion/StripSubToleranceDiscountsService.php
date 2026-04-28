<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Events\DocumentLineDiscountStrippedAtConversion;
use App\Modules\Treasury\Application\Services\DiscountToleranceBoundary;
use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;
use Illuminate\Support\Facades\Auth;

/**
 * Strips sub-tolerance discounts from a freshly-converted target document
 * (line-level and header-level) BEFORE the document is hashed or its totals
 * are recalculated. Each strip dispatches an immutable
 * {@see DocumentLineDiscountStrippedAtConversion} event so the audit trail
 * survives the silent rewrite.
 *
 * Catches the Quote loophole described in spec §7: Quotes skip the
 * `DiscountAboveTolerance` request validator (negotiation-stage), so a
 * quote may legitimately carry a €0.20 line discount. When that quote
 * graduates to a SalesOrder or Invoice, the sub-tolerance value would
 * become an abuse vector — this service converts it back into a
 * payment-tolerance situation by zeroing the discount fields.
 *
 * The "residual" (originally meant as a small discount) flows through as
 * a balance-due gap and gets absorbed at settlement time by the existing
 * tolerance pipeline (POS A1 / B2B A2). No money is lost; only the
 * fiscal classification is corrected.
 */
final class StripSubToleranceDiscountsService
{
    private const SCALE = 4;

    public function __construct(
        private readonly DiscountToleranceBoundary $boundary,
    ) {}

    /**
     * Walk every line of the target document plus its header discount and
     * zero anything that fails the tolerance boundary, dispatching one
     * audit event per stripped item.
     */
    public function stripFromConvertedDocument(Document $source, Document $target): void
    {
        $companyId = (string) $target->company_id;
        $tenantId = (string) $target->tenant_id;

        // Documents are NOT NULL on currency at the schema layer (and the
        // model types it as `string`), but assert explicitly so a
        // misconfigured factory or migration mishap doesn't silently
        // mis-render the strip event with a hardcoded fallback.
        $currency = $target->currency;
        if ($currency === '') {
            throw new \RuntimeException(
                "Cannot strip sub-tolerance discount: target document {$target->id} has no currency set."
            );
        }

        $userId = Auth::id();
        $userIdString = $userId !== null ? (string) $userId : null;

        // Line-level strips — the dominant case (per spec §7 anti-abuse rule).
        foreach ($target->lines()->get() as $line) {
            $discountAmount = $line->discount_amount;
            if ($discountAmount === null) {
                continue;
            }

            if (bccomp($discountAmount, '0', self::SCALE) === 0) {
                continue;
            }

            $lineSubtotal = bcmul(
                $line->quantity ?? '0',
                $line->unit_price ?? '0',
                self::SCALE,
            );

            try {
                $this->boundary->assertDiscountAboveTolerance(
                    discountAmount: $discountAmount,
                    subtotal: $lineSubtotal,
                    companyId: $companyId,
                    currencyCode: $currency,
                );
            } catch (DiscountBelowToleranceException $e) {
                // Per spec §7: zero (not null) so downstream consumers that
                // check `discount_amount !== null` continue to behave
                // consistently. Then re-bake line_total from the now-zero
                // discount — without this, the residual silently disappears
                // and the settlement-time tolerance pipeline never sees the
                // gap it is supposed to absorb.
                $line->discount_amount = '0';
                $line->discount_percent = '0';
                // calculateTotal() returns `string`; coerce through bcadd so
                // the assignment matches DocumentLine's `numeric-string` type
                // contract without an inline cast.
                /** @phpstan-ignore-next-line argument.type */
                $line->line_total = bcadd($line->calculateTotal(self::SCALE), '0', self::SCALE);
                $line->save();

                event(new DocumentLineDiscountStrippedAtConversion(
                    lineId: (string) $line->id,
                    sourceLineId: $line->source_line_id,
                    sourceDocumentId: (string) $source->id,
                    targetDocumentId: (string) $target->id,
                    sourceDocumentNumber: (string) $source->document_number,
                    targetDocumentNumber: (string) $target->document_number,
                    sourceType: $source->type->value,
                    targetType: $target->type->value,
                    companyId: $companyId,
                    tenantId: $tenantId,
                    originalDiscountAmount: $e->discountAmount,
                    toleranceMargin: $e->toleranceMargin,
                    subtotal: $lineSubtotal,
                    currencyCode: $currency,
                    userId: $userIdString,
                    strippedAt: now()->toIso8601String(),
                ));
            }
        }

        // Header-level strip — checks against the *target* document's subtotal,
        // not its post-discount total, so the threshold matches what a
        // payment-time tolerance call would see.
        $headerDiscount = $target->discount_amount;
        if ($headerDiscount === null) {
            return;
        }

        if (bccomp($headerDiscount, '0', self::SCALE) === 0) {
            return;
        }

        $headerSubtotal = $target->subtotal ?? '0';

        try {
            $this->boundary->assertDiscountAboveTolerance(
                discountAmount: $headerDiscount,
                subtotal: $headerSubtotal,
                companyId: $companyId,
                currencyCode: $currency,
            );
        } catch (DiscountBelowToleranceException $e) {
            // Spec §7: zero (not null) — downstream code paths that check
            // `discount_amount !== null` must keep behaving the same.
            $target->update(['discount_amount' => '0']);

            event(new DocumentLineDiscountStrippedAtConversion(
                lineId: null,
                sourceLineId: null,
                sourceDocumentId: (string) $source->id,
                targetDocumentId: (string) $target->id,
                sourceDocumentNumber: (string) $source->document_number,
                targetDocumentNumber: (string) $target->document_number,
                sourceType: $source->type->value,
                targetType: $target->type->value,
                companyId: $companyId,
                tenantId: $tenantId,
                originalDiscountAmount: $e->discountAmount,
                toleranceMargin: $e->toleranceMargin,
                subtotal: $headerSubtotal,
                currencyCode: $currency,
                userId: $userIdString,
                strippedAt: now()->toIso8601String(),
            ));
        }
    }
}
