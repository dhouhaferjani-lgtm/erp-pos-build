<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Enums;

enum ImpersonationActionDecision: string
{
    case Safe = 'safe';
    case RequiresElevation = 'requires_elevation';
    case HardBlocked = 'hard_blocked';
}
