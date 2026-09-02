<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
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
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class GoodsReceiptReceiveAllBatchDataTest extends TestCase
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
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Receipt hardening user',
            'email' => 'receipt-hardening@example.test',
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
            'name' => 'Receipt hardening supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    public function test_empty_receive_all_body_is_a_uuid_free_typed_batch_refusal(): void
    {
        $po = $this->purchaseOrder([
            ['product' => $this->product('P-LOT-1', true), 'quantity' => '1.0000'],
        ]);

        $response = $this->receive($po, []);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'GOODS_RECEIPT_FAILED')
            ->assertJsonPath('error.reason', 'BATCH_DATA_REQUIRED')
            ->assertJsonPath('error.message', 'Batch data is required for batch-tracked products: line 1 (P-LOT-1).')
            ->assertJsonPath('error.details.lines.0.line_number', 1)
            ->assertJsonPath('error.details.lines.0.sku', 'P-LOT-1')
            ->assertJsonPath('error.details.lines.0.description', 'P-LOT-1');

        self::assertSame(0, preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-/i', (string) $response->json('error.message')));
        self::assertSame(0, GoodsReceipt::query()->count());
        self::assertSame(0, StockMovement::query()->count());
        self::assertSame(0, Batch::query()->count());
    }

    public function test_receive_all_threads_batches_when_quantities_are_omitted(): void
    {
        $po = $this->purchaseOrder([
            ['product' => $this->product('P-LOT-RECEIVE-ALL', true), 'quantity' => '6.0000'],
        ]);
        $line = $po->lines->sole();

        $this->receive($po, [
            'batches' => [$line->id => $this->batch('LOT-RECEIVE-ALL')],
        ])->assertOk();

        self::assertSame('6.0000', (string) $line->fresh()?->quantity_received);
        $batch = Batch::query()->where('batch_number', 'LOT-RECEIVE-ALL')->sole();
        self::assertSame('6.0000', (string) BatchStock::query()->where('batch_id', $batch->id)->sole()->quantity);
        self::assertSame('6.0000', (string) StockLevel::query()->where('product_id', $line->product_id)->sole()->quantity);
        self::assertSame(1, GoodsReceipt::query()->count());
    }

    public function test_receive_all_accepts_batch_data_for_only_the_tracked_line(): void
    {
        $tracked = $this->product('P-MIX-TRACKED', true);
        $untracked = $this->product('P-MIX-PLAIN', false);
        $po = $this->purchaseOrder([
            ['product' => $tracked, 'quantity' => '2.0000'],
            ['product' => $untracked, 'quantity' => '3.0000'],
        ]);
        $trackedLine = $po->lines->firstWhere('product_id', $tracked->id);
        self::assertInstanceOf(DocumentLine::class, $trackedLine);

        $this->receive($po, [
            'batches' => [$trackedLine->id => $this->batch('LOT-MIXED')],
        ])->assertOk();

        self::assertSame('2.0000', (string) $trackedLine->fresh()?->quantity_received);
        self::assertSame('3.0000', (string) $po->lines->firstWhere('product_id', $untracked->id)?->fresh()?->quantity_received);
        self::assertSame(1, Batch::query()->count());
        self::assertNull($po->lines->firstWhere('product_id', $untracked->id)?->fresh()?->batch_id);
    }

    public function test_missing_batch_prepass_aggregates_every_tracked_line(): void
    {
        $po = $this->purchaseOrder([
            ['product' => $this->product('P-TRACKED-A', true), 'quantity' => '1.0000'],
            ['product' => $this->product('P-TRACKED-B', true), 'quantity' => '1.0000'],
        ]);

        $response = $this->receive($po, [
            'quantities' => $po->lines->mapWithKeys(static fn (DocumentLine $line): array => [$line->id => '1.0000'])->all(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonCount(2, 'error.details.lines')
            ->assertJsonPath('error.details.lines.0.sku', 'P-TRACKED-A')
            ->assertJsonPath('error.details.lines.1.sku', 'P-TRACKED-B');
        self::assertSame(0, GoodsReceipt::query()->count());
        self::assertSame(0, StockMovement::query()->count());
    }

    public function test_draft_without_batches_is_refused_before_a_receipt_is_persisted(): void
    {
        $po = $this->purchaseOrder([
            ['product' => $this->product('P-DRAFT-BATCH', true), 'quantity' => '1.0000'],
        ]);
        $line = $po->lines->sole();

        $this->receive($po, [
            'save_as_draft' => true,
            'quantities' => [$line->id => '1.0000'],
        ])->assertUnprocessable()->assertJsonPath('error.reason', 'BATCH_DATA_REQUIRED');

        self::assertSame(0, GoodsReceipt::query()->count());
    }

    public function test_draft_with_batches_preserves_payload_and_remains_postable(): void
    {
        $po = $this->purchaseOrder([
            ['product' => $this->product('P-DRAFT-POST', true), 'quantity' => '1.0000'],
        ]);
        $line = $po->lines->sole();
        $batch = $this->batch('LOT-DRAFT-POST');

        $response = $this->receive($po, [
            'save_as_draft' => true,
            'quantities' => [$line->id => '1.0000'],
            'batches' => [$line->id => $batch],
        ])->assertOk();

        $receipt = GoodsReceipt::query()->sole();
        $payload = $receipt->payload;
        self::assertIsArray($payload);
        $batchData = $payload['batch_data'] ?? null;
        self::assertIsArray($batchData);
        // PostgreSQL jsonb normalises object-key order; payload integrity is a
        // semantic equality assertion, while every scalar value stays strict.
        self::assertEquals($batch, $batchData[$line->id] ?? null);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/goods-receipts/{$receipt->id}/post")
            ->assertOk();
        self::assertSame(1, Batch::query()->where('batch_number', 'LOT-DRAFT-POST')->count());
        self::assertNotNull($response->json('meta.goods_receipt.id'));
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

    /**
     * @param  list<array{product: Product, quantity: string}>  $items
     */
    private function purchaseOrder(array $items): Document
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
            'document_number' => 'PO-L1-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        foreach ($items as $index => $item) {
            DocumentLine::create([
                'document_id' => $po->id,
                'product_id' => $item['product']->id,
                'line_number' => $index + 1,
                'description' => $item['product']->sku,
                'quantity' => $item['quantity'],
                'quantity_delivered' => '0.0000',
                'quantity_received' => '0.0000',
                'free_quantity' => '0.0000',
                'free_quantity_received' => '0.0000',
                'unit_price' => '1.000',
                'line_total' => $item['quantity'],
                'allocated_costs' => '0.000000',
                'landed_unit_cost' => '1.000000',
            ]);
        }

        $po->load('lines');

        return $po;
    }

    /** @return array{batch_number: string, expiry_date: string} */
    private function batch(string $number): array
    {
        return [
            'batch_number' => $number,
            'expiry_date' => now()->addYear()->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function receive(Document $po, array $payload): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", $payload);
    }
}
