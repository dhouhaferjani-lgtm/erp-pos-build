<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Enums;

enum SessionEndReason: string
{
    case Exited = 'exited';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case GrantRevoked = 'grant_revoked';
    case OperatorEnded = 'operator_ended';
}
