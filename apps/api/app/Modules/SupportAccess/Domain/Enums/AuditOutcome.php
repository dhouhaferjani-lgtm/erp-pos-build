<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Enums;

enum AuditOutcome: string
{
    case Observed = 'observed';
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Failed = 'failed';
}
