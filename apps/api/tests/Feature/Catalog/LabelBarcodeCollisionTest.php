<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Services\VariantLabelService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task B1 — VariantLabelService::valueIsUsable collision check.
 *
 * A candidate barcode value is UNUSABLE when it already identifies another
 * active variant (barcode) or any product (barcode OR sku) in the tenant.
 *
 * Real Postgres (the partial-unique + tenant scoping behaviour matters).
 */
class LabelBarcodeCollisionTest extends TestCase
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

    private function service(): VariantLabelService
    {
        return app(VariantLabelService::class);
    }

    public function test_value_is_unusable_when_taken_by_product_barcode_sku_or_variant(): void
    {
        ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-SKU',
            'barcode' => 'P-BC',
        ]);

        ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'barcode' => 'V-BC',
        ]);

        // A distinct subject variant doing the collision check (not the holder of V-BC).
        $subject = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'barcode' => null,
        ]);

        $service = $this->service();

        $this->assertFalse($service->valueIsUsable($this->tenant->id, 'P-BC', $subject->id));
        $this->assertFalse($service->valueIsUsable($this->tenant->id, 'P-SKU', $subject->id));
        $this->assertFalse($service->valueIsUsable($this->tenant->id, 'V-BC', $subject->id));
    }

    public function test_value_is_usable_when_totally_free(): void
    {
        ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-SKU',
            'barcode' => 'P-BC',
        ]);

        $subject = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'barcode' => 'V-BC',
        ]);

        $this->assertTrue($this->service()->valueIsUsable($this->tenant->id, 'TOTALLY-FREE', $subject->id));
    }
}
