<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Models\SuperAdmin;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\AdminAuditService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

final class TenantSensitivityService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly AdminAuditService $audit,
    ) {}

    public function change(SuperAdmin $admin, string $tenantId, bool $isSensitive, string $reason): Tenant
    {
        return $this->database->connection(
            $this->config->get('tenancy.database.central_connection'),
        )->transaction(function () use ($admin, $tenantId, $isSensitive, $reason): Tenant {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            $previous = (bool) $tenant->is_sensitive;
            $tenant->update(['is_sensitive' => $isSensitive]);

            $this->audit->logTenantAction(
                admin: $admin,
                tenant: $tenant,
                action: 'tenant_sensitivity_changed',
                oldValues: ['is_sensitive' => $previous],
                newValues: ['is_sensitive' => $isSensitive],
                notes: trim($reason),
            );

            return $tenant->refresh();
        });
    }
}
