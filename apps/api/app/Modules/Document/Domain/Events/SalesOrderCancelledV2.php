<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Nullable-number successor for cancellations of abandonable sales-order drafts.
 */
final class SalesOrderCancelledV2 extends DomainEvent
{
    public function __construct(
        public readonly string $salesOrderId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly ?string $documentNumber,
        public readonly string $draftReference,
        public readonly string $partnerId,
        public readonly string $cancellationReason,
        public readonly string $cancelledBy,
        public readonly string $cancelledAt,
    ) {
        parent::__construct($salesOrderId);
    }

    public function getEventName(): string
    {
        return 'sales_order.cancelled';
    }
}
