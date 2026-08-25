<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

/**
 * A purchase order refused to become a firm commitment while a line has no price.
 *
 * ── WHY THIS GUARD EXISTS (campaign W2-6, gate r2 finding 2) ──
 * W2-6 gives a purchase line whose product has no `products.purchase_price` an EMPTY
 * price, so the operator must type it rather than accept the product's RETAIL price
 * (the original defect: the goods receipt booked at retail, inflating stock valuation
 * and WAC by ~64%). The submit path refuses a blank.
 *
 * The DRAFT AUTOSAVE, however, deliberately coerces `''` to `'0'` — it fires at
 * keystroke frequency and an empty cell would otherwise be a stream of 422s that trips
 * the autosave-failed / beforeunload guard. That coercion converts "unpriced" into
 * "priced at zero" AT THE PERSISTENCE BOUNDARY, and past that point nothing could tell
 * the two apart: re-opening the draft loads `'0.000'`, which is not blank, and
 * `CreateDocumentRequest`'s `lines.*.unit_price => required|numeric` accepts `'0'`
 * (only `''` and `null` are rejected). The detail page's Confirm button posts straight
 * to `/purchase-orders/{id}/confirm`, which an FE guard cannot cover at all.
 *
 * A confirmed zero-priced PO posts `Dr 37 Stocks` at zero on goods receipt, drags the
 * weighted-average cost down, and the supplier-invoice three-way match raises nothing
 * worse than an ADVISORY `price_variance` under the shipped `warn` policy
 * (`procurement_policies.price_variance_enforcement` defaults to `warn`). So the only
 * durable closure is here, at confirm.
 *
 * ── FREE OF CHARGE ──
 * `document_lines.is_bonus_line` is the product's explicit zero-value flag ("Marks
 * explicit zero-value supplier invoice / credit-note bonus lines", migration
 * 2026_07_02_100000). Such a line is legitimately 0.000 and is exempted. The OTHER
 * bonus mechanism — same-product purchase bonuses — rides `free_quantity` on a normally
 * PRICED line, so it never reaches this refusal.
 *
 * ── WHY \RuntimeException AND NOT \DomainException ──
 * `PurchaseOrderController::confirm()` maps EVERY `\DomainException` to
 * `INVALID_STATUS_TRANSITION`, and "this line has no price" is not a status-transition
 * error — the document is a perfectly valid draft. A distinct type, caught FIRST, is
 * what makes an honest `PO_LINE_UNPRICED` reachable by the operator. Same reasoning,
 * and same shape, as `InsufficientStockForFulfilmentException` (campaign N-2).
 *
 * ── EXCEPTION TEXT vs OPERATOR TEXT ──
 * `getMessage()` is the DEVELOPER/log string and stays English by design. The OPERATOR
 * text is built by the controller from `self::TRANSLATION_KEY` +
 * `translationReplacements()`, so the 422 body renders in the tenant's locale (rule 11).
 */
final class UnpricedPurchaseOrderLineException extends \RuntimeException
{
    /**
     * Machine code surfaced to API clients as `error.code`.
     *
     * @see docs/conventions/01-API-RESPONSES.md
     */
    public const string ERROR_CODE = 'PO_LINE_UNPRICED';

    /** Translation key for the OPERATOR-facing message (the `documents` lang file). */
    public const string TRANSLATION_KEY = 'documents.purchase_order.line_unpriced';

    /**
     * @param  int  $lineNumber  1-based position of the first unpriced line.
     * @param  string|null  $lineDescription  The line's description, for the operator.
     */
    public function __construct(
        public readonly string $documentId,
        public readonly int $lineNumber,
        public readonly ?string $lineDescription,
    ) {
        parent::__construct(
            "Purchase order line {$lineNumber} ('".($lineDescription ?? 'unnamed')."') has no unit price. "
            .'An unpriced line cannot be confirmed.'
        );
    }

    /**
     * Placeholders for `__(self::TRANSLATION_KEY, …)`.
     *
     * @return array<string, string>
     */
    public function translationReplacements(): array
    {
        return [
            'line' => (string) $this->lineNumber,
            'description' => $this->lineDescription ?? '',
        ];
    }
}
