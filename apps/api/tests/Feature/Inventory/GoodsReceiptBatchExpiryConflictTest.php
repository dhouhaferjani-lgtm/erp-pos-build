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
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
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

final class GoodsReceiptBatchExpiryConflictTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Retail]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'TND']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Batch expiry conflict user',
            'email' => 'batch-expiry-conflict@example.test',
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
            'name' => 'Batch expiry conflict supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-LOT-7',
            'name' => 'P-LOT-7',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => true,
            'cost_price' => '1.000000',
        ]);
    }

    public function test_second_tranche_with_different_expiry_is_a_noop_conflict_refusal(): void
    {
        $po = $this->purchaseOrder('6.0000');
        $line = $po->lines->sole();
        $this->receive($po, $line, '2.0000', 'LOT-SAME', '2027-01-31')->assertOk();
        $beforeMovements = StockMovement::query()->count();
        $beforeJournalEntries = DB::table('journal_entries')->count();

        $response = $this->receive($po->fresh(['lines']) ?? $po, $line, '2.0000', 'LOT-SAME', '2028-01-31');

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'GOODS_RECEIPT_FAILED')
            ->assertJsonPath('error.reason', 'BATCH_EXPIRY_CONFLICT')
            ->assertJsonPath('error.message', 'Batch LOT-SAME for line 1 (P-LOT-7) already has expiry date 2027-01-31; supplied expiry date 2028-01-31 conflicts.')
            ->assertJsonPath('error.details.batch_number', 'LOT-SAME')
            ->assertJsonPath('error.details.stored_expiry', '2027-01-31')
            ->assertJsonPath('error.details.supplied_expiry', '2028-01-31');
        self::assertSame(0, preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-/i', (string) $response->json('error.message')));

        $batch = Batch::query()->where('batch_number', 'LOT-SAME')->sole();
        self::assertSame('2027-01-31', $batch->expiry_date?->toDateString());
        self::assertSame('2.0000', (string) BatchStock::query()->where('batch_id', $batch->id)->sole()->quantity);
        self::assertSame($beforeMovements, StockMovement::query()->count());
        self::assertSame($beforeJournalEntries, DB::table('journal_entries')->count());
    }

    public function test_same_expiry_reuses_lot_and_sums_quantities(): void
    {
        $po = $this->purchaseOrder('4.0000');
        $line = $po->lines->sole();

        $this->receive($po, $line, '2.0000', 'LOT-UNCHANGED', '2027-01-31')->assertOk();
        $this->receive($po->fresh(['lines']) ?? $po, $line, '2.0000', 'LOT-UNCHANGED', '2027-01-31')->assertOk();

        $batch = Batch::query()->where('batch_number', 'LOT-UNCHANGED')->sole();
        self::assertSame(1, Batch::query()->where('batch_number', 'LOT-UNCHANGED')->count());
        self::assertSame('4.0000', (string) BatchStock::query()->where('batch_id', $batch->id)->sole()->quantity);
    }

    public function test_equivalent_noncanonical_date_does_not_create_a_false_conflict(): void
    {
        $po = $this->purchaseOrder('4.0000');
        $line = $po->lines->sole();

        $this->receive($po, $line, '2.0000', 'LOT-EQUIVALENT-DATE', '2027-01-31')->assertOk();
        $this->receive(
            $po->fresh(['lines']) ?? $po,
            $line,
            '2.0000',
            'LOT-EQUIVALENT-DATE',
            'January 31, 2027',
        )->assertOk();

        $batch = Batch::query()->where('batch_number', 'LOT-EQUIVALENT-DATE')->sole();
        self::assertSame('2027-01-31', $batch->expiry_date?->toDateString());
        self::assertSame('4.0000', (string) BatchStock::query()->where('batch_id', $batch->id)->sole()->quantity);
    }

    public function test_null_supplied_expiry_reuses_dated_lot_without_conflict_or_write(): void
    {
        $po = $this->purchaseOrder('4.0000');
        $line = $po->lines->sole();
        $this->receive($po, $line, '2.0000', 'LOT-UNKNOWN', '2027-01-31')->assertOk();

        app(GoodsReceiptService::class)->receiveGoods(
            $po->fresh(['lines']) ?? $po,
            [$line->id => '2.0000'],
            [$line->id => ['batch_number' => 'LOT-UNKNOWN', 'expiry_date' => null]],
            actorId: $this->user->id,
        );

        $batch = Batch::query()->where('batch_number', 'LOT-UNKNOWN')->sole();
        self::assertSame('2027-01-31', $batch->expiry_date?->toDateString());
        self::assertSame('4.0000', (string) BatchStock::query()->where('batch_id', $batch->id)->sole()->quantity);
    }

    public function test_conflict_refusal_is_identical_at_a_second_location(): void
    {
        $annex = Location::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'ANNEX',
            'is_active' => true,
        ]);
        $po = $this->purchaseOrder('4.0000');
        $line = $po->lines->sole();
        $this->receive($po, $line, '2.0000', 'LOT-TWO-LOCATIONS', '2027-01-31')->assertOk();

        $first = $this->receive($po->fresh(['lines']) ?? $po, $line, '2.0000', 'LOT-TWO-LOCATIONS', '2028-01-31', $annex->id);
        $second = $this->receive($po->fresh(['lines']) ?? $po, $line, '2.0000', 'LOT-TWO-LOCATIONS', '2028-01-31', $annex->id);

        $first->assertUnprocessable()->assertJsonPath('error.reason', 'BATCH_EXPIRY_CONFLICT');
        $second->assertUnprocessable()
            ->assertJsonPath('error.reason', $first->json('error.reason'))
            ->assertJsonPath('error.message', $first->json('error.message'));
        self::assertSame(0, BatchStock::query()->where('location_id', $annex->id)->count());
    }

    public function test_same_lot_received_at_two_locations_has_one_lot_and_two_stock_rows(): void
    {
        $annex = Location::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'ANNEX-SUCCESS',
            'is_active' => true,
        ]);
        $po = $this->purchaseOrder('4.0000');
        $line = $po->lines->sole();

        $this->receive($po, $line, '2.0000', 'LOT-TWO-ROWS', '2027-01-31')->assertOk();
        $this->receive(
            $po->fresh(['lines']) ?? $po,
            $line,
            '2.0000',
            'LOT-TWO-ROWS',
            '2027-01-31',
            $annex->id,
        )->assertOk();

        $batch = Batch::query()->where('batch_number', 'LOT-TWO-ROWS')->sole();
        self::assertSame(1, Batch::query()->where('batch_number', 'LOT-TWO-ROWS')->count());
        self::assertSame(2, BatchStock::query()->where('batch_id', $batch->id)->count());
        self::assertEqualsCanonicalizing(
            [$this->warehouse->id, $annex->id],
            BatchStock::query()->where('batch_id', $batch->id)->pluck('location_id')->all(),
        );
    }

    public function test_expiry_conflict_is_company_scoped_in_a_second_company(): void
    {
        $companyB = Company::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'TND']);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $companyB->id,
            'role' => 'admin',
        ]);
        $warehouseB = Location::factory()->create([
            'company_id' => $companyB->id,
            'code' => 'MAIN-B',
            'is_active' => true,
            'is_default' => true,
        ]);
        $supplierB = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyB->id,
            'name' => 'Batch expiry conflict supplier B',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
        $productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyB->id,
            'sku' => 'P-LOT-7-B',
            'name' => 'P-LOT-7-B',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => true,
            'cost_price' => '1.000000',
        ]);
        app(CompanyContext::class)->setCompanyId($companyB->id);
        $po = $this->purchaseOrderFor($companyB, $warehouseB, $supplierB, $productB, '4.0000');
        $line = $po->lines->sole();

        $this->receive($po, $line, '2.0000', 'LOT-COMPANY-SCOPED', '2027-01-31')->assertOk();
        $this->receive(
            $po->fresh(['lines']) ?? $po,
            $line,
            '2.0000',
            'LOT-COMPANY-SCOPED',
            '2028-01-31',
        )->assertUnprocessable()
            ->assertJsonPath('error.reason', 'BATCH_EXPIRY_CONFLICT')
            ->assertJsonPath('error.details.lines.0.sku', 'P-LOT-7-B');

        self::assertSame(1, Batch::query()
            ->where('company_id', $companyB->id)
            ->where('batch_number', 'LOT-COMPANY-SCOPED')
            ->count());
    }

    private function purchaseOrder(string $quantity): Document
    {
        return $this->purchaseOrderFor($this->company, $this->warehouse, $this->supplier, $this->product, $quantity);
    }

    private function purchaseOrderFor(
        Company $company,
        Location $location,
        Partner $supplier,
        Product $product,
        string $quantity,
    ): Document {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $supplier->id,
            'location_id' => $location->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-CONFLICT-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $quantity,
            'tax_amount' => '0.000',
            'total' => $quantity,
        ]);
        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => $product->sku,
            'quantity' => $quantity,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity' => '0.0000',
            'free_quantity_received' => '0.0000',
            'unit_price' => '1.000',
            'line_total' => $quantity,
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '1.000000',
        ]);

        $po->load('lines');

        return $po;
    }

    /** @return TestResponse<Response> */
    private function receive(
        Document $po,
        DocumentLine $line,
        string $quantity,
        string $batchNumber,
        string $expiry,
        ?string $locationId = null,
    ): TestResponse {
        $payload = [
            'quantities' => [$line->id => $quantity],
            'batches' => [$line->id => ['batch_number' => $batchNumber, 'expiry_date' => $expiry]],
        ];
        if ($locationId !== null) {
            $payload['location_id'] = $locationId;
        }

        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $po->company_id)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", $payload);
    }
}
