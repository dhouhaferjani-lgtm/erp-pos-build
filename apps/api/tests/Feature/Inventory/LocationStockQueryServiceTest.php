<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\LocationStockReader;
use App\Shared\DTOs\LocationIncomingRowDTO;
use App\Shared\DTOs\LocationStockRowDTO;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LocationStockReader read model (POS location stock feed).
 *
 * The reader is exercised through the Shared contract binding so these
 * tests also pin the InventoryServiceProvider bind().
 */
class LocationStockQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $locationA;

    private Location $locationB;

    private Product $product;

    private ProductVariant $variant;

    private Product $plainProduct;

    private LocationStockReader $reader;

    private int $transferSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Stock Feed Tenant',
            'slug' => 'stock-feed-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Acme',
            'legal_name' => 'Acme LLC',
            'tax_id' => 'TAX-ACME',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'U',
            'email' => 'u@e.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->locationA = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SH-01',
            'name' => 'Shop A',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->locationB = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Warehouse B',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'TSHIRT',
            'name' => 'T-Shirt',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);

        $this->variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => 'RED-L',
            'sku' => 'TSHIRT-RED-L',
            'barcode' => null,
            'name_suffix' => 'RED-L',
            'is_default' => false,
            'is_active' => true,
            'display_order' => 0,
            'price_override' => null,
            'cost_override' => null,
            'image_url' => null,
        ]);

        $this->plainProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'MUG',
            'name' => 'Mug',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '2.0000',
            'sale_price' => '4.0000',
        ]);

        $this->reader = $this->app->make(LocationStockReader::class);
    }

    private function makeStockLevel(Product $product, ?ProductVariant $variant, Location $location, string $quantity, string $reserved = '0'): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reserved' => $reserved,
        ]);
    }

    /**
     * Insert a stock_transfers row + one line directly — the read model only
     * cares about persisted rows, not the transfer service ceremony.
     */
    private function makeTransfer(
        TransferStatus $status,
        Location $source,
        Location $destination,
        Product $product,
        ?ProductVariant $variant,
        string $quantity,
    ): StockTransfer {
        $this->transferSequence++;

        $transfer = StockTransfer::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'transfer_number' => sprintf('TR-%04d', $this->transferSequence),
            'transfer_type' => 'intracompany',
            'status' => $status->value,
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'initiated_by_user_id' => $this->user->id,
            'initiated_at' => now(),
        ]);

        StockTransferLine::create([
            'transfer_id' => $transfer->id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'quantity' => $quantity,
        ]);

        return $transfer;
    }

    private function makePurchaseOrderLine(
        DocumentStatus $status,
        Location $location,
        Product $product,
        string $quantity,
        ?string $quantityReceived,
        string $documentNumber,
    ): DocumentLine {
        $supplier = Partner::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'name' => 'ACME Supplier'],
            ['type' => PartnerType::Supplier, 'is_active' => true],
        );

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => $status,
            'document_number' => $documentNumber,
            'partner_id' => $supplier->id,
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        return DocumentLine::create([
            'document_id' => $po->id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => $quantity,
            // Column is NOT NULL DEFAULT 0 — normalize "nothing received yet" to '0'.
            'quantity_received' => $quantityReceived ?? '0',
            'unit_price' => '1.00',
            'line_total' => '10.00',
        ]);
    }

    /**
     * @param  list<LocationStockRowDTO>  $stock
     * @return array<string, LocationStockRowDTO> stock rows keyed by "productId|variantId"
     */
    private function keyStock(array $stock): array
    {
        $keyed = [];
        foreach ($stock as $row) {
            $this->assertInstanceOf(LocationStockRowDTO::class, $row);
            $keyed[$row->productId.'|'.($row->variantId ?? '')] = $row;
        }

        return $keyed;
    }

    /**
     * @param  list<LocationIncomingRowDTO>  $incoming
     * @return array<string, LocationIncomingRowDTO> incoming rows keyed by "productId|variantId"
     */
    private function keyIncoming(array $incoming): array
    {
        $keyed = [];
        foreach ($incoming as $row) {
            $this->assertInstanceOf(LocationIncomingRowDTO::class, $row);
            $keyed[$row->productId.'|'.($row->variantId ?? '')] = $row;
        }

        return $keyed;
    }

    public function test_full_mode_returns_complete_location_stock_with_variant_grain(): void
    {
        $this->makeStockLevel($this->product, $this->variant, $this->locationA, '10.0000', '2.5000');
        $this->makeStockLevel($this->plainProduct, null, $this->locationA, '5.5000', '0.0000');
        // Location B noise — must not appear.
        $this->makeStockLevel($this->product, $this->variant, $this->locationB, '99.0000', '0.0000');

        $page = $this->reader->read($this->tenant->id, $this->company->id, $this->locationA->id, null, 1, 500);

        $this->assertCount(2, $page->stock);
        $this->assertSame(1, $page->page);
        $this->assertSame(1, $page->lastPage);
        $this->assertSame(2, $page->total);

        $stock = $this->keyStock($page->stock);

        $variantKey = $this->product->id.'|'.$this->variant->id;
        $plainKey = $this->plainProduct->id.'|';
        // Exactly the 2 location-A rows (order is product_id/variant_id, i.e. UUID order).
        $this->assertEqualsCanonicalizing([$variantKey, $plainKey], array_keys($stock));

        $variantRow = $stock[$variantKey];
        $this->assertSame($this->variant->id, $variantRow->variantId);
        $this->assertSame('10.0000', $variantRow->quantity);
        $this->assertSame('2.5000', $variantRow->reserved);
        $this->assertSame('7.5000', $variantRow->available);

        $plainRow = $stock[$plainKey];
        $this->assertNull($plainRow->variantId);
        $this->assertSame('5.5000', $plainRow->quantity);
        $this->assertSame('0.0000', $plainRow->reserved);
        $this->assertSame('5.5000', $plainRow->available);
    }

    public function test_delta_mode_filters_on_updated_at(): void
    {
        $row1 = $this->makeStockLevel($this->plainProduct, null, $this->locationA, '5.0000');
        $row2 = $this->makeStockLevel($this->product, $this->variant, $this->locationA, '10.0000');

        // Control updated_at directly (bypasses Eloquent's timestamp touching).
        DB::table('stock_levels')->where('id', $row1->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        DB::table('stock_levels')->where('id', $row2->id)->update(['updated_at' => '2026-03-01 00:00:00']);

        // Incoming source so we can assert delta mode does NOT filter incoming.
        $this->makeTransfer(TransferStatus::InTransit, $this->locationB, $this->locationA, $this->product, $this->variant, '6');

        $cursor = CarbonImmutable::parse('2026-02-01 00:00:00');
        $page = $this->reader->read($this->tenant->id, $this->company->id, $this->locationA->id, $cursor, 1, 500);

        $this->assertCount(1, $page->stock);
        $this->assertSame($this->product->id, $page->stock[0]->productId);
        $this->assertSame($this->variant->id, $page->stock[0]->variantId);

        // Incoming is still the complete set in delta mode.
        $this->assertCount(1, $page->incoming);
    }

    public function test_incoming_transfer_counts_only_in_transit_toward_this_location(): void
    {
        // Toward A: only the in_transit one may count.
        $this->makeTransfer(TransferStatus::InTransit, $this->locationB, $this->locationA, $this->product, $this->variant, '6');
        $this->makeTransfer(TransferStatus::Draft, $this->locationB, $this->locationA, $this->product, $this->variant, '5');
        $this->makeTransfer(TransferStatus::Completed, $this->locationB, $this->locationA, $this->product, $this->variant, '4');
        $this->makeTransfer(TransferStatus::Cancelled, $this->locationB, $this->locationA, $this->product, $this->variant, '3');
        // In transit toward B — wrong destination.
        $this->makeTransfer(TransferStatus::InTransit, $this->locationA, $this->locationB, $this->product, $this->variant, '9');

        $page = $this->reader->read($this->tenant->id, $this->company->id, $this->locationA->id, null, 1, 500);

        $this->assertCount(1, $page->incoming);
        $row = $page->incoming[0];
        $this->assertSame($this->product->id, $row->productId);
        $this->assertSame($this->variant->id, $row->variantId);
        $this->assertSame('6.0000', $row->incomingTransfer);
        $this->assertSame('0.0000', $row->incomingPo);
    }

    public function test_incoming_po_is_location_scoped_unreceived_remainder(): void
    {
        // Confirmed at A: 10 ordered, 4 received -> 6 incoming.
        $this->makePurchaseOrderLine(DocumentStatus::Confirmed, $this->locationA, $this->plainProduct, '10', '4', 'PO-0001');
        // Confirmed at B — wrong location.
        $this->makePurchaseOrderLine(DocumentStatus::Confirmed, $this->locationB, $this->plainProduct, '7', null, 'PO-0002');
        // Draft at A — wrong status.
        $this->makePurchaseOrderLine(DocumentStatus::Draft, $this->locationA, $this->plainProduct, '8', null, 'PO-0003');

        $page = $this->reader->read($this->tenant->id, $this->company->id, $this->locationA->id, null, 1, 500);

        $this->assertCount(1, $page->incoming);
        $row = $page->incoming[0];
        $this->assertSame($this->plainProduct->id, $row->productId);
        // PO incoming is product-grain: always lands on the variantId = null key.
        $this->assertNull($row->variantId);
        $this->assertSame('6.0000', $row->incomingPo);
        $this->assertSame('0.0000', $row->incomingTransfer);
    }

    public function test_incoming_is_complete_even_in_delta_mode_with_empty_stock_delta(): void
    {
        $row = $this->makeStockLevel($this->product, $this->variant, $this->locationA, '10.0000');
        DB::table('stock_levels')->where('id', $row->id)->update(['updated_at' => '2026-01-01 00:00:00']);

        $this->makeTransfer(TransferStatus::InTransit, $this->locationB, $this->locationA, $this->product, $this->variant, '6');

        // Cursor strictly after every stock update -> empty stock delta.
        $cursor = CarbonImmutable::parse('2026-02-01 00:00:00');
        $page = $this->reader->read($this->tenant->id, $this->company->id, $this->locationA->id, $cursor, 1, 500);

        $this->assertSame([], $page->stock);
        $this->assertCount(1, $page->incoming);
        $this->assertSame('6.0000', $page->incoming[0]->incomingTransfer);
    }

    public function test_incoming_present_only_on_page_one(): void
    {
        $thirdProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'CAP',
            'name' => 'Cap',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.0000',
            'sale_price' => '2.0000',
        ]);

        $this->makeStockLevel($this->product, $this->variant, $this->locationA, '10.0000');
        $this->makeStockLevel($this->plainProduct, null, $this->locationA, '5.0000');
        $this->makeStockLevel($thirdProduct, null, $this->locationA, '3.0000');

        $this->makeTransfer(TransferStatus::InTransit, $this->locationB, $this->locationA, $this->product, $this->variant, '6');

        $pageOne = $this->reader->read($this->tenant->id, $this->company->id, $this->locationA->id, null, 1, 2);
        $this->assertCount(2, $pageOne->stock);
        $this->assertCount(1, $pageOne->incoming);
        $this->assertSame(1, $pageOne->page);
        $this->assertSame(2, $pageOne->lastPage);
        $this->assertSame(3, $pageOne->total);

        $pageTwo = $this->reader->read($this->tenant->id, $this->company->id, $this->locationA->id, null, 2, 2);
        $this->assertCount(1, $pageTwo->stock);
        $this->assertSame([], $pageTwo->incoming);
        $this->assertSame(2, $pageTwo->page);
    }
}
