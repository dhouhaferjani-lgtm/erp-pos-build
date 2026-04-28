<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Events;

/**
 * Emitted when an Appointment transitions Scheduled → Confirmed.
 *
 * Triggered by customer confirmation (SMS reply, phone call back) or by
 * operator-initiated confirmation in the dashboard.
 */
final readonly class AppointmentConfirmed
{
    public function __construct(
        public string $appointment_id,
        public string $tenant_id,
        public string $company_id,
        public ?string $confirmed_by_user_id,
        public \DateTimeImmutable $occurred_at,
    ) {}
}
