<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum FacturXProfile: string
{
    case Minimum = 'minimum';
    case BasicWL = 'basicwl';
    case Basic = 'basic';
    case EN16931 = 'en16931';
    case Extended = 'extended';
}
