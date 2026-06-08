<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a sales order is confirmed — version 2.
 *
 * Supersedes SalesOrderConfirmed (V1) whose per-line payload entries lacked the
 * variant dimension. Each entry of the V2 $lines array adds a `variant_id` key
 * (nullable). Both V1 and V2 are fired together (dual-dispatch) so existing
 * listeners are not broken.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create SalesOrderConfirmedV3.
 */
final class SalesOrderConfirmedV2 extends DomainEvent
{
    /**
     * @param  array<int, array{line_id: string, product_id: string, variant_id: string|null, quantity: string, location_id: string}>  $lines
     */
    public function __construct(
        public readonly string $salesOrderId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $documentNumber,
        public readonly string $partnerId,
        public readonly string $total,
        public readonly string $currency,
        public readonly array $lines,
        public readonly string $confirmedBy,
        public readonly string $confirmedAt,
    ) {
        parent::__construct($salesOrderId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'sales_order.confirmed.v2';
    }
}
