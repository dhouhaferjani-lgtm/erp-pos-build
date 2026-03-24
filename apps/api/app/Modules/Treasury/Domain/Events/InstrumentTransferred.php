<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment instrument is transferred between repositories.
 *
 * Dispatched from PaymentInstrumentController::transfer().
 * Immutable — never modify once deployed.
 */
final class InstrumentTransferred extends DomainEvent
{
    public function __construct(
        public readonly string $instrumentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $fromRepositoryId,
        public readonly string $toRepositoryId,
        public readonly string $amount,
        public readonly string $transferredAt,
    ) {
        parent::__construct($instrumentId);
    }

    public function getEventName(): string
    {
        return 'treasury.instrument.transferred';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'instrument_id' => $this->instrumentId,
            'from_repository_id' => $this->fromRepositoryId,
            'to_repository_id' => $this->toRepositoryId,
            'amount' => $this->amount,
            'transferred_at' => $this->transferredAt,
        ];
    }
}
