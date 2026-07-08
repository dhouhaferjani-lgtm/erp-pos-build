<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Enums;

enum RegulatoryEnforcement: string
{
    case Advisory = 'Advisory';
    case Block = 'Block';
}
