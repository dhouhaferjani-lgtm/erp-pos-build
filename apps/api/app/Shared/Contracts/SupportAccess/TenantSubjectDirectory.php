<?php

declare(strict_types=1);

namespace App\Shared\Contracts\SupportAccess;

interface TenantSubjectDirectory
{
    public function belongsToTenant(string $tenantId, string $subjectUserId): bool;
}
