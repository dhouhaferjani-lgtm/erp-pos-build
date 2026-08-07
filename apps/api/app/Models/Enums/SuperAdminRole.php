<?php

declare(strict_types=1);

namespace App\Models\Enums;

enum SuperAdminRole: string
{
    case SuperAdmin = 'super_admin';
    case SupportApprover = 'support_approver';
}
