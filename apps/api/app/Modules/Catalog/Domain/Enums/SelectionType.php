<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum SelectionType: string
{
    case Single = 'single';
    case Multiple = 'multiple';
}
