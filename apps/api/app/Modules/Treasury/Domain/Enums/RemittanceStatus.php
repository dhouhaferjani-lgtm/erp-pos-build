<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum RemittanceStatus: string
{
    case Draft = 'draft';
    case Remitted = 'remitted';
    case Closed = 'closed';
}
