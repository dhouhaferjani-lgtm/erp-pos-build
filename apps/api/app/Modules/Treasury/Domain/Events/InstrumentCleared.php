<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment instrument is cleared.
 *
 * Dispatched from PaymentInstrumentController::clear().
 * Immutable — never modify once deployed.
 */
final class InstrumentCleared extends DomainEvent
{
    public function __construct(
        public readonly string $instrumentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $amount,
        public readonly string $clearedAt,
    ) {
        parent::__construct($instrumentId);
    }

    public function getEventName(): string
    {
        return 'treasury.instrument.cleared';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'instrument_id' => $this->instrumentId,
            'amount' => $this->amount,
            'cleared_at' => $this->clearedAt,
        ];
    }
}
