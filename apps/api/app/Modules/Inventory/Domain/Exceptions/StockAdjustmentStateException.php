<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Enums\StockAdjustmentStatus;
use DomainException;

/**
 * Thrown when a stock-adjustment state transition is invalid (posting an already
 * posted document, editing a posted draft, cancelling a posted document, …).
 *
 * Modelled on TransferStateException (promoted `public readonly` props) so the
 * controller can emit structured `details` — plus the LEGAL set, which
 * StockAdjustmentStatus::allowedTransitions() can report because it is total
 * (DPA V7 / D5).
 */
class StockAdjustmentStateException extends DomainException
{
    /**
     * @param  list<StockAdjustmentStatus>  $allowed
     */
    public function __construct(
        public readonly string $adjustmentId,
        public readonly StockAdjustmentStatus $currentStatus,
        public readonly string $attemptedAction,
        public readonly array $allowed,
    ) {
        parent::__construct(
            "Cannot {$attemptedAction} stock adjustment {$adjustmentId} in status {$currentStatus->value}"
        );
    }

    public static function for(
        string $adjustmentId,
        StockAdjustmentStatus $currentStatus,
        string $attemptedAction,
    ): self {
        return new self(
            adjustmentId: $adjustmentId,
            currentStatus: $currentStatus,
            attemptedAction: $attemptedAction,
            allowed: $currentStatus->allowedTransitions(),
        );
    }

    /**
     * @return list<string>
     */
    public function allowedValues(): array
    {
        return array_map(
            static fn (StockAdjustmentStatus $status): string => $status->value,
            $this->allowed,
        );
    }
}
