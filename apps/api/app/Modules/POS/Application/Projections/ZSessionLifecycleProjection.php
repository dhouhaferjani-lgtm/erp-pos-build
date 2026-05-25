<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\ZSessionEvent;
use RuntimeException;

final class ZSessionLifecycleProjection implements FiscalEventProjector
{
    public function name(): string
    {
        return 'pos_core_z_session_lifecycle';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return in_array($type, [
            FiscalEventType::SESSION_OPEN,
            FiscalEventType::OPENING_FLOAT,
            FiscalEventType::CASH_IN,
            FiscalEventType::CASH_OUT,
            FiscalEventType::SAFE_DROP,
            FiscalEventType::CASH_CORRECTION,
            FiscalEventType::SESSION_CLOSE,
            FiscalEventType::X_REPORT,
        ], true);
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function priority(): int
    {
        return 50;
    }

    public function apply(FiscalEvent $event): void
    {
        if (! in_array($event->chain_context, ['z_session', 'training_z_session'], true)) {
            return;
        }

        if (ZSessionEvent::query()->where('fiscal_event_id', $event->id)->exists()) {
            return;
        }

        $payload = $event->payload;
        if (! is_array($payload)) {
            throw new RuntimeException(sprintf(
                'Cannot project Z-session fiscal event %s without parsed payload.',
                $event->id,
            ));
        }

        $sessionId = $payload['session_id'] ?? null;
        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException(sprintf(
                'Cannot project Z-session fiscal event %s without payload.session_id.',
                $event->id,
            ));
        }

        $shiftId = $payload['shift_id'] ?? null;

        ZSessionEvent::query()->create([
            'id' => (string) $event->id,
            'fiscal_event_id' => (string) $event->id,
            'tenant_id' => (string) $event->tenant_id,
            'company_id' => (string) $event->company_id,
            'terminal_id' => (string) $event->terminal_id,
            'shift_id' => is_string($shiftId) && $shiftId !== '' ? $shiftId : null,
            'session_id' => $sessionId,
            'event_type' => $event->event_type->value,
            'chain_context' => (string) $event->chain_context,
            'sequence_number' => (int) $event->sequence_number,
            'previous_hash' => (string) $event->previous_hash,
            'current_hash' => (string) $event->current_hash,
            'business_date' => $event->business_date,
            'event_time_device' => $event->event_time_device,
            'payload' => $payload,
        ]);
    }
}
