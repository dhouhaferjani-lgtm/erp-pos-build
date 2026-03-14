<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a cash drawer operation (deposit, payout, refund) is recorded.
 *
 * NF525 cash movement events (DEPOT_ESPECES, RETRAIT_ESPECES, REMBOURSEMENT)
 * must be tracked for complete cash drawer audit trail.
 */
final class CashDrawerOperationRecorded extends DomainEvent
{
    public function __construct(
        public readonly string $operationId,
        public readonly string $companyId,
        public readonly string $shiftId,
        public readonly string $terminalId,
        public readonly string $operationType,
        public readonly string $amount,
        public readonly string $userId,
        public readonly string $recordedAt,
    ) {
        parent::__construct($operationId);
    }

    public function getEventName(): string
    {
        return 'cash_drawer.operation_recorded';
    }
}
