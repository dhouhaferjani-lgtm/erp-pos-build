<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum InstrumentEventType: string
{
    case Created = 'created';
    case DetailsUpdated = 'details_updated';
    case CustodyTransferred = 'custody_transferred';
    case Remitted = 'remitted';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
    case RePresented = 're_presented';
    case Cancelled = 'cancelled';
}
