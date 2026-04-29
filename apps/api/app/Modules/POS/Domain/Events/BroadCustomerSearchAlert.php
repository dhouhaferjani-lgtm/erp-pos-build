<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Emitted when a cashier has searched the same partner's history too many times
 * in a single day, or when a cross-company history access is detected.
 *
 * Downstream listeners should notify the fraud/compliance team.
 *
 * Spec §2.6 / §4.7 — privacy alert thresholds from ReservationSettings:
 *   - same_partner_per_day: default 8
 *   - cross_company_immediate: default true
 */
final class BroadCustomerSearchAlert extends DomainEvent
{
    public function __construct(
        public readonly string $cashierId,
        public readonly string $partnerId,
        public readonly string $terminalId,
        public readonly string $tenantId,
        public readonly int $searchCountToday,
        public readonly string $alertReason,
        public readonly string $occurredAt,
    ) {
        parent::__construct($cashierId);
    }

    public function getEventName(): string
    {
        return 'pos.customer_search.broad_alert';
    }
}
