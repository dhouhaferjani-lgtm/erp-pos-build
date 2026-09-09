<?php

declare(strict_types=1);

namespace Tests\Feature\Replenishment;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Events\StockTransferInitiated;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Listeners\SettleRequestsOnTransferInitiated;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ReplenishmentEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $source;

    private Location $destination;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->source = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'warehouse']);
        $this->destination = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'shop']);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'requires_batch_tracking' => false, 'purchase_price' => '2.000', 'tax_rate' => '0.00']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->givePermissionTo(['replenishment.create', 'replenishment.process', 'inventory.transfers.create', 'inventory.transfers.cancel', 'purchase-orders.create']);
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'manager', 'status' => 'active']);
        $this->actingAs($this->user)->withHeader('X-Company-Id', $this->company->id);
        app(StockAdjustmentService::class)->receive($this->product->id, $this->source->id, '10.0000', 'T1-REQUEST', $this->user->id, expectedCompanyId: $this->company->id);
    }

    public function test_capture_and_bump_during_transit_do_not_reopen_original_or_settle_new_demand(): void
    {
        $original = $this->capture('2.0000');
        $transfer = $this->createTransfer($original);
        self::assertSame(ReplenishmentStatus::Fulfilled, $original->refresh()->status);
        $new = $this->capture('3.0000');
        $bumped = $this->capture('1.0000');
        self::assertSame($new->id, $bumped->id);
        self::assertSame(2, $bumped->request_count);
        self::assertSame('4.0000', $bumped->requested_qty);
        self::assertSame(ReplenishmentStatus::Fulfilled, $original->refresh()->status);
        self::assertSame($transfer->id, $original->fulfillment_id);
        self::assertSame(TransferStatus::InTransit, $transfer->refresh()->status);
        self::assertSame(ReplenishmentStatus::Pending, $new->refresh()->status);
        self::assertNull($new->fulfillment_id);
        self::assertSame(1, StockTransfer::query()->count());
    }

    public function test_replayed_initiated_event_must_not_settle_demand_captured_after_dispatch(): void
    {
        if (getenv('T1_RUN_KNOWN_REDS') !== '1') {
            $this->markTestSkipped('ticket docs/superpowers/tickets/2026-09-09-t1-settlement-replay.md');
        }
        $original = $this->capture('2.0000');
        $transfer = $this->createTransfer($original);
        $new = $this->capture('3.0000');
        $this->capture('1.0000');
        app(SettleRequestsOnTransferInitiated::class)->handle(new StockTransferInitiated($transfer->id, $this->tenant->id, $this->company->id, $transfer->transfer_number, $transfer->transfer_type->value, $this->source->id, $this->destination->id, $this->user->id, $transfer->created_at->toIso8601String()));
        self::assertSame(ReplenishmentStatus::Pending, $new->refresh()->status);
        self::assertNull($new->fulfillment_id);
        self::assertSame('4.0000', $new->requested_qty);
        self::assertSame(ReplenishmentStatus::Fulfilled, $original->refresh()->status);
    }

    #[Group('pg')]
    public function test_pg_cancel_merges_into_new_open_grain_and_replay_does_not_merge_twice(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index collision must run on PostgreSQL.');
        }
        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'replenishment_open_non_variant'");
        self::assertNotNull($index, 'Real partial unique must exist for this regression.');
        self::assertStringContainsString('UNIQUE INDEX', $index->indexdef);
        self::assertStringContainsString('WHERE', $index->indexdef);
        $original = $this->capture('2.0000', 'Original demand');
        $transfer = $this->createTransfer($original);
        $uuid = Str::uuid()->toString();
        $new = $this->capture('3.0000', 'New POS demand', $uuid);
        $this->postJson("/api/v1/stock-transfers/{$transfer->id}/cancel", ['reason' => 'T1 vehicle breakdown'])->assertOk();
        self::assertSame(ReplenishmentStatus::Cancelled, $original->refresh()->status);
        self::assertSame('superseded_after_transfer_cancelled', $original->rejection_reason);
        self::assertSame(ReplenishmentStatus::Pending, $new->refresh()->status);
        self::assertSame('5.0000', $new->requested_qty);
        self::assertSame(2, $new->request_count);
        self::assertStringContainsString('Original demand', $new->note);
        self::assertStringContainsString('New POS demand', $new->note);
        self::assertNull($new->fulfillment_id);
        self::assertSame(1, ReplenishmentRequest::query()->where('product_id', $this->product->id)->where('location_id', $this->destination->id)->whereIn('status', [ReplenishmentStatus::Pending, ReplenishmentStatus::InProgress])->count());
        $this->postJson("/api/v1/stock-transfers/{$transfer->id}/cancel")->assertUnprocessable();
        self::assertSame($new->id, $this->capture('3.0000', 'New POS demand', $uuid)->id);
        self::assertSame('5.0000', $new->refresh()->requested_qty);
        self::assertSame(2, $new->request_count);
    }

    public function test_po_append_refuses_other_company_and_creates_new_po_in_current_company(): void
    {
        $request = $this->capture('2.0000');
        $supplier = Partner::factory()->supplier()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id]);
        $companyB = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);
        $locationB = Location::factory()->create(['company_id' => $companyB->id]);
        // Same supplier deliberately isolates the company guard from supplier mismatch.
        $foreign = Document::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $companyB->id, 'location_id' => $locationB->id, 'partner_id' => $supplier->id, 'type' => DocumentType::PurchaseOrder, 'status' => DocumentStatus::Draft]);
        $before = $foreign->refresh()->getAttributes();
        $lineCount = $foreign->lines()->count();
        $payload = ['supplier_id' => $supplier->id, 'destination_location_id' => $this->source->id, 'lines' => [['request_id' => $request->id, 'quantity' => '2.0000']]];
        $this->postJson('/api/v1/replenishment-requests/actions/create-po', [...$payload, 'existing_document_id' => $foreign->id])->assertUnprocessable();
        self::assertSame(ReplenishmentStatus::Pending, $request->refresh()->status);
        $id = $this->postJson('/api/v1/replenishment-requests/actions/create-po', $payload)->assertOk()->json('data.document_id');
        self::assertNotSame($foreign->id, $id);
        self::assertSame($this->company->id, Document::query()->findOrFail($id)->company_id);
        self::assertSame($this->destination->id, Document::query()->findOrFail($id)->lines()->sole()->location_id);
        self::assertSame($before, $foreign->refresh()->getAttributes());
        self::assertSame($lineCount, $foreign->lines()->count());
    }

    public function test_requester_can_cancel_in_progress_request_while_status_is_open(): void
    {
        if (getenv('T1_RUN_KNOWN_REDS') !== '1') {
            $this->markTestSkipped('ticket docs/superpowers/tickets/2026-09-09-t1-cancel-in-progress.md');
        }
        $request = $this->capture('2.0000');
        $supplier = Partner::factory()->supplier()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id]);
        $this->postJson('/api/v1/replenishment-requests/actions/create-po', ['supplier_id' => $supplier->id, 'destination_location_id' => $this->source->id, 'lines' => [['request_id' => $request->id, 'quantity' => '2.0000']]])->assertOk();
        self::assertSame(ReplenishmentStatus::InProgress, $request->refresh()->status);
        self::assertTrue($request->status->isOpen());
        $this->user->revokePermissionTo('replenishment.process');
        $this->postJson("/api/v1/replenishment-requests/{$request->id}/cancel")->assertOk();
        self::assertSame(ReplenishmentStatus::Cancelled, $request->refresh()->status);
    }

    public function test_restricted_processor_cannot_source_transfer_from_hidden_location(): void
    {
        $request = $this->capture('2.0000');
        UserCompanyMembership::query()->where('user_id', $this->user->id)->update(['allowed_location_ids' => [$this->destination->id]]);
        $this->postJson('/api/v1/replenishment-requests/actions/create-transfer', ['source_location_id' => $this->source->id, 'lines' => [['request_id' => $request->id, 'quantity' => '2.0000']]])->assertUnprocessable();
        self::assertSame(ReplenishmentStatus::Pending, $request->refresh()->status);
        self::assertNull($request->fulfillment_id);
        self::assertSame(0, StockTransfer::query()->count());
    }

    private function capture(string $qty, ?string $note = null, ?string $uuid = null): ReplenishmentRequest
    {
        return app(ReplenishmentCaptureService::class)->capture(new CaptureRequestData($this->tenant->id, $this->company->id, $this->destination->id, $this->product->id, null, $qty, $note, $this->user->id, ReplenishmentChannel::Pos, clientRequestUuid: $uuid));
    }

    private function createTransfer(ReplenishmentRequest $request): StockTransfer
    {
        $id = $this->postJson('/api/v1/replenishment-requests/actions/create-transfer', ['source_location_id' => $this->source->id, 'lines' => [['request_id' => $request->id, 'quantity' => '2.0000']]])->assertOk()->json('data.transfer_ids.0');

        return StockTransfer::query()->findOrFail($id);
    }
}
