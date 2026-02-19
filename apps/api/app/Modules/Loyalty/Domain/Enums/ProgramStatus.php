<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

enum ProgramStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
