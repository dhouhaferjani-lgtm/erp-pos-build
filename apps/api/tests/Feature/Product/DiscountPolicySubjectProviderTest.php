<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\DiscountFloorMode;
use App\Modules\Company\Domain\Enums\PriceEntryMode;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\DiscountPolicySubjectProviderInterface;
use App\Shared\DTOs\DiscountPolicyLineContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DiscountPolicySubjectProviderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private DiscountPolicySubjectProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create([
            'currency' => 'EUR',
            'default_minimum_margin' => '15.00',
            'default_max_discount_percent' => null,
            'discount_floor_mode' => DiscountFloorMode::Advisory,
            'price_entry_mode' => PriceEntryMode::Ht,
        ]);
        $this->provider = app(DiscountPolicySubjectProviderInterface::class);
    }

    public function test_resolve_many_returns_nearest_cap_and_minimum_margin_without_n_plus_one(): void
    {
        $root = Category::factory()->for($this->company)->create(['max_discount_percent' => '15.00']);
        $child = Category::factory()->for($this->company)->create([
            'parent_id' => $root->id,
            'max_discount_percent' => '10.00',
        ]);
        $product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'category_id' => $child->id,
            'max_discount_percent' => null,
            'cost_price' => '100.123456',
            'sale_price' => '150.00',
            'last_purchase_cost' => '98.000000',
            'minimum_margin_override' => '12.00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $subjects = $this->provider->resolveMany($this->company->id, [
            'line-0' => new DiscountPolicyLineContext(productId: $product->id, variantId: null),
        ]);

        self::assertSame('10.00', $subjects['line-0']->effectiveMaxDiscountPercent());
        self::assertSame('12.00', $subjects['line-0']->minimumMarginPercent);
        self::assertSame('150.000', $subjects['line-0']->salePriceNet);
        self::assertSame('100.123456', $subjects['line-0']->wacNet);
        self::assertSame('98.000000', $subjects['line-0']->lastPurchaseCost);
        self::assertNotSame('', $subjects['line-0']->policyVersion);
        self::assertLessThanOrEqual(6, count(DB::getQueryLog()));
    }

    public function test_subject_uses_product_cap_before_category_and_company(): void
    {
        $this->company->update(['default_max_discount_percent' => '20.00']);
        $category = Category::factory()->for($this->company)->create(['max_discount_percent' => '10.00']);
        $product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'category_id' => $category->id,
            'max_discount_percent' => '5.00',
        ]);

        $subject = $this->provider->resolve($this->company->id, $product->id);

        self::assertSame('5.00', $subject->effectiveMaxDiscountPercent());
    }

    public function test_subject_uses_company_cap_when_product_and_category_caps_are_null(): void
    {
        $this->company->update(['default_max_discount_percent' => '20.00']);
        $category = Category::factory()->for($this->company)->create(['max_discount_percent' => null]);
        $product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'category_id' => $category->id,
            'max_discount_percent' => null,
        ]);

        $subject = $this->provider->resolve($this->company->id, $product->id);

        self::assertSame('20.00', $subject->effectiveMaxDiscountPercent());
    }

    public function test_subject_resolves_tax_configuration_before_tax_rate(): void
    {
        Country::query()->create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => 'EUR',
            'currency_decimal_places' => 2,
            'phone_prefix' => '+33',
            'date_format' => 'd/m/Y',
            'default_locale' => 'fr_FR',
            'default_timezone' => 'Europe/Paris',
            'is_active' => true,
        ]);
        $taxConfiguration = TaxConfiguration::query()->create([
            'country_code' => 'FR',
            'tax_type' => TaxType::Percentage,
            'name' => 'VAT 5.5',
            'percentage_rate' => '5.50',
            'applies_to' => TaxApplicationLevel::LineItems,
            'is_default' => false,
            'is_active' => true,
        ]);
        $product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'default_tax_configuration_id' => $taxConfiguration->id,
            'tax_rate' => '20.00',
        ]);

        $subject = $this->provider->resolve($this->company->id, $product->id);

        self::assertSame($taxConfiguration->id, $subject->taxConfigurationId);
        self::assertSame('20.00', $subject->taxRate);
        self::assertSame('5.5000', $subject->resolvedTaxRate);
    }

    public function test_subject_treats_sale_price_as_ht_even_when_company_entry_mode_is_ttc(): void
    {
        $this->company->update(['price_entry_mode' => PriceEntryMode::Ttc]);
        $product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'sale_price' => '123.450',
            'tax_rate' => '20.00',
        ]);

        $subject = $this->provider->resolve($this->company->id, $product->id);

        self::assertSame('123.450', $subject->salePriceNet);
        self::assertSame(PriceEntryMode::Ttc->value, $subject->priceEntryMode);
    }
}
