<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Contracts;

/**
 * Provides the next `appointment_number` for a given company.
 *
 * Format is `APT-YYYY-NNNNNN` — year comes from the current clock OR from an
 * explicit `$year` parameter for predictable unit testing. Implementation is
 * pessimistic-locked on `(tenant_id, company_id, year)` to guarantee gap-free
 * monotonic numbering under concurrent creation (mirrors the
 * `WorkOrderSequenceInterface` / `DocumentNumberingService` patterns).
 */
interface AppointmentSequenceInterface
{
    /**
     * Returns the next appointment number for the given company and year.
     *
     * @param  string  $companyId  UUID of the target company (row lock scope).
     * @param  int  $year  Calendar year for the sequence bucket (e.g. 2026).
     * @return string The formatted number, e.g. `APT-2026-000001`.
     */
    public function nextNumber(string $companyId, int $year): string;
}
