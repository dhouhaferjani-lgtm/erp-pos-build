<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum RepositoryLocationAttributionVerdict: string
{
    case Ambiguous = 'ambiguous';
    case Attributable = 'attributable';
}
