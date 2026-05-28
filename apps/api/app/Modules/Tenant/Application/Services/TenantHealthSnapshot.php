<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

final class TenantHealthSnapshot
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $tenantSlug,
        public readonly string $tenantName,
        public readonly string $databaseName,
        public readonly bool $databaseExists,
        public readonly ?int $databaseSizeBytes,
        public readonly ?int $activeConnections,
        public readonly ?string $lastBackupCompletedAt,
        public readonly ?string $lastBackupStatus,
        public readonly ?string $lastBackupError,
    ) {}

    /**
     * @return array{
     *     tenant_id: string,
     *     tenant_slug: string,
     *     tenant_name: string,
     *     database_name: string,
     *     database_exists: bool,
     *     database_size_bytes: int|null,
     *     active_connections: int|null,
     *     last_backup_completed_at: string|null,
     *     last_backup_status: string|null,
     *     last_backup_error: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'tenant_slug' => $this->tenantSlug,
            'tenant_name' => $this->tenantName,
            'database_name' => $this->databaseName,
            'database_exists' => $this->databaseExists,
            'database_size_bytes' => $this->databaseSizeBytes,
            'active_connections' => $this->activeConnections,
            'last_backup_completed_at' => $this->lastBackupCompletedAt,
            'last_backup_status' => $this->lastBackupStatus,
            'last_backup_error' => $this->lastBackupError,
        ];
    }
}
