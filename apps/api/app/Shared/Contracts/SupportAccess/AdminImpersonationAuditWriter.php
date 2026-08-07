<?php

declare(strict_types=1);

namespace App\Shared\Contracts\SupportAccess;

use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;

interface AdminImpersonationAuditWriter
{
    public function writeImpersonationMirror(ImpersonationAuditMirrorData $event): void;
}
