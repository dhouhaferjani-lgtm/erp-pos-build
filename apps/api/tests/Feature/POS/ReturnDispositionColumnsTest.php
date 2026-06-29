<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReturnDispositionColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('pos_receipt_lines', ['physical_receipt', 'resalable', 'disposition']));
        $this->assertTrue(Schema::hasColumn('products', 'restock_policy'));
        $this->assertTrue(Schema::hasColumn('categories', 'restock_policy'));
    }

    public function test_product_restock_policy_casts_to_enum(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'restock_policy' => RestockPolicy::Never->value,
        ]);
        $freshProduct = $product->fresh();
        $this->assertInstanceOf(Product::class, $freshProduct);
        $this->assertSame(RestockPolicy::Never, $freshProduct->restock_policy);

        $noPolicy = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $this->assertNull($noPolicy->restock_policy);
    }
}
