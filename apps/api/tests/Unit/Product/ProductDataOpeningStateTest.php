<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Application\DTOs\OpeningStateData;
use App\Modules\Product\Application\DTOs\ProductData;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataOpeningStateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
    }

    public function test_product_data_includes_opening_state(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $data = ProductData::fromModel($product, null, new OpeningStateData(false, false, true));

        $this->assertTrue($data->opening->can_enter_opening);
    }

    public function test_product_data_opening_is_null_when_not_provided(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $data = ProductData::fromModel($product);

        $this->assertNull($data->opening);
    }
}
