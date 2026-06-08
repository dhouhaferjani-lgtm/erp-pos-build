<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

final class TenantBackupResult
{
    public function __construct(
        public readonly string $tenantBackupId,
        public readonly string $tenantId,
        public readonly string $filePath,
        public readonly int $fileSizeBytes,
        public readonly string $sha256,
    ) {}
}
