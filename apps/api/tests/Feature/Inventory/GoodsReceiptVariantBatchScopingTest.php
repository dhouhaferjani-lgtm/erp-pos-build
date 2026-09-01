<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class GoodsReceiptVariantBatchScopingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Retail]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'TND']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Variant receipt user',
            'email' => 'variant-receipt@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo('purchase-orders.receive');
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Variant receipt supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    public function test_variant_purchase_order_line_creates_variant_scoped_lot_and_stock(): void
    {
        $product = $this->product('P-VARIANT-LOT', true);
        $variant = $this->variant($product, 'RED');
        $po = $this->purchaseOrder($product, $variant);
        $line = $po->lines->sole();

        $this->receive($po, $line, 'LOT-VARIANT')->assertOk();

        $batch = Batch::query()->where('batch_number', 'LOT-VARIANT')->sole();
        self::assertSame($variant->id, $batch->variant_id);
        self::assertSame('2.0000', (string) BatchStock::query()->where('batch_id', $batch->id)->sole()->quantity);
        self::assertSame('2.0000', (string) StockLevel::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->sole()->quantity);
    }

    public function test_same_batch_number_under_second_variant_creates_a_second_lot_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Variant-aware partial unique indexes are PostgreSQL-only.');
        }

        $product = $this->product('P-VARIANT-DUP', true);
        $red = $this->variant($product, 'RED');
        $blue = $this->variant($product, 'BLUE');
        $redPo = $this->purchaseOrder($product, $red);
        $bluePo = $this->purchaseOrder($product, $blue);

        $this->receive($redPo, $redPo->lines->sole(), 'LOT-SHARED')->assertOk();
        $this->receive($bluePo, $bluePo->lines->sole(), 'LOT-SHARED')->assertOk();

        $batches = Batch::query()->where('batch_number', 'LOT-SHARED')->orderBy('variant_id')->get();
        self::assertCount(2, $batches);
        self::assertEqualsCanonicalizing([$red->id, $blue->id], $batches->pluck('variant_id')->all());
        self::assertSame('4.0000', (string) BatchStock::query()->whereIn('batch_id', $batches->pluck('id'))->sum('quantity'));
        self::assertSame('4.0000', (string) StockLevel::query()->where('product_id', $product->id)->sum('quantity'));
    }

    public function test_variant_bearing_product_without_line_variant_is_a_typed_noop_refusal(): void
    {
        $product = $this->product('P-LOT-8', true);
        $this->variant($product, 'ONLY');
        $po = $this->purchaseOrder($product);
        $line = $po->lines->sole();

        $response = $this->receive($po, $line, 'LOT-MISSING-VARIANT');

        $response->assertUnprocessable()
            ->assertJsonPath('error.reason', 'VARIANT_REQUIRED')
            ->assertJsonPath('error.message', 'Product P-LOT-8 on line 1 has active variants; batches must be variant-scoped — a variant_id is required.');
        self::assertSame(0, GoodsReceipt::query()->count());
        self::assertSame(0, StockMovement::query()->count());
        self::assertSame(0, Batch::query()->count());
        self::assertSame(0, preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-/i', (string) $response->json('error.message')));
    }

    public function test_product_without_variants_still_receives_a_product_level_lot(): void
    {
        $product = $this->product('P-PLAIN-LOT', true);
        $po = $this->purchaseOrder($product);
        $line = $po->lines->sole();

        $this->receive($po, $line, 'LOT-PLAIN')->assertOk();

        self::assertNull(Batch::query()->where('batch_number', 'LOT-PLAIN')->sole()->variant_id);
    }

    private function product(string $sku, bool $tracked): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $sku,
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => $tracked,
            'cost_price' => '1.000000',
        ]);
    }

    private function variant(Product $product, string $suffix): ProductVariant
    {
        return ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'VAR-'.$suffix,
            'sku' => $product->sku.'-'.$suffix,
            'is_active' => true,
        ]);
    }

    private function purchaseOrder(Product $product, ?ProductVariant $variant = null): Document
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-VARIANT-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '2.000',
            'tax_amount' => '0.000',
            'total' => '2.000',
        ]);
        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'line_number' => 1,
            'description' => $product->sku,
            'quantity' => '2.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity' => '0.0000',
            'free_quantity_received' => '0.0000',
            'unit_price' => '1.000',
            'line_total' => '2.000',
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '1.000000',
        ]);

        $po->load('lines');

        return $po;
    }

    /** @return TestResponse<Response> */
    private function receive(Document $po, DocumentLine $line, string $batchNumber): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '2.0000'],
                'batches' => [
                    $line->id => [
                        'batch_number' => $batchNumber,
                        'expiry_date' => now()->addYear()->toDateString(),
                    ],
                ],
            ]);
    }
}
