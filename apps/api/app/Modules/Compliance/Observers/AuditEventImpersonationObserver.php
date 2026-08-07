<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Observers;

use App\Modules\Compliance\Domain\AuditEvent;
use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;

final class AuditEventImpersonationObserver
{
    public function __construct(private readonly ImpersonationContextProvider $context) {}

    public function creating(AuditEvent $event): void
    {
        $impersonation = $this->context->current();
        if ($impersonation === null) {
            return;
        }

        $event->forceFill([
            'impersonator_id' => $event->getAttribute('impersonator_id') ?? $impersonation->operator_id,
            'impersonation_session_id' => $event->getAttribute('impersonation_session_id') ?? $impersonation->session_id,
            'impersonation_event_id' => $event->getAttribute('impersonation_event_id') ?? $impersonation->audit_event_id,
            'impersonation_sequence' => $event->getAttribute('impersonation_sequence') ?? $impersonation->audit_sequence,
            'impersonation_previous_hash' => $event->getAttribute('impersonation_previous_hash') ?? $impersonation->audit_previous_hash,
            'impersonation_hash' => $event->getAttribute('impersonation_hash') ?? $impersonation->audit_hash,
        ]);
    }
}
