<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Domain\Category;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaxResolutionServiceDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_product_default_inherits_category_then_company(): void
    {
        $service = new TaxResolutionService;
        $company = $this->makeCompany('FR');
        $company->update(['default_tax_rate' => '20.00']);
        $company->refresh();

        // No category -> falls back to company default
        // bccomp normalises '20' vs '20.00' — driver-agnostic (SQLite strips trailing zeros)
        $this->assertSame(0, bccomp($service->getDefaultTaxForNewProduct($company, null), '20.00', 2));

        // Category with a default -> category default takes priority
        $category = Category::create([
            'company_id' => $company->id,
            'name' => 'Médicaments',
        ]);
        \DB::table('categories')->where('id', $category->id)->update(['default_tax_rate' => '10.00']);

        $this->assertSame(0, bccomp($service->getDefaultTaxForNewProduct($company, (string) $category->id), '10.00', 2));

        // Integer PK passed directly (as ProductService::upsert does via $category->id) must also resolve
        $this->assertSame(0, bccomp($service->getDefaultTaxForNewProduct($company, (int) $category->id), '10.00', 2));
    }

    public function test_falls_back_to_company_when_category_has_no_tax_rate(): void
    {
        $service = new TaxResolutionService;
        $company = $this->makeCompany('FR');
        $company->update(['default_tax_rate' => '20.00']);
        $company->refresh();

        $category = Category::create([
            'company_id' => $company->id,
            'name' => 'Sans TVA',
        ]);
        // default_tax_rate remains NULL

        $this->assertSame(0, bccomp($service->getDefaultTaxForNewProduct($company, (string) $category->id), '20.00', 2));
    }

    public function test_falls_back_to_zero_when_company_has_no_default(): void
    {
        $service = new TaxResolutionService;
        $company = $this->makeCompany('FR');
        // company.default_tax_rate is NULL by default

        $this->assertSame(0, bccomp($service->getDefaultTaxForNewProduct($company, null), '0.00', 2));
    }

    /**
     * Cross-company isolation: a category belonging to Company B must NOT
     * supply its default_tax_rate when resolving for Company A.
     * Company A has a 19.00 default; Company B's category has 5.00.
     * Resolver must return 19.00 (Company A's default), not 5.00.
     */
    public function test_category_from_different_company_is_ignored(): void
    {
        $service = new TaxResolutionService;

        $companyA = $this->makeCompany('FR');
        $companyA->update(['default_tax_rate' => '19.00']);
        $companyA->refresh();

        $companyB = $this->makeCompany('FR');
        $companyB->update(['default_tax_rate' => '5.00']);
        $companyB->refresh();

        // Create a category that belongs to Company B with a 5.00 rate
        $categoryB = Category::create([
            'company_id' => $companyB->id,
            'name' => 'Catégorie Société B',
        ]);
        \DB::table('categories')->where('id', $categoryB->id)->update(['default_tax_rate' => '5.00']);

        // When resolving for Company A using Company B's category id,
        // the resolver must NOT return 5.00 — it must fall back to Company A's default (19.00).
        $result = $service->getDefaultTaxForNewProduct($companyA, (int) $categoryB->id);

        $this->assertSame(
            0,
            bccomp($result, '19.00', 2),
            "Expected Company A default rate 19.00, got {$result} — category from Company B leaked across company boundary",
        );
    }

    private function makeCompany(string $countryCode): Company
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant '.$countryCode,
            'slug' => 'test-tax-resolution-'.strtolower($countryCode).'-'.uniqid(),
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
            'name' => 'Test Company '.$countryCode,
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
