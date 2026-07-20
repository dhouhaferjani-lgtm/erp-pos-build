<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum StatementParserKey: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
}
