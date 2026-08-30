<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

enum DuplicatePolicy: string
{
    case Override = 'override';
    case Skip = 'skip';
}
