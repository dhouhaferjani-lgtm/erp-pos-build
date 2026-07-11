<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum InstrumentKind: string
{
    case Cheque = 'cheque';
    case Effet = 'effet';
    case Other = 'other';
}
