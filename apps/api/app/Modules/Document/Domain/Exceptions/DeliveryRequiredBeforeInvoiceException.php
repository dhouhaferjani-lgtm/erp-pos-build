<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

/**
 * A definitive goods invoice was about to be POSTED before its goods left, in a
 * jurisdiction whose seeded policy is `require_delivery_first` (Wave 3 T25b).
 *
 * ── WHY THIS IS TYPED AND NOT A BARE `\DomainException` (inv R-4) ──
 * Before Wave 3 the only *guided* delivery refusal lived in the controller,
 * while the service threw an untyped `\DomainException` that the controller
 * flattened to `POSTING_FAILED`. Under GUIDED-REQUIRE the refusal IS the
 * compliance control, and a compliance control must not depend on which entry
 * point raised it: it is raised in `DocumentPostingService::post()`, carries the
 * resolved policy AND its source, and names the compliant alternatives so the
 * operator has somewhere to go. A refusal with no reachable compliant path is
 * worked around by back-dating or by cancel-and-recreate — which is worse than
 * the thing it was meant to prevent.
 *
 * ── T25d: refuse-and-REDIRECT, and nothing else ──
 * The alternatives below are POINTERS. Wave 3 creates, allocates and posts no
 * advance payment and touches no Treasury allocation code — the advance-reversal
 * lane is frozen with a live red defect. Proforma invoices do not exist in this
 * system and are explicitly out of scope.
 */
final class DeliveryRequiredBeforeInvoiceException extends \DomainException
{
    /**
     * @param  list<array{id: string, number: string, total: string, line_count: int}>  $draftDeliveryNotes
     */
    public function __construct(
        public readonly string $policy,
        public readonly string $policySource,
        public readonly array $draftDeliveryNotes,
        public readonly bool $canAutoConfirm,
        public readonly ?string $blockedReason,
    ) {
        parent::__construct(__('documents.pre_delivery_invoicing.refused'));
    }

    /**
     * The compliant paths, in the order an operator should consider them.
     *
     * @return list<array{action: string, description: string}>
     */
    public function alternatives(): array
    {
        return [
            [
                'action' => 'create_and_confirm_delivery_note',
                'description' => __('documents.pre_delivery_invoicing.alternative_delivery_note'),
            ],
            [
                // The shipped advance path: quote/order + an advance payment
                // (`PaymentType::Advance` → `SystemAccountPurpose::CustomerAdvance`).
                // Wave 3 only POINTS at it.
                'action' => 'order_with_advance_payment',
                'description' => __('documents.pre_delivery_invoicing.alternative_advance_payment'),
            ],
        ];
    }

    /**
     * The wire payload for a 422.
     *
     * @return array{
     *     policy: string,
     *     policy_source: string,
     *     draft_dns: list<array{id: string, number: string, total: string, line_count: int}>,
     *     can_auto_confirm: bool,
     *     blocked_reason: string|null,
     *     alternatives: list<array{action: string, description: string}>
     * }
     */
    public function toPayload(): array
    {
        return [
            'policy' => $this->policy,
            'policy_source' => $this->policySource,
            'draft_dns' => $this->draftDeliveryNotes,
            'can_auto_confirm' => $this->canAutoConfirm,
            'blocked_reason' => $this->blockedReason,
            'alternatives' => $this->alternatives(),
        ];
    }
}
