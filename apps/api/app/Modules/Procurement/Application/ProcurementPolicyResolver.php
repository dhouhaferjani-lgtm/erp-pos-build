<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Company\Domain\Company;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;

/**
 * Resolves the effective procurement AP policy for a given company.
 *
 * Resolution order:
 *   1. Persisted `procurement_policies` row for the company — company-specific override.
 *   2. `ProcurementPolicy::defaultForVertical($tenant->vertical)` — per-vertical default.
 *
 * Phase 1 guard: if the resolved policy carries `bill_control_mode=ordered`,
 * a DomainException is thrown. `ordered` (invoice-first) is reserved for
 * Phase 2 and must never silently execute in Phase 1 AP flows.
 */
final class ProcurementPolicyResolver
{
    public function forCompany(string $companyId): ProcurementPolicy
    {
        $persisted = ProcurementPolicy::where('company_id', $companyId)->first();

        if ($persisted !== null) {
            $policy = $persisted;
        } else {
            /** @var Company|null $company */
            $company = Company::with('tenant')->find($companyId);

            if ($company === null) {
                throw new \DomainException(
                    "Cannot resolve procurement policy: company [{$companyId}] not found."
                );
            }

            $policy = ProcurementPolicy::defaultForVertical($company->tenant->vertical);
        }

        if ($policy->bill_control_mode === BillControlMode::Ordered) {
            throw new \DomainException(
                'Phase 1 procurement supports only receipt-first (received) bill control. '.
                'The "ordered" (invoice-first) mode is reserved for Phase 2 and cannot be '.
                "used in the current release. Company: [{$companyId}]."
            );
        }

        return $policy;
    }
}
