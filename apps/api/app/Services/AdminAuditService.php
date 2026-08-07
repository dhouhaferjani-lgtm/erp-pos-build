<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\SuperAdmin;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\SupportAccess\AdminImpersonationAuditWriter;
use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;
use App\Shared\DTOs\SupportAccess\GrantAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAuditService implements AdminImpersonationAuditWriter
{
    public function __construct(
        private readonly ImpersonationContextProvider $impersonationContext,
        private readonly Request $request,
    ) {}

    public function writeImpersonationMirror(ImpersonationAuditMirrorData $event): void
    {
        AdminAuditLog::query()->firstOrCreate([
            'impersonation_event_id' => $event->event_id,
            'entity_type' => 'impersonation_session',
            'action' => 'impersonation_'.$event->event_type,
        ], [
            'super_admin_id' => $event->operator_id,
            'tenant_id' => $event->tenant_id,
            'entity_id' => $event->session_id,
            'new_values' => [
                'outcome' => $event->outcome,
                'method' => $event->http_method,
                'path' => $event->path,
                'details' => $event->details,
                'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ],
            'notes' => 'Consent-gated support access audit mirror.',
            'ip_address' => $event->details['request_ip'] ?? null,
            'user_agent' => $event->details['user_agent'] ?? null,
            'impersonator_id' => $event->operator_id,
            'impersonation_session_id' => $event->session_id,
            'impersonation_sequence' => $event->sequence,
            'impersonation_previous_hash' => $event->previous_hash,
            'impersonation_hash' => $event->hash,
        ]);
    }

    public function writeGrantMirror(GrantAuditMirrorData $event): void
    {
        AdminAuditLog::query()->firstOrCreate([
            'impersonation_event_id' => $event->event_id,
            'entity_type' => 'impersonation_grant',
            'action' => 'impersonation_'.$event->event_type,
        ], [
            'super_admin_id' => $event->operator_id,
            'tenant_id' => $event->tenant_id,
            'entity_id' => $event->grant_id,
            'new_values' => [
                'outcome' => $event->outcome,
                'actor_id' => $event->actor_id,
                'actor_type' => $event->actor_type,
                'details' => $event->details,
                'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ],
            'notes' => 'Consent-gated support grant audit mirror.',
            'ip_address' => $event->details['request_ip'] ?? null,
            'user_agent' => $event->details['user_agent'] ?? null,
            'impersonator_id' => $event->operator_id,
            'impersonation_session_id' => null,
            'impersonation_sequence' => $event->sequence,
            'impersonation_previous_hash' => $event->previous_hash,
            'impersonation_hash' => $event->hash,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
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
        $impersonation = $this->impersonationContext->current();

        return AdminAuditLog::create([
            'id' => Str::uuid()->toString(),
            'super_admin_id' => $admin->id,
            'tenant_id' => $tenant?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'notes' => $notes,
            'impersonator_id' => $impersonation?->operator_id,
            'impersonation_session_id' => $impersonation?->session_id,
            'impersonation_event_id' => $impersonation?->audit_event_id,
            'impersonation_sequence' => $impersonation?->audit_sequence,
            'impersonation_previous_hash' => $impersonation?->audit_previous_hash,
            'impersonation_hash' => $impersonation?->audit_hash,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
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
