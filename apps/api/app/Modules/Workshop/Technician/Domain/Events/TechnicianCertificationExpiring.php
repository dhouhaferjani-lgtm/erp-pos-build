<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Events;

/**
 * Fired daily by CheckExpiringCertifications when a cert is within 30 days of
 * expiring. Consumers (notifications, dashboards) react independently.
 */
final readonly class TechnicianCertificationExpiring
{
    public function __construct(
        public string $certification_id,
        public string $technician_profile_id,
        public \DateTimeImmutable $expires_at,
    ) {}
}
