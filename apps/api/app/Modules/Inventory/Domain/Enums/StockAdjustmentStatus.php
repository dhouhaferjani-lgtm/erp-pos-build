<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * `stock_adjustments` lifecycle states (DPA V7 / D5).
 *
 * draft     — authored, nothing posted; the only editable and cancellable state
 * posted    — movements written, numbering stamped; TERMINAL (correct by contra)
 * cancelled — the draft was abandoned; TERMINAL
 *
 * The house has two idioms — GoodsReceiptStatus is a bare 2-case enum with an
 * inline `!== Draft` assert, TransferStatus carries per-action `canBeX()`. This
 * one follows CountingStatus: a TOTAL `allowedTransitions()` with no `default`,
 * so adding a case is a compile-time error, terminal states map to `[]`, and the
 * typed exception can report the legal set to the client.
 */
enum StockAdjustmentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Posted, self::Cancelled],
            self::Posted => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }
}
