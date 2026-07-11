<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum RemittanceLineStatus: string
{
    case Pending = 'pending';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
}
