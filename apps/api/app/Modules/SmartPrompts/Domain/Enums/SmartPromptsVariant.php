<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Domain\Enums;

enum SmartPromptsVariant: string
{
    case Inline = 'inline';
    case Toast = 'toast';
    case Off = 'off';
}
