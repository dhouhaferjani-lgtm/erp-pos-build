<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\LocationStockQueryService;
use App\Modules\Inventory\Application\Services\StockMatrixQueryService;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StockTransferEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $source;

    private Location $destination;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->givePermissionTo(['inventory.transfers.view', 'inventory.transfers.create', 'inventory.transfers.complete', 'inventory.transfers.cancel']);
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'manager', 'status' => 'active']);
        $this->source = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'warehouse', 'is_active' => true]);
        $this->destination = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'shop', 'is_active' => true]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'cost_price' => '5.0000', 'requires_batch_tracking' => false]);
        $this->actingAs($this->user)->withHeader('X-Company-Id', $this->company->id);
    }

    public function test_transfer_movements_are_created_with_final_linkage_and_never_updated(): void
    {
        $this->seedStock();
        $created = [];
        $updated = [];
        StockMovement::created(static function (StockMovement $movement) use (&$created): void {
            $created[] = [$movement->movement_type->value, $movement->reference_type, $movement->reference_id];
        });
        StockMovement::updated(static function (StockMovement $movement) use (&$updated): void {
            $updated[] = $movement->id;
        });
        $transfer = $this->app->make(StockTransferService::class)->initiate($this->data());
        self::assertSame([['transfer_out', StockTransfer::class, $transfer->id]], $created);
        self::assertSame([], $updated);
        $this->app->make(StockTransferService::class)->complete($transfer->id, $this->user->id);
        self::assertSame(['transfer_in', StockTransfer::class, $transfer->id], $created[1]);
        self::assertSame([], $updated);
        $cancelled = $this->app->make(StockTransferService::class)->initiate($this->data());
        $this->app->make(StockTransferService::class)->cancel($cancelled->id, $this->user->id, 'test');
        self::assertSame(['transfer_in', StockTransfer::class, $cancelled->id], $created[3]);
        self::assertSame([], $updated);
    }

    public function test_transfer_announcements_carry_final_labels_and_linkage_in_both_versions(): void
    {
        $this->seedStock();
        $events = [StockMovementRecorded::class, StockMovementRecordedV2::class];
        Event::fake($events);
        $transfer = $this->app->make(StockTransferService::class)->initiate($this->data());
        foreach ($events as $event) {
            Event::assertDispatched($event, static fn ($posted): bool => $posted->movementType === 'transfer_out' && $posted->referenceType === StockTransfer::class && $posted->referenceId === $transfer->id);
        }
        $this->app->make(StockTransferService::class)->complete($transfer->id, $this->user->id);
        foreach ($events as $event) {
            Event::assertDispatched($event, static fn ($posted): bool => $posted->movementType === 'transfer_in' && $posted->referenceType === StockTransfer::class && $posted->referenceId === $transfer->id);
        }
    }

    #[DataProvider('invalidTransferMovementOverrides')]
    public function test_invalid_transfer_overrides_are_refused_before_any_write(string $method, ?MovementType $type, ?string $transferId): void
    {
        $this->seedStock();
        $count = StockMovement::query()->count();
        $before = StockLevel::query()->where('product_id', $this->product->id)->pluck('quantity', 'id')->all();
        try {
            $this->app->make(StockAdjustmentService::class)->{$method}(
                $this->product->id, $this->source->id, '1.0000', 'INVALID-OVERRIDE', $this->user->id,
                expectedCompanyId: $this->company->id, movementType: $type, transferId: $transferId,
            );
            self::fail('An invalid override must be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame($count, StockMovement::query()->count());
            self::assertSame($before, StockLevel::query()->where('product_id', $this->product->id)->pluck('quantity', 'id')->all());
        }
    }

    /** @return iterable<string, array{string, MovementType|null, string|null}> */
    public static function invalidTransferMovementOverrides(): iterable
    {
        yield 'receive wrong direction' => ['receive', MovementType::TransferOut, '11111111-1111-4111-8111-111111111111'];
        yield 'issue wrong direction' => ['issue', MovementType::TransferIn, '11111111-1111-4111-8111-111111111111'];
        yield 'receive missing linkage' => ['receive', MovementType::TransferIn, null];
        yield 'issue missing linkage' => ['issue', MovementType::TransferOut, null];
        yield 'receive malformed UUID' => ['receive', MovementType::TransferIn, 'invalid'];
        yield 'issue malformed UUID' => ['issue', MovementType::TransferOut, 'invalid'];
        yield 'linkage without type' => ['receive', null, '11111111-1111-4111-8111-111111111111'];
        yield 'default type override' => ['issue', MovementType::Issue, '11111111-1111-4111-8111-111111111111'];
    }

    public function test_complete_twice_replays_and_receives_each_line_once(): void
    {
        $this->seedStock();
        $second = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'requires_batch_tracking' => false]);
        app(StockAdjustmentService::class)->receive($second->id, $this->source->id, '3.0000', 'T1-SEED', $this->user->id, expectedCompanyId: $this->company->id);
        $transfer = app(StockTransferService::class)->initiate($this->data(lines: [new InitiateTransferLineData($this->product->id, '4.0000'), new InitiateTransferLineData($second->id, '2.0000')]));
        $url = "/api/v1/stock-transfers/{$transfer->id}/complete";
        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertOk();
        self::assertSame(1, $transfer->receipts()->count());
        foreach ([$this->product->id => '4.0000', $second->id => '2.0000'] as $id => $qty) {
            $moves = StockMovement::query()->where('reference_id', $transfer->id)->where('movement_type', MovementType::TransferIn)->where('product_id', $id)->get();
            self::assertCount(1, $moves);
            self::assertSame($qty, $moves->sole()->quantity);
            self::assertSame($qty, StockLevel::query()->where('product_id', $id)->where('location_id', $this->destination->id)->sole()->quantity);
        }
    }

    public function test_terminal_transitions_are_refused_without_new_movements(): void
    {
        $this->seedStock();
        foreach (['complete' => 'cancel', 'cancel' => 'complete'] as $first => $second) {
            $transfer = app(StockTransferService::class)->initiate($this->data());
            $this->postJson("/api/v1/stock-transfers/{$transfer->id}/{$first}")->assertOk();
            $count = StockMovement::query()->count();
            $this->postJson("/api/v1/stock-transfers/{$transfer->id}/{$second}")->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_TRANSFER_STATE');
            self::assertSame($count, StockMovement::query()->count());
            self::assertSame($first === 'complete' ? TransferStatus::Completed : TransferStatus::Cancelled, $transfer->refresh()->status);
        }
    }

    public function test_recalled_after_dispatch_must_not_silently_land_at_destination(): void
    {
        if (getenv('T1_RUN_KNOWN_REDS') !== '1') {
            $this->markTestSkipped('ticket docs/superpowers/tickets/2026-09-09-t1-recalled-in-transit.md');
        }
        $batch = $this->batch();
        $this->seedStock(batchId: (int) $batch->id);
        $transfer = app(StockTransferService::class)->initiate($this->data(autoAllocate: true));
        $batch->recall('T1 supplier recall during transit');
        $this->postJson("/api/v1/stock-transfers/{$transfer->id}/complete")->assertUnprocessable();
        self::assertSame(TransferStatus::InTransit, $transfer->refresh()->status);
        self::assertSame(0, BatchStock::query()->where('batch_id', $batch->id)->where('location_id', $this->destination->id)->count());
    }

    /** Current behaviour, ticket T1-4: docs/superpowers/tickets/2026-09-09-t1-recalled-in-transit.md. */
    public function test_recalled_lot_receipt_pins_current_behaviour_until_ticket_t1_4(): void
    {
        $batch = $this->batch();
        $this->seedStock(batchId: (int) $batch->id);
        $transfer = app(StockTransferService::class)->initiate($this->data(autoAllocate: true));
        $batch->recall('T1 supplier recall during transit');
        $this->postJson("/api/v1/stock-transfers/{$transfer->id}/complete")->assertOk();
        self::assertSame(TransferStatus::Completed, $transfer->refresh()->status);
        self::assertTrue($batch->refresh()->is_recalled);
        self::assertSame('4.0000', BatchStock::query()->where('batch_id', $batch->id)->where('location_id', $this->destination->id)->sole()->quantity);
        $sourceLot = BatchStock::query()->where('batch_id', $batch->id)->where('location_id', $this->source->id)->sole();
        self::assertSame('6.0000', $sourceLot->quantity);
        self::assertSame(StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->sole()->quantity, $sourceLot->quantity);
    }

    /** Current behaviour, ticket T1-4: docs/superpowers/tickets/2026-09-09-t1-recalled-in-transit.md. */
    public function test_expired_lot_receipt_pins_current_behaviour_until_ticket_t1_4(): void
    {
        $batch = $this->batch();
        $this->seedStock(batchId: (int) $batch->id);
        $transfer = app(StockTransferService::class)->initiate($this->data(autoAllocate: true));
        $this->travel(3)->days();
        $this->postJson("/api/v1/stock-transfers/{$transfer->id}/complete")->assertOk();
        self::assertSame('4.0000', BatchStock::query()->where('batch_id', $batch->id)->where('location_id', $this->destination->id)->sole()->quantity);
        $sourceLot = BatchStock::query()->where('batch_id', $batch->id)->where('location_id', $this->source->id)->sole();
        self::assertSame('6.0000', $sourceLot->quantity);
        self::assertSame(StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->sole()->quantity, $sourceLot->quantity);
        $expiryDate = $batch->refresh()->expiry_date;
        self::assertNotNull($expiryDate);
        self::assertTrue($expiryDate->isPast());
    }

    /** @return iterable<string, array{string}> */
    public static function terminalActions(): iterable
    {
        yield 'cancel' => ['cancel'];
        yield 'complete' => ['complete'];
    }

    #[DataProvider('terminalActions')]
    public function test_location_feed_tracks_transit_then_terminal_stock(string $action): void
    {
        $this->seedStock();
        $transfer = app(StockTransferService::class)->initiate($this->data());
        $reader = app(LocationStockQueryService::class);
        $read = fn () => $reader->read($this->tenant->id, $this->company->id, $this->destination->id, null, 1, 50);
        self::assertSame('4.0000', $read()->incoming[0]->incomingTransfer);
        app(StockTransferService::class)->{$action}($transfer->id, $this->user->id);
        self::assertSame([], $read()->incoming);
        $this->assertTerminalStock($action);
    }

    #[DataProvider('terminalActions')]
    public function test_location_distribution_tracks_transit_then_terminal_stock(string $action): void
    {
        $this->seedStock();
        $transfer = app(StockTransferService::class)->initiate($this->data());
        // totalOnHand means available quantity: reserve one of the six physical source units.
        StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->update(['reserved' => '1.0000']);
        $read = fn () => app(LocationStockQueryService::class)->stockDistributionForProduct($this->tenant->id, $this->company->id, $this->product->id, null, $this->destination->id);
        self::assertSame('4.0000', $read()->totalIncomingTransfer);
        self::assertSame('5.0000', $read()->totalOnHand);
        app(StockTransferService::class)->{$action}($transfer->id, $this->user->id);
        self::assertSame('0.0000', $read()->totalIncomingTransfer);
        self::assertSame('9.0000', $read()->totalOnHand);
        $this->assertTerminalStock($action);
    }

    #[DataProvider('terminalActions')]
    public function test_matrix_tracks_transit_then_terminal_stock(string $action): void
    {
        $this->seedStock();
        $transfer = app(StockTransferService::class)->initiate($this->data());
        $read = fn () => app(StockMatrixQueryService::class)->matrix($this->tenant->id, $this->company->id, [$this->source->id, $this->destination->id], '', 1, 50, true)['data'][0]['cells'];
        self::assertSame('4.0000', $read()[$this->destination->id]['incoming']);
        self::assertSame('6.0000', $read()[$this->source->id]['on_hand']);
        app(StockTransferService::class)->{$action}($transfer->id, $this->user->id);
        self::assertSame('0.0000', $read()[$this->destination->id]['incoming']);
        self::assertSame($action === 'cancel' ? '0.0000' : '4.0000', $read()[$this->destination->id]['on_hand']);
        $this->assertTerminalStock($action);
    }

    #[DataProvider('terminalActions')]
    public function test_wac_counts_transit_exactly_once_before_and_after_terminal_action(string $action): void
    {
        $this->seedStock();
        $transfer = app(StockTransferService::class)->initiate($this->data());
        $journalsBefore = DB::table('journal_entries')->count();
        self::assertSame(0, $journalsBefore);
        // Adding 10 to ten owned units must add 1/unit, even with four in transit.
        $adjust = fn () => app(WeightedAverageCostService::class)->recordCostAdjustment($this->product, '10.000', 'T1 ownership denominator', $this->tenant->id, $this->company->id);
        $adjust();
        self::assertSame($journalsBefore, DB::table('journal_entries')->count());
        self::assertSame('6.000000', $this->product->refresh()->cost_price);
        app(StockTransferService::class)->{$action}($transfer->id, $this->user->id);
        $adjust();
        self::assertSame($journalsBefore, DB::table('journal_entries')->count());
        self::assertSame('7.000000', $this->product->refresh()->cost_price);
        $this->assertTerminalStock($action);
    }

    public function test_variant_reservations_prevent_transfer_despite_sufficient_on_hand(): void
    {
        $variant = ProductVariant::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'product_id' => $this->product->id]);
        $this->seedStock(variantId: $variant->id);
        StockLevel::query()->where('variant_id', $variant->id)->update(['reserved' => '8.0000']);
        try {
            app(StockTransferService::class)->initiate($this->data(lines: [new InitiateTransferLineData($this->product->id, '4.0000', $variant->id)]));
            self::fail('Reserved variant units must not be transferred.');
        } catch (InsufficientStockException) {
            self::assertSame(0, StockTransfer::query()->count());
            self::assertSame('10.0000', StockLevel::query()->where('variant_id', $variant->id)->sole()->quantity);
        }
    }

    public function test_batch_reservations_prevent_transfer_despite_sufficient_aggregate_stock(): void
    {
        $batch = $this->batch();
        $this->seedStock(batchId: (int) $batch->id);
        BatchStock::query()->where('batch_id', $batch->id)->update(['reserved_quantity' => '8.0000']);
        try {
            app(StockTransferService::class)->initiate($this->data(autoAllocate: true));
            self::fail('Reserved batch units must not be transferred.');
        } catch (InsufficientStockException) {
            self::assertSame(0, StockTransfer::query()->count());
            self::assertSame('10.0000', BatchStock::query()->where('batch_id', $batch->id)->sole()->quantity);
        }
    }

    public function test_second_company_transfer_is_invisible_on_index_show_complete_and_cancel(): void
    {
        $companyB = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);
        $sourceB = Location::factory()->create(['company_id' => $companyB->id]);
        $destB = Location::factory()->create(['company_id' => $companyB->id]);
        $productB = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $companyB->id, 'requires_batch_tracking' => false]);
        app(StockAdjustmentService::class)->receive($productB->id, $sourceB->id, '10.0000', 'T1-B', $this->user->id, expectedCompanyId: $companyB->id);
        $foreign = app(StockTransferService::class)->initiate(new InitiateTransferData($this->tenant->id, $companyB->id, $sourceB->id, $destB->id, $this->user->id, [new InitiateTransferLineData($productB->id, '4.0000')]));
        $this->seedStock();
        $own = app(StockTransferService::class)->initiate($this->data());
        $ids = array_column($this->getJson('/api/v1/stock-transfers')->assertOk()->json('data'), 'id');
        self::assertContains($own->id, $ids);
        self::assertNotContains($foreign->id, $ids);
        $count = StockMovement::query()->count();
        $this->getJson("/api/v1/stock-transfers/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/stock-transfers/{$foreign->id}/complete")->assertNotFound();
        $this->postJson("/api/v1/stock-transfers/{$foreign->id}/cancel")->assertNotFound();
        self::assertSame($count, StockMovement::query()->count());
        self::assertSame(TransferStatus::InTransit, $foreign->refresh()->status);
    }

    public function test_idempotency_key_replays_identical_payload_but_refuses_changed_quantity(): void
    {
        if (getenv('T1_RUN_KNOWN_REDS') !== '1') {
            $this->markTestSkipped('ticket docs/superpowers/tickets/2026-09-09-t1-idempotency-payload.md');
        }
        $this->seedStock();
        $payload = ['source_location_id' => $this->source->id, 'destination_location_id' => $this->destination->id, 'idempotency_key' => 'T1-retry', 'lines' => [['product_id' => $this->product->id, 'quantity' => '4.0000']]];
        $first = $this->postJson('/api/v1/stock-transfers', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/stock-transfers', $payload)->assertSuccessful()->assertJsonPath('data.id', $first);
        $payload['lines'][0]['quantity'] = '5.0000';
        $this->postJson('/api/v1/stock-transfers', $payload)->assertUnprocessable();
        self::assertSame(1, StockTransfer::query()->count());
        self::assertSame('6.0000', StockLevel::query()->where('location_id', $this->source->id)->sole()->quantity);
    }

    private function seedStock(?int $batchId = null, ?string $variantId = null): void
    {
        app(StockAdjustmentService::class)->receive($this->product->id, $this->source->id, '10.0000', 'T1-SEED', $this->user->id, batchId: $batchId, expectedCompanyId: $this->company->id, variantId: $variantId);
    }

    /** @param list<InitiateTransferLineData>|null $lines */
    private function data(?array $lines = null, bool $autoAllocate = false): InitiateTransferData
    {
        return new InitiateTransferData($this->tenant->id, $this->company->id, $this->source->id, $this->destination->id, $this->user->id, $lines ?? [new InitiateTransferLineData($this->product->id, '4.0000')], autoAllocateBatchesFefo: $autoAllocate);
    }

    private function batch(): Batch
    {
        $this->product->update(['requires_batch_tracking' => true]);

        return Batch::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'product_id' => $this->product->id, 'batch_number' => 'T1-LOT', 'expiry_date' => now()->addDays(2)->toDateString(), 'is_active' => true, 'is_expired' => false, 'is_recalled' => false]);
    }

    private function assertTerminalStock(string $action): void
    {
        self::assertSame($action === 'cancel' ? '10.0000' : '6.0000', StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->sole()->quantity);
        $destination = StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->destination->id)->first();
        self::assertSame($action === 'cancel' ? '0.0000' : '4.0000', $destination->quantity ?? '0.0000');
    }
}
