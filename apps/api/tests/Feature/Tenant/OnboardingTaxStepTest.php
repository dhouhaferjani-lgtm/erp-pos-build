<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use App\Modules\Tenant\Domain\Enums\OnboardingStep;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that the onboarding "Tax Configuration" step is automatically
 * satisfied after CompanyTaxProvisioningService runs for a supported country.
 *
 * Guards Task 17: because provisioning now sets company.default_tax_configuration_id,
 * a freshly-registered TN/FR tenant should no longer see tax as unconfigured in
 * the onboarding checklist.
 */
final class OnboardingTaxStepTest extends TestCase
{
    use RefreshDatabase;

    private OnboardingChecklistService $checklist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checklist = new OnboardingChecklistService;
    }

    /**
     * After provisioning for a TN company the tax step must be completed.
     */
    public function test_tax_step_is_completed_after_provisioning(): void
    {
        (new CountriesSeeder)->run();
        $company = $this->makeCompany('TN');

        (new CompanyTaxProvisioningService(failLoudOnMissingCountry: true))->provisionForCompany($company);
        $company->refresh();

        $taxStep = $this->findTaxStep($this->checklist->getStatus($company->id));

        $this->assertTrue(
            $taxStep['completed'],
            'Tax step must be completed after provisioning sets default_tax_configuration_id'
        );
    }

    /**
     * Without provisioning the tax step must NOT be completed — proves the test is meaningful.
     */
    public function test_tax_step_is_not_completed_without_provisioning(): void
    {
        $company = $this->makeCompany('TN');

        // No provisioning call — default_tax_configuration_id remains null.
        $taxStep = $this->findTaxStep($this->checklist->getStatus($company->id));

        $this->assertFalse(
            $taxStep['completed'],
            'Tax step must be incomplete when default_tax_configuration_id is null'
        );
    }

    /**
     * FR companies are also supported; provisioning must satisfy the step there too.
     */
    public function test_tax_step_is_completed_after_fr_provisioning(): void
    {
        (new CountriesSeeder)->run();
        $company = $this->makeCompany('FR');

        (new CompanyTaxProvisioningService(failLoudOnMissingCountry: true))->provisionForCompany($company);
        $company->refresh();

        $taxStep = $this->findTaxStep($this->checklist->getStatus($company->id));

        $this->assertTrue(
            $taxStep['completed'],
            'Tax step must be completed after FR provisioning sets default_tax_configuration_id'
        );
    }

    /**
     * For an unsupported country (US) provisioning leaves the FK null,
     * so the step correctly stays incomplete.
     */
    public function test_tax_step_remains_incomplete_for_unsupported_country(): void
    {
        (new CountriesSeeder)->run();
        $company = $this->makeCompany('US');

        (new CompanyTaxProvisioningService(failLoudOnMissingCountry: true))->provisionForCompany($company);
        $company->refresh();

        $taxStep = $this->findTaxStep($this->checklist->getStatus($company->id));

        $this->assertFalse(
            $taxStep['completed'],
            'Tax step must remain incomplete for countries with no tax configs (US)'
        );
    }

    /**
     * The step must carry the expected step key and label.
     */
    public function test_tax_step_has_correct_metadata(): void
    {
        $company = $this->makeCompany('TN');

        $taxStep = $this->findTaxStep($this->checklist->getStatus($company->id));

        $this->assertSame(OnboardingStep::TaxConfig->value, $taxStep['step']);
        $this->assertSame(OnboardingStep::TaxConfig->label(), $taxStep['label']);
        $this->assertTrue($taxStep['required'], 'Tax config step must be marked required');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<array{step: string, label: string, completed: bool, required: bool, settings_path: string}>  $steps
     * @return array{step: string, label: string, completed: bool, required: bool, settings_path: string}
     */
    private function findTaxStep(array $steps): array
    {
        foreach ($steps as $step) {
            if ($step['step'] === OnboardingStep::TaxConfig->value) {
                return $step;
            }
        }

        $this->fail('TaxConfig step not found in onboarding checklist');
    }

    private function makeCompany(string $countryCode): Company
    {
        $tenant = Tenant::create([
            'name' => 'Onboarding Test Tenant '.$countryCode,
            'slug' => 'onboarding-tax-test-'.strtolower($countryCode).'-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => strtoupper($countryCode),
            'currency_code' => match (strtoupper($countryCode)) {
                'TN' => 'TND',
                'FR' => 'EUR',
                default => 'USD',
            },
        ]);

        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Onboarding Test Company '.$countryCode,
            'country_code' => strtoupper($countryCode),
            'currency' => match (strtoupper($countryCode)) {
                'TN' => 'TND',
                'FR' => 'EUR',
                default => 'USD',
            },
            'locale' => 'fr',
            'timezone' => 'UTC',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);
    }
}
