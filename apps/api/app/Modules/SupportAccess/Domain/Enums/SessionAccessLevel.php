<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Enums;

enum SessionAccessLevel: string
{
    case ReadOnly = 'read_only';
    case WriteElevated = 'write_elevated';
}
