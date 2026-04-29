<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum ExchangeRequestStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
