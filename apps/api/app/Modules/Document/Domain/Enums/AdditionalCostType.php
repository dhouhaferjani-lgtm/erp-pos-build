<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum AdditionalCostType: string
{
    case Transport = 'transport';
    case Shipping = 'shipping';
    case Insurance = 'insurance';
    case Customs = 'customs';
    case Handling = 'handling';
    case Other = 'other';
}
