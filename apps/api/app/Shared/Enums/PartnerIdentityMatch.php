<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum PartnerIdentityMatch: string
{
    case Code = 'code';
    case VatNumber = 'vat_number';
    case Name = 'name';
}
