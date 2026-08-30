<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\Tenant\Application\DTOs\DayOneInvariantResult;
use App\Modules\Tenant\Domain\Enums\OnboardingStep;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use Illuminate\Database\DatabaseManager;

/**
 * Read-only, shared definitions for the CI and operator day-one censuses.
 *
 * @phpstan-type CompanyScope array{
 *     id: string,
 *     tenant_id: string,
 *     name: string,
 *     country_code: string,
 *     default_tax_configuration_id: string|null
 * }
 */
final readonly class DayOneCensus
{
    public function __construct(
        private DatabaseManager $database,
        private OnboardingChecklistService $onboardingChecklist,
    ) {}

    /** @return list<DayOneInvariantResult> */
    public function inspect(?string $companyId = null): array
    {
        $results = [];

        foreach ($this->companies($companyId) as $company) {
            $results[] = $this->units($company);
            $results[] = $this->taxConfigurations($company);
            $results[] = $this->requiredPurposes($company);
            $results[] = $this->refundPurposes($company);
            $results = [...$results, ...$this->repositories($company)];
            $results[] = $this->paymentMethods($company);
            $results[] = $this->numberedDrafts($company);
            $results[] = $this->onboardingChecklist($company);
        }

        return $results;
    }

    /**
     * @return list<CompanyScope>
     */
    private function companies(?string $companyId): array
    {
        $query = $this->database->table('companies')
            ->select([
                'id',
                'tenant_id',
                'name',
                'country_code',
                'default_tax_configuration_id',
            ])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($companyId !== null && $companyId !== '') {
            $query->where('id', $companyId);
        }

        $companies = [];
        foreach ($query->get() as $row) {
            $defaultTaxConfigurationId = $row->default_tax_configuration_id;
            $companies[] = [
                'id' => (string) $row->id,
                'tenant_id' => (string) $row->tenant_id,
                'name' => (string) $row->name,
                'country_code' => strtoupper((string) $row->country_code),
                'default_tax_configuration_id' => is_string($defaultTaxConfigurationId)
                    ? $defaultTaxConfigurationId
                    : null,
            ];
        }

        return $companies;
    }

    /** @param CompanyScope $company */
    private function units(array $company): DayOneInvariantResult
    {
        $visibleUnits = $this->database->table('units')
            ->where('is_active', true)
            ->where(function ($query) use ($company): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $company['tenant_id']);
            })
            ->count();
        $unresolvedCategories = $this->database->table('unit_categories')
            ->where(function ($query) use ($company): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $company['tenant_id']);
            })
            ->whereNull('base_unit_id')
            ->count();

        return new DayOneInvariantResult(
            key: 'units_visible_min_19',
            description: 'Tenant-scoped rows, per-company visibility: active units and resolved category bases.',
            expected: 'active_visible>=19; unresolved_base_units=0',
            actual: "active_visible={$visibleUnits}; unresolved_base_units={$unresolvedCategories}",
            passed: $visibleUnits >= 19 && $unresolvedCategories === 0,
            companyId: $company['id'],
            locationId: null,
        );
    }

    /** @param CompanyScope $company */
    private function taxConfigurations(array $company): DayOneInvariantResult
    {
        $countryConfigurations = $this->database->table('tax_configurations')
            ->where('country_code', $company['country_code'])
            ->count();
        $defaults = $this->database->table('tax_configurations')
            ->where('country_code', $company['country_code'])
            ->where('is_default', true)
            ->count();
        $selectedDefaultIsValid = $company['default_tax_configuration_id'] !== null
            && $this->database->table('tax_configurations')
                ->where('id', $company['default_tax_configuration_id'])
                ->where('country_code', $company['country_code'])
                ->where('is_default', true)
                ->exists();

        return new DayOneInvariantResult(
            key: 'tax_configurations_seeded',
            description: 'Country tax configurations exist and the company selects the sole default.',
            expected: 'country_rows>=1; country_defaults=1; selected_default=yes',
            actual: sprintf(
                'country_rows=%d; country_defaults=%d; selected_default=%s',
                $countryConfigurations,
                $defaults,
                $selectedDefaultIsValid ? 'yes' : 'no',
            ),
            passed: $countryConfigurations >= 1 && $defaults === 1 && $selectedDefaultIsValid,
            companyId: $company['id'],
            locationId: null,
        );
    }

    /** @param CompanyScope $company */
    private function requiredPurposes(array $company): DayOneInvariantResult
    {
        $requiredMissing = [];
        foreach (ProvisioningRequiredPurposesV1::requiredPurposes() as $purpose) {
            if ($this->activePurposeCount($company['id'], $purpose) !== 1) {
                $requiredMissing[] = $purpose->value;
            }
        }

        $scopePurposeCount = $this->activePurposeCount(
            $company['id'],
            SystemAccountPurpose::SalesStampDutyPayable,
        );
        $scopePassed = $company['country_code'] !== 'TN' || $scopePurposeCount === 1;
        $conditional = $this->classifiedPurposePresence($company['id'], 'CONDITIONAL');
        $soft = $this->classifiedPurposePresence($company['id'], 'SOFT');
        $requiredCount = count(ProvisioningRequiredPurposesV1::requiredPurposes());

        return new DayOneInvariantResult(
            key: 'required_purposes_tagged',
            description: 'REQUIRED purposes resolve exactly once; SCOPE_REQUIRED follows country scope; CONDITIONAL and SOFT are reported.',
            expected: "required={$requiredCount}/{$requiredCount}; TN scope sales_stamp_duty_payable=1",
            actual: sprintf(
                'required=%d/%d; missing=%s; scope_sales_stamp=%d; conditional=%d/%d; soft=%d/%d',
                $requiredCount - count($requiredMissing),
                $requiredCount,
                $requiredMissing === [] ? 'none' : implode(',', $requiredMissing),
                $scopePurposeCount,
                $conditional['present'],
                $conditional['total'],
                $soft['present'],
                $soft['total'],
            ),
            passed: $requiredMissing === [] && $scopePassed,
            companyId: $company['id'],
            locationId: null,
        );
    }

    /** @param CompanyScope $company */
    private function refundPurposes(array $company): DayOneInvariantResult
    {
        $salesReturn = $this->activePurposeCount($company['id'], SystemAccountPurpose::SalesReturn);
        $refundWriteOff = $this->activePurposeCount($company['id'], SystemAccountPurpose::RefundWriteOff);

        return new DayOneInvariantResult(
            key: 'refund_purposes_seeded_by_country_template',
            description: 'The country chart template pins both refund purposes before the first refund.',
            expected: 'sales_return=1; refund_write_off=1',
            actual: "sales_return={$salesReturn}; refund_write_off={$refundWriteOff}",
            passed: $salesReturn === 1 && $refundWriteOff === 1,
            companyId: $company['id'],
            locationId: null,
        );
    }

    /**
     * @param  CompanyScope  $company
     * @return list<DayOneInvariantResult>
     */
    private function repositories(array $company): array
    {
        $results = [];
        $locations = $this->database->table('locations')
            ->where('company_id', $company['id'])
            ->where('pos_enabled', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($locations as $location) {
            $locationId = (string) $location->id;
            $drawers = $this->database->table('payment_repositories')
                ->where('company_id', $company['id'])
                ->where('location_id', $locationId)
                ->where('type', RepositoryType::CashRegister->value)
                ->where('is_active', true)
                ->count();

            $results[] = new DayOneInvariantResult(
                key: 'one_drawer_per_pos_location_and_one_safe',
                description: 'Every POS-enabled location owns exactly one active attributed cash drawer.',
                expected: 'active_attributed_cash_registers=1',
                actual: "active_attributed_cash_registers={$drawers}",
                passed: $drawers === 1,
                companyId: $company['id'],
                locationId: $locationId,
            );
        }

        $safes = $this->database->table('payment_repositories')
            ->where('company_id', $company['id'])
            ->where('type', RepositoryType::Safe->value)
            ->where('is_active', true)
            ->count();
        $results[] = new DayOneInvariantResult(
            key: 'one_drawer_per_pos_location_and_one_safe',
            description: 'Every company owns exactly one active safe.',
            expected: 'active_safes=1',
            actual: "active_safes={$safes}",
            passed: $safes === 1,
            companyId: $company['id'],
            locationId: null,
        );

        return $results;
    }

    /** @param CompanyScope $company */
    private function paymentMethods(array $company): DayOneInvariantResult
    {
        $active = $this->database->table('payment_methods')
            ->where('company_id', $company['id'])
            ->where('is_active', true)
            ->count();
        $cashTenders = $this->database->table('payment_methods')
            ->where('company_id', $company['id'])
            ->where('is_active', true)
            ->where('is_cash_tender', true)
            ->count();

        return new DayOneInvariantResult(
            key: 'payment_methods_seeded',
            description: 'Active payment methods exist with one unambiguous cash tender.',
            expected: 'active_methods>=1; active_cash_tenders=1',
            actual: "active_methods={$active}; active_cash_tenders={$cashTenders}",
            passed: $active >= 1 && $cashTenders === 1,
            companyId: $company['id'],
            locationId: null,
        );
    }

    /** @param CompanyScope $company */
    private function numberedDrafts(array $company): DayOneInvariantResult
    {
        $numberedDrafts = $this->database->table('documents')
            ->where('company_id', $company['id'])
            ->where('status', 'draft')
            ->whereNotNull('document_number')
            ->count();

        return new DayOneInvariantResult(
            key: 'no_numbered_drafts',
            description: 'Draft documents carry no document number until confirmation.',
            expected: 'numbered_drafts=0',
            actual: "numbered_drafts={$numberedDrafts}",
            passed: $numberedDrafts === 0,
            companyId: $company['id'],
            locationId: null,
        );
    }

    /** @param CompanyScope $company */
    private function onboardingChecklist(array $company): DayOneInvariantResult
    {
        $provisionedSteps = [
            OnboardingStep::TaxConfig,
            OnboardingStep::PaymentMethods,
            OnboardingStep::PaymentRepositories,
        ];
        $statusByStep = [];
        foreach ($this->onboardingChecklist->getStatus($company['id']) as $status) {
            $statusByStep[$status['step']] = $status;
        }

        $failed = [];
        foreach ($provisionedSteps as $step) {
            $status = $statusByStep[$step->value] ?? null;
            if ($status === null || $status['degraded'] || ! $status['completed']) {
                $failed[] = $step->value;
            }
        }

        return new DayOneInvariantResult(
            key: 'onboarding_checklist_consistent',
            description: 'Provisioning-owned onboarding steps agree with the tax, payment-method, and repository data.',
            expected: 'tax_config,payment_methods,payment_repositories=complete and not degraded',
            actual: $failed === [] ? 'all provisioning-owned steps complete' : 'incomplete='.implode(',', $failed),
            passed: $failed === [],
            companyId: $company['id'],
            locationId: null,
        );
    }

    private function activePurposeCount(string $companyId, SystemAccountPurpose $purpose): int
    {
        return $this->database->table('accounts')
            ->where('company_id', $companyId)
            ->where('system_purpose', $purpose->value)
            ->where('is_active', true)
            ->count();
    }

    /** @return array{present: int, total: int} */
    private function classifiedPurposePresence(string $companyId, string $classification): array
    {
        $present = 0;
        $total = 0;
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] !== $classification) {
                continue;
            }

            $total++;
            if ($this->activePurposeCount($companyId, $entry['purpose']) > 0) {
                $present++;
            }
        }

        return ['present' => $present, 'total' => $total];
    }
}
