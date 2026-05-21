<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum SignatureStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Signed = 'signed';
    case Failed = 'failed';
}
