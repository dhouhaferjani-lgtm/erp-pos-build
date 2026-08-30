<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Tenant\Application\DTOs\DayOneInvariantResult;
use App\Modules\Tenant\Domain\Enums\OnboardingStep;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Illuminate\Database\DatabaseManager;

/**
 * Read-only, shared definitions for the CI and operator day-one censuses.
 *
 * @phpstan-type CompanyScope array{
 *     model: Company,
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
        private UnitsProvisioningService $unitsProvisioning,
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
            // Company uses SoftDeletes: a trashed company must not be censused (nor make findOrFail throw).
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($companyId !== null && $companyId !== '') {
            $query->where('id', $companyId);
        }

        $companies = [];
        foreach ($query->get() as $row) {
            $defaultTaxConfigurationId = $row->default_tax_configuration_id;
            $companies[] = [
                'model' => Company::query()->findOrFail((string) $row->id),
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
        $visibleUnits = $this->unitsProvisioning->visibleActiveUnitCount($company['model']);
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
        $conditional = $this->purposePresence(
            $company['id'],
            ProvisioningRequiredPurposesV1::conditionalPurposes(),
        );
        $soft = $this->purposePresence(
            $company['id'],
            ProvisioningRequiredPurposesV1::softPurposes(),
        );
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
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'name']);

        $results[] = new DayOneInvariantResult(
            key: 'one_drawer_per_pos_location_and_one_safe',
            description: 'Every company owns at least one active POS-enabled location.',
            expected: 'active_pos_locations>=1',
            actual: 'active_pos_locations='.$locations->count(),
            passed: $locations->isNotEmpty(),
            companyId: $company['id'],
            locationId: null,
        );

        foreach ($locations as $location) {
            $locationId = (string) $location->id;
            $drawers = $this->database->table('payment_repositories')
                ->where('company_id', $company['id'])
                ->where('location_id', $locationId)
                ->where('type', RepositoryType::CashRegister->value)
                ->where('is_active', true)
                ->count();
            $glLinkedDrawers = $this->database->table('payment_repositories')
                ->where('company_id', $company['id'])
                ->where('location_id', $locationId)
                ->where('type', RepositoryType::CashRegister->value)
                ->where('is_active', true)
                ->whereNotNull('gl_account_id')
                ->count();

            $results[] = new DayOneInvariantResult(
                key: 'one_drawer_per_pos_location_and_one_safe',
                description: 'Every active POS-enabled location owns exactly one active GL-linked attributed cash drawer.',
                expected: 'active_attributed_cash_registers=1; GL-linked=1',
                actual: "active_attributed_cash_registers={$drawers}; gl_linked={$glLinkedDrawers}",
                passed: $drawers === 1 && $glLinkedDrawers === 1,
                companyId: $company['id'],
                locationId: $locationId,
            );
        }

        $safes = $this->database->table('payment_repositories')
            ->where('company_id', $company['id'])
            ->where('type', RepositoryType::Safe->value)
            ->where('is_active', true)
            ->count();
        $glLinkedSafes = $this->database->table('payment_repositories')
            ->where('company_id', $company['id'])
            ->where('type', RepositoryType::Safe->value)
            ->where('is_active', true)
            ->whereNotNull('gl_account_id')
            ->count();
        $results[] = new DayOneInvariantResult(
            key: 'one_drawer_per_pos_location_and_one_safe',
            description: 'Every company owns exactly one active GL-linked safe.',
            expected: 'active_safes=1; GL-linked=1',
            actual: "active_safes={$safes}; gl_linked={$glLinkedSafes}",
            passed: $safes === 1 && $glLinkedSafes === 1,
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
            ->orderBy('code')
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->all();
        $flaggedCode = count($cashTenders) === 1 ? $cashTenders[0] : implode(',', $cashTenders);
        $cashCodeMethods = $this->database->table('payment_methods')
            ->where('company_id', $company['id'])
            ->whereRaw('UPPER(code) = ?', ['CASH'])
            ->count();

        return new DayOneInvariantResult(
            key: 'cash_tender_coherent',
            description: 'Exactly one active cash-tender flag agrees with the sole CASH-family code.',
            expected: 'active_methods>=1; active_cash_tenders=1; flagged_code=CASH; cash_code_methods=1',
            actual: sprintf(
                'active_methods=%d; active_cash_tenders=%d; flagged_code=%s; cash_code_methods=%d',
                $active,
                count($cashTenders),
                $flaggedCode === '' ? 'none' : $flaggedCode,
                $cashCodeMethods,
            ),
            passed: $active >= 1
                && count($cashTenders) === 1
                && strtoupper($flaggedCode) === 'CASH'
                && $cashCodeMethods === 1,
            companyId: $company['id'],
            locationId: null,
        );
    }

    /** @param CompanyScope $company */
    private function numberedDrafts(array $company): DayOneInvariantResult
    {
        $numberedDrafts = $this->database->table('documents')
            ->where('company_id', $company['id'])
            ->where('status', DocumentStatus::Draft->value)
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

    /**
     * @param  list<SystemAccountPurpose>  $purposes
     * @return array{present: int, total: int}
     */
    private function purposePresence(string $companyId, array $purposes): array
    {
        $present = 0;
        foreach ($purposes as $purpose) {
            if ($this->activePurposeCount($companyId, $purpose) > 0) {
                $present++;
            }
        }

        return ['present' => $present, 'total' => count($purposes)];
    }
}
