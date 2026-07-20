<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
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
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class GoodsReceiptDestinationTest extends TestCase
{
    use RefreshDatabase;

    /** Receipt destination and aggregate semantics are contractually verified on PostgreSQL. */
    protected array $connectionsToTransact = ['pgsql'];

    protected function beforeRefreshingDatabase(): void
    {
        config(['database.default' => 'pgsql']);
    }

    private Tenant $tenant;

    private Company $company;

    private Location $locationA;

    private Location $locationB;

    private User $user;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Receipt Destination Tenant',
            'slug' => 'receipt-destination-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Receipt Destination Company',
            'legal_name' => 'Receipt Destination Company',
            'tax_id' => 'GRD-1',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Receipt Destination User',
            'email' => 'receipt-destination-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.receive', 'purchase-orders.receive']);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
            'status' => 'active',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->locationA = $this->location('A', true);
        $this->locationB = $this->location('B');
        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Receipt Destination Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    public function test_repeated_partial_receipts_preserve_history_and_move_remainder_to_latest_destination(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'GRD-001',
            'name' => 'Destination Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'cost_price' => '0.00',
        ]);
        $po = $this->purchaseOrder($product, '10.0000');
        $line = $po->lines->firstOrFail();
        $service = app(GoodsReceiptService::class);

        $first = $service->receiveGoods($po, [$line->id => '4.0000'], [], [], [], null, null, $this->locationA->id);
        $firstReceipt = $first->receipt->fresh(['lines']);
        $firstMovement = StockMovement::findOrFail($firstReceipt->lines->firstOrFail()->movement_id);
        self::assertSame('6.0000', $this->incomingFor($product, $this->locationA));

        $second = $service->receiveGoods(
            $first->purchaseOrder->fresh(['lines']),
            [$line->id => '3.0000'],
            [],
            [],
            [],
            null,
            null,
            $this->locationB->id,
        );
        $secondReceipt = $second->receipt->fresh(['lines']);
        $secondMovement = StockMovement::findOrFail($secondReceipt->lines->firstOrFail()->movement_id);
        $latestLine = DocumentLine::findOrFail($line->id);

        self::assertSame($this->locationA->id, $firstReceipt->location_id);
        self::assertSame($this->locationB->id, $secondReceipt->location_id);
        self::assertSame($this->locationA->id, $firstMovement->location_id);
        self::assertSame($this->locationB->id, $secondMovement->location_id);
        self::assertSame('4.0000', (string) $firstMovement->quantity);
        self::assertSame('3.0000', (string) $secondMovement->quantity);
        self::assertSame($this->locationB->id, $latestLine->location_id);
        self::assertSame('7.0000', (string) $latestLine->quantity_received);
        self::assertSame('3.0000', bcsub((string) $latestLine->quantity, (string) $latestLine->quantity_received, 4));
        self::assertSame('3.0000', $this->incomingFor($product, $this->locationB));
        self::assertSame('0.0000', $this->incomingFor($product, $this->locationA));

        $receipts = GoodsReceipt::query()->where('purchase_order_id', $po->id)->get();
        self::assertCount(2, $receipts);
        self::assertSame(2, GoodsReceiptLine::query()->whereIn('goods_receipt_id', $receipts->pluck('id'))->count());
    }

    public function test_omitted_destination_uses_purchase_order_default_location(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'GRD-002',
            'name' => 'Default Destination Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'cost_price' => '0.00',
        ]);
        $po = $this->purchaseOrder($product, '2.0000');
        $line = $po->lines->firstOrFail();

        $result = app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '2.0000']);
        $receipt = $result->receipt->fresh(['lines']);
        $movement = StockMovement::findOrFail($receipt->lines->firstOrFail()->movement_id);

        self::assertSame($this->locationA->id, $receipt->location_id);
        self::assertSame($this->locationA->id, $movement->location_id);
        self::assertSame($this->locationA->id, DocumentLine::findOrFail($line->id)->location_id);
    }

    public function test_out_of_scope_destination_is_rejected_by_receive_endpoint(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'GRD-003',
            'name' => 'Forbidden Destination Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'cost_price' => '0.00',
        ]);
        $po = $this->purchaseOrder($product, '1.0000');
        $line = $po->lines->firstOrFail();
        $receipt = app(GoodsReceiptService::class)->createDraft(
            $po,
            [$line->id => '1.0000'],
            [],
            [],
            [],
            null,
            $this->user->id,
        );
        UserCompanyMembership::where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => [$this->locationA->id]]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/goods-receipts/{$receipt->id}/post", ['location_id' => $this->locationB->id])
            ->assertForbidden();
    }

    private function incomingFor(Product $product, Location $location): string
    {
        $row = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/stock-matrix?include=incoming&location_ids[]='.$location->id)
            ->assertOk()
            ->json('data');

        $productRow = collect($row)->firstWhere('product_id', $product->id);

        return (string) ($productRow['cells'][$location->id]['incoming'] ?? '0.0000');
    }

    private function location(string $suffix, bool $default = false): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'code' => 'GRD-'.$suffix,
            'name' => 'Receipt Destination '.$suffix,
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => $default,
        ]);
    }

    private function purchaseOrder(Product $product, string $quantity): Document
    {
        $lineTotal = bcmul($quantity, '5.000', 4);
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->locationA->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-GRD-'.Str::upper(Str::random(8)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $lineTotal,
            'tax_amount' => '0.00',
            'total' => $lineTotal,
        ]);
        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => $quantity,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => $lineTotal,
            'allocated_costs' => '0.0000',
            'location_id' => $this->locationA->id,
        ]);

        return $po->fresh(['lines']);
    }
}
