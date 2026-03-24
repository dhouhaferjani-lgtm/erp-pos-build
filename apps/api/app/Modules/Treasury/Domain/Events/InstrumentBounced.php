<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment instrument bounces.
 *
 * Dispatched from PaymentInstrumentController::bounce().
 * Immutable — never modify once deployed.
 */
final class InstrumentBounced extends DomainEvent
{
    public function __construct(
        public readonly string $instrumentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $amount,
        public readonly string $reason,
        public readonly string $bouncedAt,
    ) {
        parent::__construct($instrumentId);
    }

    public function getEventName(): string
    {
        return 'treasury.instrument.bounced';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'instrument_id' => $this->instrumentId,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'bounced_at' => $this->bouncedAt,
        ];
    }
}
