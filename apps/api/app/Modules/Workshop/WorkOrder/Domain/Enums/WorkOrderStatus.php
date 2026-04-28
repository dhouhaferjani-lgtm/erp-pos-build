<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Enums;

/**
 * Lifecycle status of a WorkOrder. Drives the Spec §5.3 status machine.
 *
 * Terminal states: Closed, Cancelled.
 */
enum WorkOrderStatus: string
{
    case Received = 'received';
    case Diagnosed = 'diagnosed';
    case Quoted = 'quoted';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case WaitingParts = 'waiting_parts';
    case Completed = 'completed';
    case Invoiced = 'invoiced';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Closed, self::Cancelled => true,
            self::Received,
            self::Diagnosed,
            self::Quoted,
            self::Approved,
            self::InProgress,
            self::Paused,
            self::WaitingParts,
            self::Completed,
            self::Invoiced => false,
        };
    }
}
