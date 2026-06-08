<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum AttributeDataType: string
{
    case Text = 'text';
    case Numeric = 'numeric';
    case Boolean = 'boolean';
    case Date = 'date';
    case Selection = 'selection';
    case Color = 'color';
    case Image = 'image';
}
