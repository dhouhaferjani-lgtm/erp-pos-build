<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Enums;

enum ChannelConnectionStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Failed = 'failed';
    case Suspended = 'suspended';
}
