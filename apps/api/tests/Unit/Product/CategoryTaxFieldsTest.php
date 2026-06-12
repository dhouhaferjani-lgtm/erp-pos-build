<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Application\DTOs\CategoryData;
use App\Modules\Product\Domain\Category;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTaxFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);

        $tenant = Tenant::create([
            'name' => 'Tax Fields Tenant',
            'slug' => 'tax-fields-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tax Fields Company',
            'legal_name' => 'Tax Fields Company LLC',
            'tax_id' => 'TAX999',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    public function test_category_tax_fields_are_mass_assignable_and_persisted(): void
    {
        $taxConfig = TaxConfiguration::create([
            'country_code' => 'FR',
            'tax_type' => TaxType::Percentage,
            'name' => 'TVA 20%',
            'code' => 'FR_TVA_20',
            'percentage_rate' => '20.00',
            'applies_to' => TaxApplicationLevel::LineItems,
            'is_default' => false,
            'is_active' => true,
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_stamp_duty' => false,
        ]);

        $category = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Electronics',
            'default_tax_rate' => '10.00',
            'default_tax_configuration_id' => $taxConfig->id,
        ]);

        // Reload from DB to confirm persistence (not just in-memory)
        $fresh = Category::find($category->id);
        $this->assertNotNull($fresh);

        $this->assertEquals('10.00', $fresh->default_tax_rate);
        $this->assertEquals($taxConfig->id, $fresh->default_tax_configuration_id);
    }

    public function test_category_tax_fields_are_nullable(): void
    {
        $category = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Books',
        ]);

        $fresh = Category::find($category->id);
        $this->assertNotNull($fresh);

        $this->assertNull($fresh->default_tax_rate);
        $this->assertNull($fresh->default_tax_configuration_id);
    }

    public function test_category_data_from_model_includes_tax_fields(): void
    {
        $taxConfig = TaxConfiguration::create([
            'country_code' => 'FR',
            'tax_type' => TaxType::Percentage,
            'name' => 'TVA 5.5%',
            'code' => 'FR_TVA_5_5',
            'percentage_rate' => '5.50',
            'applies_to' => TaxApplicationLevel::LineItems,
            'is_default' => false,
            'is_active' => true,
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_stamp_duty' => false,
        ]);

        $category = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Food',
            'default_tax_rate' => '5.50',
            'default_tax_configuration_id' => $taxConfig->id,
        ]);

        $dto = CategoryData::fromModel($category);

        $this->assertEquals('5.50', $dto->default_tax_rate);
        $this->assertEquals($taxConfig->id, $dto->default_tax_configuration_id);
    }

    public function test_category_data_tax_fields_are_null_when_not_set(): void
    {
        $category = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Services',
        ]);

        $dto = CategoryData::fromModel($category);

        $this->assertNull($dto->default_tax_rate);
        $this->assertNull($dto->default_tax_configuration_id);
    }
}
