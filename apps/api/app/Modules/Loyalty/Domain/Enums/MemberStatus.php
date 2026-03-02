<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
}
