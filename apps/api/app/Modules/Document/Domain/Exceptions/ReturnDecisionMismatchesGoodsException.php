<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

/**
 * A "there are no goods" decision was posted for an invoice that HAS goods.
 *
 * Gate CF round 1, FE Blocker B3. `not_applicable` and `no_goods_issued` are not user
 * opinions — they are the SERVER's reading of the invoice, and the modal merely reports
 * them. But nothing enforced that: `requiresReturnDecision()` and `hasGoodsIssued()`
 * existed only as a read model, so any client could post `not_applicable` for an invoice
 * with delivered physical goods and the composite would record it.
 *
 * That is not a hypothetical. The modal computed
 * `requiresDecision = canCancel?.requires_return_decision ?? false` — failing OPEN, the
 * direction that SKIPS the goods question entirely — so a user clicking Cancel before
 * `/can-cancel` returned, or permanently if that endpoint errored, got a modal with no
 * goods question at all and posted `not_applicable`. A false statement about physical
 * reality, written to `payload.return_decisions` and surfaced by T16 as "This invoice has
 * no physical products."
 *
 * The modal is fixed to fail CLOSED, but a UI default is affordance, not a safety
 * property — the same argument CF-D6 makes for `RETURN_NOTHING_DELIVERED`, which is this
 * refusal's mirror image (that one refuses a goods decision when there are no goods;
 * this one refuses a no-goods decision when there are). Together they make the owner
 * ruling's "explicit, never silent" enforceable at the boundary rather than merely
 * offered in pixels.
 */
final class ReturnDecisionMismatchesGoodsException extends DomainException
{
    public const CODE = 'RETURN_DECISION_MISMATCHES_GOODS';

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $invoiceNumber,
        public readonly string $postedMode,
        public readonly bool $requiresReturnDecision,
        public readonly bool $goodsIssued,
    ) {
        parent::__construct(
            "Invoice {$invoiceNumber} carries goods, so '{$postedMode}' cannot be recorded for it. "
            .'Reload the page and choose what is happening to the products.'
        );
    }
}
