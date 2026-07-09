<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\DiscountFloorMode;
use App\Modules\Company\Domain\Enums\PriceEntryMode;
use App\Modules\Pricing\Domain\CountryPricingRegulation;
use App\Modules\Pricing\Domain\Enums\RegulatoryEnforcement;
use App\Modules\Pricing\Domain\Enums\RegulatoryRuleType;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountryPricingRegulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DiscountPolicySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_policy_columns_exist_with_rev3_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $product = Product::factory()->for($tenant)->for($company)->create();
        $category = Category::factory()->for($company)->create();

        self::assertNull($product->max_discount_percent);
        self::assertNull($category->max_discount_percent);
        self::assertNull($company->default_max_discount_percent);
        self::assertSame(DiscountFloorMode::Advisory, $company->discount_floor_mode);
        self::assertSame(PriceEntryMode::Ht, $company->price_entry_mode);
        self::assertFalse(Schema::hasColumn('companies', 'margin_floor_buffer_percent'));
    }

    public function test_country_pricing_regulations_seed_advisory_rules(): void
    {
        $this->seed(CountryPricingRegulationSeeder::class);

        $this->assertDatabaseHas('country_pricing_regulations', [
            'country_code' => 'FR',
            'rule_type' => RegulatoryRuleType::BelowCostFloor->value,
            'enforcement' => RegulatoryEnforcement::Advisory->value,
            'active' => true,
        ]);
        $this->assertDatabaseHas('country_pricing_regulations', [
            'country_code' => 'TN',
            'rule_type' => RegulatoryRuleType::PharmaMarginSchedule->value,
            'active' => false,
        ]);

        self::assertCount(3, CountryPricingRegulation::query()->get());
    }
}
