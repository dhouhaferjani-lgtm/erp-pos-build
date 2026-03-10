<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Repositories;

use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Modules\Taxation\Domain\Repositories\WithholdingTaxRuleRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class EloquentWithholdingTaxRuleRepository implements WithholdingTaxRuleRepositoryInterface
{
    public function findById(string $id): ?WithholdingTaxRule
    {
        return WithholdingTaxRule::find($id);
    }

    public function findByCountry(string $countryCode): Collection
    {
        return WithholdingTaxRule::where('country_code', $countryCode)
            ->where('is_active', true)
            ->whereNull('company_id') // Global rules only
            ->orderBy('name')
            ->get();
    }

    public function findApplicableRules(
        PartnerTaxStatus $partnerStatus,
        string $amount,
        string $countryCode,
        ?string $companyId = null,
        ?TransactionType $transactionType = null,
        ?Carbon $date = null
    ): Collection {
        $date = $date ?? now();

        $query = WithholdingTaxRule::where('country_code', $countryCode)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            });

        // Include both global and company-specific rules if company ID provided
        if ($companyId) {
            $query->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')
                    ->orWhere('company_id', $companyId);
            });
        } else {
            $query->whereNull('company_id');
        }

        // Order by specificity
        $query->orderByRaw('CASE
            WHEN company_id IS NOT NULL THEN 0
            ELSE 1
        END');

        $query->orderByRaw('CASE
            WHEN transaction_type IS NOT NULL AND partner_tax_status IS NOT NULL THEN 0
            WHEN transaction_type IS NOT NULL THEN 1
            WHEN partner_tax_status IS NOT NULL THEN 2
            ELSE 3
        END');

        return $query->get();
    }

    public function findByCompany(string $companyId): Collection
    {
        return WithholdingTaxRule::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function create(array $data): WithholdingTaxRule
    {
        return WithholdingTaxRule::create($data);
    }

    public function update(string $id, array $data): WithholdingTaxRule
    {
        $rule = WithholdingTaxRule::findOrFail($id);
        $rule->update($data);

        /** @var WithholdingTaxRule $freshRule */
        $freshRule = $rule->fresh();

        return $freshRule;
    }

    public function deactivate(string $id): bool
    {
        $rule = WithholdingTaxRule::findOrFail($id);
        $rule->update(['is_active' => false]);

        return true;
    }

    public function delete(string $id): bool
    {
        $rule = WithholdingTaxRule::findOrFail($id);

        return $rule->delete() ?? false;
    }
}
