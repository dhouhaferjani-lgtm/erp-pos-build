<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Enums;

enum ChannelOrderStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
