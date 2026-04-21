<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\Contracts;

use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\ValueObjects\AvailabilityResult;

/**
 * Contract consumed by Spec B (work-order assignment) and Spec D (scheduler).
 * Plan C owns this contract.
 */
interface TechnicianAvailabilityServiceInterface
{
    public function isAvailable(
        string $profileId,
        \DateTimeImmutable $startsAt,
        int $durationMinutes,
        ?SpecialtyCode $requiredSpecialty,
    ): AvailabilityResult;
}
