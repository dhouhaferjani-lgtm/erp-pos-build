<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\SuperAdmin;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Str;

class AdminAuditService
{
    public function log(
        SuperAdmin $admin,
        string $action,
        ?Tenant $tenant = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $notes = null
    ): AdminAuditLog {
        return AdminAuditLog::create([
            'id' => Str::uuid()->toString(),
            'super_admin_id' => $admin->id,
            'tenant_id' => $tenant?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'notes' => $notes,
        ]);
    }

    public function logTenantAction(
        SuperAdmin $admin,
        Tenant $tenant,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $notes = null
    ): AdminAuditLog {
        return $this->log(
            admin: $admin,
            action: $action,
            tenant: $tenant,
            entityType: 'tenant',
            entityId: $tenant->id,
            oldValues: $oldValues,
            newValues: $newValues,
            notes: $notes
        );
    }
}
