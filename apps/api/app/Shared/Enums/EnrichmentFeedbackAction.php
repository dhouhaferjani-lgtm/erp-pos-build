<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum EnrichmentFeedbackAction: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
