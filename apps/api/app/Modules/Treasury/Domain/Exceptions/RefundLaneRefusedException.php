<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use App\Modules\Treasury\Domain\Enums\PaymentType;
use DomainException;

/**
 * F-W2-13 (P0) — the CUSTOMER refund lane was asked to undo a payment whose undo
 * is not the AR shape this lane posts.
 *
 * WHY A TYPED EXCEPTION AND NOT A BARE `\DomainException` (fix round 1, gate r1
 * finding #2). `PaymentRefundController::refundPayment()`/`partialRefund()`
 * flatten every `\Exception` to `{"error": "<message>"}` — a plain STRING, which
 * `apps/web/src/lib/api.ts` `getErrorMessage()` cannot read (it looks for
 * `data.error.message`), so the operator saw "Request failed with status code
 * 422". A typed refusal lets the controller answer with the house envelope
 * `{"error":{"code","message","details"}}` and render the operator text from
 * `lang/<locale>/treasury.php` (rule 11 — nothing user-facing hardcoded).
 *
 * The `getMessage()` text is the TECHNICAL one (logs, tests, non-HTTP callers);
 * `translationKey()` is what the boundary shows a human.
 */
final class RefundLaneRefusedException extends DomainException
{
    public function __construct(
        public readonly string $paymentId,
        public readonly PaymentType $paymentType,
    ) {
        parent::__construct(sprintf(
            'payment %s is a %s: it settles a supplier invoice, so it cannot be refunded through the '
            .'customer refund lane, which posts Dr customer receivable / Cr cash with cash OUT. Undoing it '
            .'needs the supplier-side shape (Dr supplier payable / Cr bank reversed, cash IN), which no lane '
            .'implements yet.',
            $paymentId,
            $paymentType->value,
        ));
    }

    /**
     * The operator-facing text key in `lang/<locale>/treasury.php`.
     *
     * EXHAUSTIVE `match`, no `default`: a future `PaymentType` that this lane
     * decides to refuse must be given its own operator wording rather than
     * silently rendering a missing key. Every arm other than `SupplierPayment` is
     * unreachable today — `PaymentRefundService::assertRefundableType()` throws
     * this exception for supplier payments ONLY (F-W2-13 is a supplier finding;
     * POS/POSRefund are deliberately NOT refused here — gate r1 findings #1/#4).
     */
    public function translationKey(): string
    {
        return match ($this->paymentType) {
            PaymentType::SupplierPayment => 'treasury.refund_refused.supplier_payment',
            PaymentType::DocumentPayment,
            PaymentType::Advance,
            PaymentType::CreditApplication,
            PaymentType::POS,
            PaymentType::POSRefund,
            PaymentType::Refund,
            PaymentType::Reversal => throw new \LogicException(
                "RefundLaneRefusedException has no operator wording for {$this->paymentType->value}; "
                .'add a `treasury.refund_refused.*` key before refusing this shape.'
            ),
        };
    }
}
