<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum RepositoryNormalisationAction: string
{
    case DeactivateSurplusSafe = 'deactivate_surplus_safe';
    case LinkCanonicalSafe = 'link_canonical_safe';
}
