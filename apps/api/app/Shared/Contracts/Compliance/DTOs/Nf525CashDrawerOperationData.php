<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a cash-drawer operation for NF525 export.
 *
 * Immutable view of POS\Domain\CashDrawerOperation.
 */
final readonly class Nf525CashDrawerOperationData
{
    public function __construct(
        public string $id,
        public string $shiftId,
        /** Operation type from the source: "DEPOSIT", "PAYOUT", "REFUND". */
        public string $operationType,
        public string $amount,
        public string $userId,
        public ?string $reason,
        public string $createdAtIso8601,
    ) {}
}
