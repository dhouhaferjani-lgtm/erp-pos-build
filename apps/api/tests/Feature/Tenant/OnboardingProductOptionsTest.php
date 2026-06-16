<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use App\Modules\Tenant\Domain\Enums\OnboardingStep;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that the onboarding "Product Options" step reflects whether at
 * least one ProductAttribute with is_variant_axis=true exists for the tenant.
 *
 * Guards Task E2: checkProductOptions() must return true iff a variant-axis
 * attribute exists (soft-deleted rows excluded via SoftDeletes).
 */
final class OnboardingProductOptionsTest extends TestCase
{
    use RefreshDatabase;

    private OnboardingChecklistService $checklist;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checklist = new OnboardingChecklistService;
        $this->company = $this->makeCompany();
    }

    public function test_product_options_incomplete_without_variant_axis_attribute(): void
    {
        // No ProductAttribute with is_variant_axis=true exists.
        $step = $this->findProductOptionsStep($this->checklist->getStatus($this->company->id));

        $this->assertFalse(
            $step['completed'],
            'product_options step must be incomplete when no variant-axis attribute exists'
        );
    }

    public function test_product_options_complete_with_a_variant_axis_attribute(): void
    {
        ProductAttribute::factory()->create([
            'is_variant_axis' => true,
        ]);

        $step = $this->findProductOptionsStep($this->checklist->getStatus($this->company->id));

        $this->assertTrue(
            $step['completed'],
            'product_options step must be completed when a variant-axis attribute exists'
        );
    }

    public function test_product_options_step_is_not_required(): void
    {
        $step = $this->findProductOptionsStep($this->checklist->getStatus($this->company->id));

        $this->assertFalse(
            $step['required'],
            'product_options step must be optional'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<array{step: string, label: string, completed: bool, required: bool, settings_path: string}>  $steps
     * @return array{step: string, label: string, completed: bool, required: bool, settings_path: string}
     */
    private function findProductOptionsStep(array $steps): array
    {
        foreach ($steps as $step) {
            if ($step['step'] === OnboardingStep::ProductOptions->value) {
                return $step;
            }
        }

        $this->fail('ProductOptions step not found in onboarding checklist');
    }

    private function makeCompany(): Company
    {
        $tenant = Tenant::create([
            'name' => 'Product Options Onboarding Tenant',
            'slug' => 'product-options-onboarding-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => 'TN',
            'currency_code' => 'TND',
        ]);

        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Product Options Onboarding Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'UTC',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);
    }
}
