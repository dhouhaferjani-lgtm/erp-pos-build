<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum CrossReferenceType: string
{
    case Oe = 'oe';
    case Oem = 'oem';
    case Trade = 'trade';
    case Iam = 'iam';
    case Ean = 'ean';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Oe => 'OE (Original Equipment)',
            self::Oem => 'OEM (Original Equipment Manufacturer)',
            self::Trade => 'Trade Number',
            self::Iam => 'IAM (Independent Aftermarket)',
            self::Ean => 'EAN / Barcode',
            self::Internal => 'Internal Reference',
        };
    }
}
