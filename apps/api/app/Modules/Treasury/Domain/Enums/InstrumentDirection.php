<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum InstrumentDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
