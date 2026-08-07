<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Enums;

enum GrantStatus: string
{
    case PendingTenantApproval = 'pending_tenant_approval';
    case PendingInternalApproval = 'pending_internal_approval';
    case Active = 'active';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
