<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

enum PriceEntryMode: string
{
    case Ht = 'Ht';
    case Ttc = 'Ttc';
}
