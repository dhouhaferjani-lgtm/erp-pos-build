<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum MovementReasonCode: string
{
    case CountVariance = 'count_variance';
    case Correction = 'correction';
    case TheftLoss = 'theft_loss';
    case Other = 'other';
}
