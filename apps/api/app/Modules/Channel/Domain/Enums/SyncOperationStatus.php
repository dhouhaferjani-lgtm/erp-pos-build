<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Enums;

enum SyncOperationStatus: string
{
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case Failed = 'failed';
    case Retried = 'retried';
}
