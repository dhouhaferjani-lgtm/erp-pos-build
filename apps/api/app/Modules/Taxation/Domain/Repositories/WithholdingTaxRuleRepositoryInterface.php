<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Repositories;

use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

interface WithholdingTaxRuleRepositoryInterface
{
    /**
     * Find a rule by ID.
     */
    public function findById(string $id): ?WithholdingTaxRule;

    /**
     * Find all active rules for a country.
     *
     * @return Collection<int, WithholdingTaxRule>
     */
    public function findByCountry(string $countryCode): Collection;

    /**
     * Find applicable rules for given conditions.
     *
     * @param  numeric-string  $amount
     * @return Collection<int, WithholdingTaxRule>
     */
    public function findApplicableRules(
        PartnerTaxStatus $partnerStatus,
        string $amount,
        string $countryCode,
        ?string $companyId = null,
        ?TransactionType $transactionType = null,
        ?Carbon $date = null
    ): Collection;

    /**
     * Find all company-specific rules.
     *
     * @return Collection<int, WithholdingTaxRule>
     */
    public function findByCompany(string $companyId): Collection;

    /**
     * Create a new rule.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WithholdingTaxRule;

    /**
     * Update a rule.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): WithholdingTaxRule;

    /**
     * Deactivate a rule.
     */
    public function deactivate(string $id): bool;

    /**
     * Delete a rule.
     */
    public function delete(string $id): bool;
}
