<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum PrintMethod: string
{
    case Pdf = 'pdf';
    case Thermal = 'thermal';
    case EscPos = 'escpos';
}
