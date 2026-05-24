<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum FiscalIntegrityPolicyAction: string
{
    case AcceptAndQuarantine = 'accept_and_quarantine';
    case RequireAcknowledgment = 'require_acknowledgment';
    case Inactive = 'inactive';
}
