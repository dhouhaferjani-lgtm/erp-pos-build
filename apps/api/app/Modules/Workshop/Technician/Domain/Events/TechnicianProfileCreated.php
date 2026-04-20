<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Events;

/**
 * Emitted when a new technician profile is persisted. Immutable.
 */
final readonly class TechnicianProfileCreated
{
    public function __construct(
        public string $technician_profile_id,
        public string $user_id,
        public string $company_id,
    ) {}
}
