<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a partner's cached balance is refreshed from GL.
 *
 * Dispatched from PartnerBalanceService::refreshPartnerBalance() — covers
 * all callers (invoice posting, payment creation, credit notes, etc.).
 *
 * NOT part of fiscal hash chain - used for real-time UI updates only.
 */
final class PartnerBalanceUpdated extends DomainEvent
{
    public function __construct(
        public readonly string $partnerId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $receivableBalance,
        public readonly string $creditBalance,
        public readonly string $payableBalance,
        public readonly string $netBalance,
    ) {
        parent::__construct($partnerId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'partner.balance_updated';
    }
}
