<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure\Persistence;

use App\Modules\Company\Domain\Company;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderSequenceInterface;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Produces the next `work_order_number` for a given company.
 *
 * Mirrors `DocumentNumberingService::generateNumber()` — pessimistic
 * `lockForUpdate()` on the per-(company, year) sequence row inside a
 * transaction, guaranteeing gap-free monotonic numbering under concurrent
 * creation. Format: `WO-YYYY-NNNNNN` (6-digit padding — matches the
 * AppointmentSequence + seeded demo data; closes audit finding 🟠-2).
 */
final readonly class EloquentWorkOrderSequence implements WorkOrderSequenceInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function next(string $companyId): string
    {
        $year = (int) date('Y');
        $tenantId = $this->resolveTenantIdForCompany($companyId);

        /** @var string $number */
        $number = $this->db->transaction(function () use ($tenantId, $companyId, $year): string {
            $sequence = WorkOrderSequence::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = WorkOrderSequence::query()->create([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'year' => $year,
                    'last_number' => 0,
                ]);
            }

            $nextNumber = $sequence->last_number + 1;
            $sequence->update(['last_number' => $nextNumber]);

            return sprintf('WO-%d-%06d', $year, $nextNumber);
        });

        return $number;
    }

    private function resolveTenantIdForCompany(string $companyId): string
    {
        $company = Company::query()->find($companyId);
        if ($company === null) {
            throw new RuntimeException("Cannot generate work-order number: company {$companyId} not found.");
        }

        /** @var string $tenantId */
        $tenantId = $company->getAttribute('tenant_id');

        return $tenantId;
    }
}
