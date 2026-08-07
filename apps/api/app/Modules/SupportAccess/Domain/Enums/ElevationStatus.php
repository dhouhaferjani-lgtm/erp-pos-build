<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Enums;

enum ElevationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
