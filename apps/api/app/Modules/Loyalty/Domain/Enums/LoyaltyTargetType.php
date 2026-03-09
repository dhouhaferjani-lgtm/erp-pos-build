<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

enum LoyaltyTargetType: string
{
    case Contact = 'contact';
    case Partner = 'partner';
}
