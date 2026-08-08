<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment is reversed.
 *
 * Dispatched from PaymentRefundService::reversePayment().
 * Immutable — never modify once deployed.
 *
 * DPA V4 (D-15) added TWO TRAILING OPTIONAL params. Additive only: no existing
 * field changed meaning, no field was removed, and every construction site uses
 * named arguments. There is deliberately NO `PaymentReversedV2`.
 *
 * `$amount` still carries the ORIGINAL payment's amount — that semantic is
 * frozen. Post-V4 a reversal is NET of the refund lineage, so reversing a 1000
 * payment already refunded 400 emits `amount: 1000` for a reversal that unwound
 * 600. `$reversedAmount` carries the net, so the audit trail is complete without
 * mutating the frozen field.
 */
final class PaymentReversed extends DomainEvent
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $reversedAt,
        public readonly ?string $reversalPaymentId = null,
        public readonly ?string $reversedAmount = null,
    ) {
        parent::__construct($paymentId);
    }

    public function getEventName(): string
    {
        return 'treasury.payment.reversed';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reversed_at' => $this->reversedAt,
            // DPA V4 (D-15): `?? ''` keeps the declared array<string, string>
            // return type intact for pre-V4 constructions.
            'reversal_payment_id' => $this->reversalPaymentId ?? '',
            'reversed_amount' => $this->reversedAmount ?? '',
        ];
    }
}
