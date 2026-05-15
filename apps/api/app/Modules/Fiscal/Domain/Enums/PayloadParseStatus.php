<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum PayloadParseStatus: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Failed = 'failed';
}
