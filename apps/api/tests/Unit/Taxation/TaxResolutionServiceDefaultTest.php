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
