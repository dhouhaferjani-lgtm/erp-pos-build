<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Enums;

enum GrantType: string
{
    case PerIncident = 'per_incident';
    case PreGrantedWindow = 'pre_granted_window';
}
