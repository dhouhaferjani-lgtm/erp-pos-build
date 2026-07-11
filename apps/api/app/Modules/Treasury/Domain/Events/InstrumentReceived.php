<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class InstrumentReceived extends DomainEvent
{
    public function __construct(
        public readonly string $instrumentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $amount,
        public readonly string $receivedAt,
    ) {
        parent::__construct($instrumentId);
    }

    public function getEventName(): string
    {
        return 'treasury.instrument.received';
    }

    /** @return array<string, string> */
    public function getAuditPayload(): array
    {
        return [
            'instrument_id' => $this->instrumentId,
            'amount' => $this->amount,
            'received_at' => $this->receivedAt,
        ];
    }
}
