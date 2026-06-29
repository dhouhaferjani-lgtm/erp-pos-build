<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductSkinSuitability;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\SkinType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductSkinSuitabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_skin_suitabilities_relation_returns_two_rows_cast_to_skin_type(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Parapharmacy',
            'slug' => 'test-parapharmacy-skin',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        ProductSkinSuitability::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'skin_type' => SkinType::Oily->value,
        ]);

        ProductSkinSuitability::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'skin_type' => SkinType::Sensitive->value,
        ]);

        $suitabilities = $metadata->skinSuitabilities;

        $this->assertCount(2, $suitabilities);

        foreach ($suitabilities as $suitability) {
            $this->assertInstanceOf(SkinType::class, $suitability->skin_type);
        }

        $skinTypes = $suitabilities->pluck('skin_type')->all();
        $this->assertContains(SkinType::Oily, $skinTypes);
        $this->assertContains(SkinType::Sensitive, $skinTypes);
    }
}
