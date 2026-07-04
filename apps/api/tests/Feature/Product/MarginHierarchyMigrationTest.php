<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarginHierarchyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_margin_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('categories', 'target_margin_override'));
        $this->assertTrue(Schema::hasColumn('categories', 'minimum_margin_override'));
    }

    public function test_product_pricing_mode_defaults_to_manual(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'pricing_mode'));
        $company = Company::factory()->create(['tenant_id' => Str::uuid()->toString()]);
        $product = Product::factory()->for($company)->create();
        $this->assertSame(PricingMode::Manual, $product->fresh()->pricing_mode);
    }
}
