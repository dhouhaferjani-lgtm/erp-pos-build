<?php

declare(strict_types=1);

namespace App\Shared\Contracts\SupportAccess;

use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;

interface SupportAccessNotifier
{
    public function grantRequested(ImpersonationGrant $grant): void;
}
