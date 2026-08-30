<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum RepositoryCensusCode: string
{
    case CashLocationNull = 'repo.cash.location_null';
    case SafeCountNotOne = 'repo.safe.count_ne_1';
    case SafeGlUnlinked = 'repo.safe.gl_unlinked';
    case SafeDuplicateClean = 'repo.safe.duplicate_clean';
    case DuplicateMoneyBearing = 'repo.duplicate.money_bearing';
    case DuplicatePerLocationType = 'repo.duplicate.per_location_type';
}
