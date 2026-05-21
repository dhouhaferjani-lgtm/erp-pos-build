<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum IntegrityStatus: string
{
    case Verified = 'verified';
    case Quarantined = 'quarantined';
}
