<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

enum OperatorApprovalDecision: string
{
    case Approved = 'approved';
    case InvalidPin = 'invalid_pin';
    case ScopeMismatch = 'scope_mismatch';
    case PermissionDenied = 'permission_denied';
}
