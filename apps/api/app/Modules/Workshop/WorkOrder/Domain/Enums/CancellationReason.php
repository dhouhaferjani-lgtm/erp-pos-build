<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Enums;

/**
 * Reason code recorded on a WorkOrder transitioning to Cancelled. Required for
 * audit, billing reversal flow, and downstream analytics.
 */
enum CancellationReason: string
{
    case CustomerDeclined = 'customer_declined';
    case CustomerNoShow = 'customer_no_show';
    case InternalError = 'internal_error';
    case Duplicate = 'duplicate';
    case VehicleUnfit = 'vehicle_unfit';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
