<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment instrument is deposited into a repository.
 *
 * Dispatched from PaymentInstrumentController::deposit().
 * Immutable — never modify once deployed.
 */
final class InstrumentDeposited extends DomainEvent
{
    public function __construct(
        public readonly string $instrumentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $repositoryId,
        public readonly string $amount,
        public readonly string $depositedAt,
    ) {
        parent::__construct($instrumentId);
    }

    public function getEventName(): string
    {
        return 'treasury.instrument.deposited';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'instrument_id' => $this->instrumentId,
            'repository_id' => $this->repositoryId,
            'amount' => $this->amount,
            'deposited_at' => $this->depositedAt,
        ];
    }
}
