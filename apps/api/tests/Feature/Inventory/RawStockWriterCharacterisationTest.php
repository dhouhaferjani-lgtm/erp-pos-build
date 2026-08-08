<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA V7 / T1 — characterisation of the four raw stock-writer endpoints.
 *
 * Pins TODAY's behaviour of `POST /stock-movements/{receive,issue,transfer,adjust}`
 * before V7 deletes them (T10/T11), plus the two defects V7 fixes so the fix is
 * visibly an inversion rather than an unverified claim:
 *
 *  1. the LOST-UPDATE RACE in `adjust()` — it writes the CLIENT's absolute over
 *     whatever was committed between the browser read and the POST;
 *  2. the two-directional BATCH/LOT DESYNC (plan D1b / gate C2) — a positive
 *     adjustment inflates the DEFAULT lot up to the post-update AGGREGATE, and a
 *     negative adjustment decrements no lot at all.
 *
 * This whole file is deleted with the endpoints in T10/T11; the inverted
 * assertions live in T2/T16.
 *
 * Fidelity note (plan T1 *Risk*): the race is asserted at SERVICE level with an
 * interleaved committed write inside the test transaction, not across two real
 * connections. The reduced fidelity is deliberate — the observable being pinned
 * (the client's absolute overwrites the interleaved delta, and `quantity_before`
 * differs from what the operator saw) is identical either way.
 */
final class RawStockWriterCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Location $secondary;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Raw Writer Tenant',
            'slug' => 'raw-writer-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Raw Writer Co',
            'legal_name' => 'Raw Writer Co LLC',
            'tax_id' => 'TAX-RW-1',
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
            'name' => 'Raw Writer User',
            'email' => 'raw-writer@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'RW-01',
            'name' => 'Raw Writer Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->secondary = Location::create([
            'company_id' => $this->company->id,
            'code' => 'RW-02',
            'name' => 'Raw Writer Annex',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RW-PROD-001',
            'name' => 'Raw Writer Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => false,
        ]);
    }

    // ---------------------------------------------------------------- payloads

    public function test_receive_endpoint_payload_shape_today(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/receive', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '25.0000',
            'reference' => 'RAW-RECEIVE-1',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.movement_type', 'receipt');
        $response->assertJsonPath('data.quantity', '25.0000');
        $response->assertJsonPath('data.quantity_before', '0.0000');
        $response->assertJsonPath('data.quantity_after', '25.0000');
        $response->assertJsonPath('data.reference', 'RAW-RECEIVE-1');
        // The endpoint passes NO reason: every row it writes is an unjustified
        // signed delta whose only justification is the browser-synthesised
        // `reference` label (plan §0.1).
        $response->assertJsonPath('data.reason', null);
    }

    public function test_issue_endpoint_payload_shape_today(): void
    {
        $this->seedStock('40.0000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/issue', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '10.0000',
            'reference' => 'RAW-ISSUE-1',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.movement_type', 'issue');
        $response->assertJsonPath('data.quantity', '10.0000');
        $response->assertJsonPath('data.quantity_after', '30.0000');
        $response->assertJsonPath('data.reason', null);
    }

    public function test_issue_endpoint_refuses_against_the_reserved_aware_boundary_today(): void
    {
        $level = $this->seedStock('5.0000');
        $level->update(['reserved' => '3.0000']);

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/issue', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '4.0000',
            'reference' => 'RAW-ISSUE-RESERVED',
        ]);

        // The boundary is `available` (= quantity − reserved), NOT on-hand:
        // this is the guard plan D1a reproduces on adjustByDelta().
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $response->assertJsonPath('error.details.available', '2.0000');
    }

    public function test_transfer_endpoint_payload_shape_today(): void
    {
        $this->seedStock('40.0000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/transfer', [
            'product_id' => $this->product->id,
            'from_location_id' => $this->warehouse->id,
            'to_location_id' => $this->secondary->id,
            'quantity' => '15.0000',
            'reference' => 'RAW-TRANSFER-1',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Transfer completed successfully');

        $this->assertSame('25.0000', (string) $this->levelAt($this->warehouse)->quantity);
        $this->assertSame('15.0000', (string) $this->levelAt($this->secondary)->quantity);
    }

    public function test_adjust_endpoint_payload_shape_today(): void
    {
        $this->seedStock('12.0000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/adjust', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'new_quantity' => '20.0000',
            'reason_code' => 'adjustment_positive',
            'reason' => 'Found in back room',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.movement_type', 'adjustment');
        // The endpoint takes an ABSOLUTE and derives the delta server-side.
        $response->assertJsonPath('data.quantity', '8.0000');
        $response->assertJsonPath('data.quantity_before', '12.0000');
        $response->assertJsonPath('data.quantity_after', '20.0000');
        $response->assertJsonPath('data.reason', 'adjustment_positive');
    }

    public function test_adjust_endpoint_accepts_opening_balance_reason_today(): void
    {
        $this->seedStock('0.0000');

        // Pinned because V7 (D7) REMOVES `opening_balance` from the manual
        // vocabulary: it writes MovementType::Adjustment with no WAC basis and
        // no GL leg, i.e. a wrong opening balance.
        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/adjust', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'new_quantity' => '7.0000',
            'reason_code' => 'opening_balance',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.movement_type', 'adjustment');
        $response->assertJsonPath('data.reason', 'opening_balance');
    }

    // -------------------------------------------------------------- the race

    public function test_adjust_silently_loses_an_interleaved_receive(): void
    {
        $this->seedStock('10.0000');

        // 1. The operator's browser reads the level: 10.
        $observedByOperator = (string) $this->levelAt($this->warehouse)->quantity;
        $this->assertSame('10.0000', $observedByOperator);

        // 2. Someone else commits a receive of +5 → 15.
        app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'INTERLEAVED',
            userId: $this->user->id,
        );
        $this->assertSame('15.0000', (string) $this->levelAt($this->warehouse)->quantity);

        // 3. The operator posts the absolute they authored from the STALE read
        //    (10 + 2 = 12).
        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-movements/adjust', [
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'new_quantity' => '12.0000',
            'reason_code' => 'adjustment_positive',
        ]);

        $response->assertStatus(201);

        // The interleaved +5 is SILENTLY LOST: the truthful result of "+2 on top
        // of whatever is there" would be 17.
        $this->assertSame('12.0000', (string) $this->levelAt($this->warehouse)->quantity);
        $response->assertJsonPath('data.quantity_after', '12.0000');

        // And the movement's own quantity_before does not match what the
        // operator saw — the only evidence the race happened, and it is not
        // surfaced anywhere.
        $response->assertJsonPath('data.quantity_before', '15.0000');
        $this->assertNotSame($observedByOperator, '15.0000');

        // The derived delta is −3, not the +2 the operator intended.
        $response->assertJsonPath('data.quantity', '-3.0000');
    }

    // ------------------------------------------------------- the C2 desyncs

    public function test_positive_adjust_inflates_the_default_lot_to_the_whole_aggregate(): void
    {
        $product = $this->batchTrackedProduct();
        $level = $this->seedStock('100.0000', $product);
        $realLot = $this->seedLot($product, 'LOT-REAL', '100.0000');

        app(StockAdjustmentService::class)->adjust(
            productId: $product->id,
            locationId: $this->warehouse->id,
            newQuantity: '105.0000',
            reason: 'found five',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        $level->refresh();
        $this->assertSame('105.0000', (string) $level->quantity);

        // The real lot is untouched...
        $this->assertSame('100.0000', $this->lotQuantity($realLot));

        // ...and the DEFAULT lot is topped up to the post-update AGGREGATE
        // (105), not by the delta (5): Σ lots = 205 vs aggregate 105.
        $defaultLot = Batch::query()
            ->where('product_id', $product->id)
            ->where('batch_number', 'DEFAULT')
            ->firstOrFail();

        $this->assertSame('105.0000', $this->lotQuantity($defaultLot));
        $this->assertSame('205.0000', $this->totalLotQuantity($product));
        $this->assertNotSame(
            (string) $level->quantity,
            $this->totalLotQuantity($product),
            'C2 case 1: Σ BatchStock must equal the aggregate — today it does not.'
        );
    }

    public function test_negative_adjust_decrements_no_lot_at_all(): void
    {
        $product = $this->batchTrackedProduct();
        $level = $this->seedStock('100.0000', $product);
        $realLot = $this->seedLot($product, 'LOT-REAL', '100.0000');

        app(StockAdjustmentService::class)->adjust(
            productId: $product->id,
            locationId: $this->warehouse->id,
            newQuantity: '90.0000',
            reason: 'ten missing',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentNegative,
        );

        $level->refresh();
        $this->assertSame('90.0000', (string) $level->quantity);

        // No lot moved: Σ lots (100) > aggregate (90).
        $this->assertSame('100.0000', $this->lotQuantity($realLot));
        $this->assertSame('100.0000', $this->totalLotQuantity($product));
        $this->assertNotSame(
            (string) $level->quantity,
            $this->totalLotQuantity($product),
            'C2 case 2: a negative adjustment must decrement a lot — today it does not.'
        );
    }

    // ------------------------------------------------------------- fixtures

    private function batchTrackedProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RW-LOT-001',
            'name' => 'Raw Writer Lot Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 180,
        ]);
    }

    private function seedStock(string $quantity, ?Product $product = null): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => ($product ?? $this->product)->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function seedLot(Product $product, string $batchNumber, string $quantity): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.0000',
        ]);

        return $batch;
    }

    private function levelAt(Location $location, ?Product $product = null): StockLevel
    {
        return StockLevel::query()
            ->where('product_id', ($product ?? $this->product)->id)
            ->where('location_id', $location->id)
            ->firstOrFail();
    }

    private function lotQuantity(Batch $batch): string
    {
        $row = BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->warehouse->id)
            ->firstOrFail();

        return (string) $row->quantity;
    }

    private function totalLotQuantity(Product $product): string
    {
        $total = '0.0000';

        $rows = BatchStock::query()
            ->whereIn('batch_id', Batch::query()->where('product_id', $product->id)->pluck('id'))
            ->where('location_id', $this->warehouse->id)
            ->get();

        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->quantity, 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4
        }

        return $total;
    }
}
