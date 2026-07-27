<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum StatementMatchType: string
{
    case Manual = 'manual';
    case SuggestionConfirmed = 'suggestion_confirmed';
    case CreatedFromLine = 'created_from_line';
}
