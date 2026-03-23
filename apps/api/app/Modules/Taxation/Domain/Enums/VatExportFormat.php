<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum VatExportFormat: string
{
    case Pdf = 'PDF';
    case Csv = 'CSV';
    case Fec = 'FEC';
    case MtdJson = 'MTD_JSON';
    case TeifXml = 'TEIF_XML';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Csv => 'CSV',
            self::Fec => 'FEC',
            self::MtdJson => 'MTD JSON',
            self::TeifXml => 'TEIF XML',
        };
    }
}
