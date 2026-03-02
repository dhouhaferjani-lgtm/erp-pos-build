<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case OptedOut = 'opted_out';
}
