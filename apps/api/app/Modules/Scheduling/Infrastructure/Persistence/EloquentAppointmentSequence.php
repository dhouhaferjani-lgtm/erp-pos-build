<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Company\Domain\Company;
use App\Modules\Scheduling\Domain\Contracts\AppointmentSequenceInterface;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Produces the next `appointment_number` for a given company and year.
 *
 * Mirrors `EloquentWorkOrderSequence::next()` / `DocumentNumberingService::generateNumber()`
 * — pessimistic `lockForUpdate()` on the per-(tenant, company, year) sequence
 * row inside a transaction guarantees gap-free monotonic numbering under
 * concurrent creation.
 *
 * Format: `APT-YYYY-NNNNNN`.
 */
final readonly class EloquentAppointmentSequence implements AppointmentSequenceInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextNumber(string $companyId, int $year): string
    {
        $tenantId = $this->resolveTenantIdForCompany($companyId);

        /** @var string $number */
        $number = $this->db->transaction(function () use ($tenantId, $companyId, $year): string {
            $sequence = AppointmentSequence::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = AppointmentSequence::query()->create([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'year' => $year,
                    'last_number' => 0,
                ]);
            }

            $nextNumber = $sequence->last_number + 1;
            $sequence->update(['last_number' => $nextNumber]);

            return sprintf('APT-%d-%06d', $year, $nextNumber);
        });

        return $number;
    }

    private function resolveTenantIdForCompany(string $companyId): string
    {
        $company = Company::query()->find($companyId);
        if ($company === null) {
            throw new RuntimeException("Cannot generate appointment number: company {$companyId} not found.");
        }

        /** @var string $tenantId */
        $tenantId = $company->getAttribute('tenant_id');

        return $tenantId;
    }
}
