<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum RemittanceType: string
{
    case Collection = 'collection';
    case Discount = 'discount';
}
