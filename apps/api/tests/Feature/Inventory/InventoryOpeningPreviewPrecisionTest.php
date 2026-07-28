<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryOpeningService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryOpeningPreviewPrecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_exposes_the_mapped_products_quantity_precision(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $user = User::factory()->for($tenant)->create();
        $unit = Unit::factory()->create(['decimal_places' => 3]);
        $product = Product::factory()->for($tenant)->for($company)->create([
            'unit_id' => $unit->id,
        ]);
        $batch = OpeningBalanceBatch::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => OpeningBatchType::Inventory,
            'name' => 'Precision Preview',
            'cutover_date' => '2026-07-01',
            'status' => OpeningBatchStatus::Draft,
            'created_by' => $user->id,
        ]);

        OpeningBalanceImportRow::create([
            'batch_id' => $batch->id,
            'row_type' => 'INVENTORY',
            'row_number' => 1,
            'status' => OpeningImportRowStatus::Valid,
            'raw_data' => [],
            'mapped_data' => [
                'product_id' => $product->id,
                'product_sku' => $product->sku,
                'product_name' => $product->name,
                'location_code' => 'WH-1',
                'location_name' => 'Warehouse',
                'quantity' => '1.2500',
                'unit_cost' => '2.000',
            ],
        ]);
        app(CompanyContext::class)->setCompanyId($company->id);

        $preview = app(InventoryOpeningService::class)->getPostPreview($batch);

        $this->assertSame(3, $preview['lines'][0]['quantity_decimals'] ?? null);
    }
}
