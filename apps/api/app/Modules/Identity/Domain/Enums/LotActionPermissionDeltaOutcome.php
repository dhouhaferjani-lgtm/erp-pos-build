<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Enums;

enum LotActionPermissionDeltaOutcome: string
{
    case Applied = 'APPLIED';
    case AlreadyApplied = 'ALREADY_APPLIED';
    case Skipped = 'SKIPPED';
    case Failed = 'FAILED';
}
