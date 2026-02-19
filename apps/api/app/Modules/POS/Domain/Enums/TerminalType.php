<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum TerminalType: string
{
    case Web = 'web';
    case Physical = 'physical';
}
