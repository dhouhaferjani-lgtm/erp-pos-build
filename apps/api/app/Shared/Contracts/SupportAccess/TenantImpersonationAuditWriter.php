<?php

declare(strict_types=1);

namespace App\Shared\Contracts\SupportAccess;

use App\Shared\DTOs\SupportAccess\GrantAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;

interface TenantImpersonationAuditWriter
{
    public function writeImpersonationMirror(ImpersonationAuditMirrorData $event): void;

    public function writeGrantMirror(GrantAuditMirrorData $event): void;
}
