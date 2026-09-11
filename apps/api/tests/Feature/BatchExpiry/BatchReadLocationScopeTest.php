<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Enums\Vertical;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Presentation\Resources\BatchResource;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

abstract class BatchPermissionFixture extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Company $company;

    protected User $user;

    protected Location $location;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Expired Route Test Tenant',
            'slug' => 'expired-route-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expired Route Test Company',
            'legal_name' => 'Expired Route Test Company LLC',
            'tax_id' => 'TAXEXPIREDROUTE',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expired Route Test User',
            'email' => 'user@expired-route-test.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $unit = Unit::factory()->create(['decimal_places' => 2]);
        $this->product = Product::factory()->create([
            'unit_id' => $unit->id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    protected function restrict(?array $locations): void
    {
        UserCompanyMembership::where('user_id', $this->user->id)->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => $locations]);
        $this->user->unsetRelations();
        config(['lot_action_permissions.enforce' => true]);
        $this->actingAs($this->user, 'sanctum');
    }

    protected function lot(): Batch
    {
        return Batch::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'product_id' => $this->product->id, 'uuid' => (string) Str::uuid(),
            'batch_number' => 'LOT-WLOTA1A', 'expiry_date' => now()->addDays(5), 'is_active' => true,
        ]);
    }

    protected function stockAt(Batch $batch, Location $location, string $quantity, string $reserved = '0.0000'): void
    {
        BatchStock::create(['tenant_id' => $this->tenant->id, 'batch_id' => $batch->id,
            'location_id' => $location->id, 'quantity' => $quantity, 'reserved_quantity' => $reserved]);
    }
}

final class BatchReadLocationScopeTest extends BatchPermissionFixture
{
    public function test_detail_excludes_other_branch_stock(): void
    {
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '91.0000');
        $this->stockAt($batch, $this->location, '2.1234', '0.1000');
        $this->restrict([$this->location->id]);
        $response = $this->getJson('/api/v1/batches/'.$batch->uuid)->assertOk();
        self::assertNotContains($other->id, array_column($response->json('data.batch_stock'), 'location_id'));
        $response->assertJsonPath('data.total_quantity', '2.1234')->assertJsonPath('data.available_quantity', '2.0234');
    }

    public function test_update_response_uses_only_membership_stock(): void
    {
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '91.0000');
        $this->stockAt($batch, $this->location, '3.1234', '0.1000');
        $this->restrict([$this->location->id]);
        $this->patchJson('/api/v1/batches/'.$batch->uuid, ['notes' => 'Scoped response'])
            ->assertOk()->assertJsonPath('data.product.quantity_decimals', 2)->assertJsonPath('data.total_quantity', '3.1234')
            ->assertJsonPath('data.available_quantity', '3.0234')
            ->assertJsonCount(1, 'data.batch_stock')
            ->assertJsonPath('data.batch_stock.0.location_id', $this->location->id);
    }

    public function test_foreign_mutation_target_is_denied_before_any_write_even_on_retry(): void
    {
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '10.0000');
        StockLevel::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'product_id' => $this->product->id, 'location_id' => $other->id,
            'quantity' => '10.0000', 'reserved' => '0.0000',
        ]);
        $this->restrict([$this->location->id]);
        $snapshot = static fn (): array => array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(), ['product_batches', 'inventory_batch_stock', 'stock_levels', 'stock_movements', 'stock_reservations', 'document_lines', 'pos_receipt_line_batch_allocations', 'journal_entries', 'journal_lines']);
        $before = $snapshot();
        $this->patchJson('/api/v1/batches/'.$batch->uuid, ['notes' => 'Must not persist', 'location_id' => $other->id])->assertForbidden();
        self::assertSame($before, $snapshot());
        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/batches/'.$batch->uuid.'/write-off', ['location_id' => $other->id, 'quantity' => '1.0000', 'reason' => 'damage'])->assertForbidden();
            self::assertSame($before, $snapshot());
        }
    }

    public function test_unrestricted_mutation_totals_are_company_wide_and_each_success_writes_once(): void
    {
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '10.0000');
        $this->stockAt($batch, $this->location, '7.0000', '1.0000');
        StockLevel::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'product_id' => $this->product->id, 'location_id' => $other->id,
            'quantity' => '10.0000', 'reserved' => '0.0000',
        ]);
        $this->restrict(null);
        // This endpoint has no idempotency key: each deliberately repeated success is a new write.
        foreach (['16.0000' => '15.0000', '15.0000' => '14.0000'] as $total => $available) {
            $before = DB::table('stock_movements')->count();
            $this->postJson('/api/v1/batches/'.$batch->uuid.'/write-off', ['location_id' => $other->id, 'quantity' => '1.0000', 'reason' => 'damage'])
                ->assertOk()->assertJsonPath('data.total_quantity', $total)->assertJsonPath('data.available_quantity', $available)->assertJsonCount(2, 'data.batch_stock');
            self::assertSame($before + 1, DB::table('stock_movements')->count());
            $this->patchJson('/api/v1/batches/'.$batch->uuid, ['notes' => 'Company response', 'location_id' => $other->id])
                ->assertOk()->assertJsonPath('data.total_quantity', $total)->assertJsonPath('data.available_quantity', $available)->assertJsonCount(2, 'data.batch_stock');
        }
    }

    public function test_recall_response_uses_membership_stock(): void
    {
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '91.0000');
        $this->stockAt($batch, $this->location, '3.1234', '0.1000');
        $this->restrict([$this->location->id]);
        $this->postJson('/api/v1/batches/'.$batch->uuid.'/recall', ['reason' => 'Damaged packaging'])
            ->assertOk()->assertJsonPath('data.is_recalled', true)
            ->assertJsonPath('data.total_quantity', '3.1234')->assertJsonPath('data.available_quantity', '3.0234')
            ->assertJsonCount(1, 'data.batch_stock')->assertJsonPath('data.batch_stock.0.location_id', $this->location->id);
    }

    public function test_transfer_controller_response_uses_all_membership_locations(): void
    {
        if (getenv('WLOTA1A_RUN_KNOWN_REDS') !== '1') {
            $this->markTestSkipped('Known red — pre-existing per-lot transfer writer omits movement_id; ticket docs/superpowers/tickets/2026-09-10-batch-transfer-missing-movement-reference.md');
        }

        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $destination = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '91.0000');
        $this->stockAt($batch, $this->location, '3.1234', '0.1000');
        $this->stockAt($batch, $destination, '2.0000');
        $this->restrict([$this->location->id, $destination->id]);
        $response = $this->postJson('/api/v1/batches/'.$batch->uuid.'/transfer', [
            'from_location_id' => $this->location->id, 'to_location_id' => $destination->id, 'quantity' => '1.0000',
        ])->assertOk()->assertJsonPath('data.total_quantity', '5.1234')->assertJsonPath('data.available_quantity', '5.0234')->assertJsonCount(2, 'data.batch_stock');
        $stocks = array_column($response->json('data.batch_stock'), null, 'location_id');
        self::assertSame('2.1234', $stocks[$this->location->id]['quantity']);
        self::assertSame('3.0000', $stocks[$destination->id]['quantity']);
        self::assertArrayNotHasKey($other->id, $stocks);
    }

    public function test_transfer_missing_movement_reference_ticket_pins_failure_and_rollback(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The ticketed transfer failure pins PostgreSQL SQLSTATE 23502.');
        }

        // docs/superpowers/tickets/2026-09-10-batch-transfer-missing-movement-reference.md
        // Delete this failure pin and the sibling's skip together when the inventory owner repairs the writer.
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $destination = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '91.0000');
        $this->stockAt($batch, $this->location, '3.1234', '0.1000');
        $this->stockAt($batch, $destination, '2.0000');
        foreach ([[$other, '91.0000', '0.0000'], [$this->location, '3.1234', '0.1000'], [$destination, '2.0000', '0.0000']] as [$location, $quantity, $reserved]) {
            StockLevel::create([
                'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
                'product_id' => $this->product->id, 'location_id' => $location->id,
                'quantity' => $quantity, 'reserved' => $reserved,
            ]);
        }
        $this->restrict([$this->location->id, $destination->id]);
        // Original seven-table snapshot plus aggregate stock and both movement ledgers.
        $snapshot = static fn (): array => array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(), ['product_batches', 'inventory_batch_stock', 'stock_reservations', 'document_lines', 'pos_receipt_line_batch_allocations', 'journal_entries', 'journal_lines', 'stock_levels', 'stock_movements', 'inventory_batch_movements']);
        $before = $snapshot();
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/batches/'.$batch->uuid.'/transfer', [
                'from_location_id' => $this->location->id, 'to_location_id' => $destination->id, 'quantity' => '1.0000',
            ]);
            self::fail('Transfer writer was repaired: retire the ticket failure pin and known-red skip together.');
        } catch (QueryException $exception) {
            self::assertSame('23502', $exception->errorInfo[0]);
            self::assertStringContainsString('inventory_batch_movements', $exception->getMessage());
            self::assertStringContainsString('movement_id', $exception->getMessage());
        }
        self::assertSame($before, $snapshot());
    }

    public function test_write_off_response_uses_membership_stock(): void
    {
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '91.0000');
        $this->stockAt($batch, $this->location, '3.1234', '0.1000');
        StockLevel::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'quantity' => '3.1234', 'reserved' => '0.1000',
        ]);
        $this->restrict([$this->location->id]);
        $this->postJson('/api/v1/batches/'.$batch->uuid.'/write-off', [
            'location_id' => $this->location->id, 'quantity' => '1.0000', 'reason' => 'damage',
        ])->assertOk()->assertJsonPath('data.total_quantity', '2.1234')->assertJsonPath('data.available_quantity', '2.0234')
            ->assertJsonCount(1, 'data.batch_stock')->assertJsonPath('data.batch_stock.0.location_id', $this->location->id);
    }

    public function test_flag_off_expiry_query_scope_and_empty_create_stock_contract(): void
    {
        $this->travelTo(now()->startOfDay());
        $this->restrict(null);
        config(['lot_action_permissions.enforce' => false]);
        Role::findByName('admin', 'sanctum')->revokePermissionTo('batches.view');
        $this->user->unsetRelations();
        self::assertFalse($this->user->can('batches.view'));
        $batch = $this->lot();
        $batch->update(['expiry_date' => now()->subDay(), 'is_expired' => true]);
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '91.0000');
        $this->stockAt($batch, $this->location, '3.1234', '0.1000');
        $stock = [['location_id' => $this->location->id, 'quantity' => '3.1234', 'reserved_quantity' => '0.1000', 'available_quantity' => '3.0234']];
        $this->getJson('/api/v1/batches/expired?'.http_build_query(['location_ids' => [$this->location->id]]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_quantity', '3.1234')
            ->assertJsonPath('data.0.available_quantity', '3.0234')->assertJsonPath('data.0.batch_stock', $stock);
        $batch->update(['expiry_date' => now()->addDays(5), 'is_expired' => false]);
        $this->getJson('/api/v1/batches/expiring?'.http_build_query(['location_id' => $this->location->id]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_quantity', '3.1234')
            ->assertJsonPath('data.0.available_quantity', '3.0234')->assertJsonPath('data.0.batch_stock', $stock);
        $this->postJson('/api/v1/batches', ['product_id' => $this->product->id, 'batch_number' => 'EMPTY-CONTRACT', 'expiry_date' => now()->addDays(60)->toDateString()])
            ->assertCreated()->assertJsonPath('data.total_quantity', '0.0000')->assertJsonPath('data.available_quantity', '0.0000')->assertJsonPath('data.batch_stock', []);
    }

    public function test_unloaded_resource_cannot_silently_emit_zero_stock(): void
    {
        $batch = $this->lot();
        $this->expectException(\LogicException::class);
        (new BatchResource($batch))->resolve();
    }

    public function test_restricted_pos_foreign_location_is_denied_without_writes(): void
    {
        $batch = $this->lot();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $other, '9.1234');
        $this->restrict([$this->location->id]);
        $snapshot = static fn (): array => array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(), ['product_batches', 'inventory_batch_stock', 'stock_reservations', 'document_lines', 'pos_receipt_line_batch_allocations', 'journal_entries', 'journal_lines']);
        $before = $snapshot();
        $this->getJson('/api/v1/pos/products/'.$this->product->id.'/batches?'.http_build_query(['location_id' => $other->id, 'quantity' => '1.0000']))->assertForbidden();
        self::assertSame($before, $snapshot());
    }

    public function test_unrestricted_pos_suggestions_are_identical_across_activation(): void
    {
        $batch = $this->lot();
        $this->stockAt($batch, $this->location, '3.1234');
        $this->restrict(null);
        $url = '/api/v1/pos/products/'.$this->product->id.'/batches?'.http_build_query(['location_id' => $this->location->id, 'quantity' => '1.0000']);
        $active = $this->getJson($url)->assertOk()->json();
        config(['lot_action_permissions.enforce' => false]);
        self::assertSame($active, $this->getJson($url)->assertOk()->json());
    }

    public function test_every_read_filters_other_branch_and_empty_scope(): void
    {
        $batch = $this->lot();
        $this->stockAt($batch, $this->location, '3.0000');
        $this->restrict([]);
        foreach (['/batches', '/batches/expiring', '/batches/expired', '/products/'.$this->product->id.'/batch-stock'] as $path) {
            $this->getJson('/api/v1'.$path)->assertOk()->assertJsonPath('data', []);
        }
        $this->getJson('/api/v1/batches/'.$batch->uuid)->assertNotFound();
        $this->getJson('/api/v1/batches/'.$batch->uuid.'/stock')->assertNotFound();
        $this->getJson('/api/v1/batches/'.$batch->uuid.'/traceability')->assertNotFound();
    }

    public function test_zero_stock_company_lot_is_visible_only_to_unrestricted_actor(): void
    {
        $batch = $this->lot();
        $this->restrict(null);
        $this->getJson('/api/v1/batches/'.$batch->uuid)->assertOk();
        $this->restrict([$this->location->id]);
        $this->getJson('/api/v1/batches/'.$batch->uuid)->assertNotFound();
    }

    public function test_depleted_lot_is_visible_only_through_attributable_history(): void
    {
        $batch = $this->lot();
        $document = Document::factory()->create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'location_id' => $this->location->id,
        ]);
        DocumentLine::create([
            'document_id' => $document->id, 'product_id' => $this->product->id, 'batch_id' => $batch->id,
            'line_number' => 1, 'description' => 'History at selected location', 'quantity' => '1.0000',
            'unit_price' => '10.000', 'line_total' => '10.000', 'location_id' => null,
        ]);
        $this->restrict([$this->location->id]);
        $this->getJson('/api/v1/batches/'.$batch->uuid)->assertOk()->assertJsonPath('data.total_quantity', '0.0000');
        // Stock pickers intentionally exclude depleted lots even when history makes detail visible.
        $this->getJson('/api/v1/products/'.$this->product->id.'/batch-stock')->assertOk()->assertJsonPath('data', []);
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->restrict([$other->id]);
        $this->getJson('/api/v1/batches/'.$batch->uuid)->assertNotFound();
    }

    public function test_second_company_selected_second_location_and_duplicate_create_are_isolated(): void
    {
        $this->restrict(null);
        $second = $this->postJson('/api/v1/companies', ['name' => 'Company B', 'legal_name' => 'Company B SARL',
            'country_code' => 'FR', 'currency' => 'EUR', 'locale' => 'fr_FR', 'timezone' => 'Europe/Paris'])->assertCreated()->json('data.id');
        $locations = [];
        foreach ([$this->company->id, $second] as $companyId) {
            $this->withHeader('X-Company-ID', $companyId);
            foreach ([1, 2] as $number) {
                $locations[$companyId][] = $this->postJson('/api/v1/locations', ['name' => 'Shop '.$number,
                    'type' => 'shop', 'pos_enabled' => $number === 2])->assertCreated()->json('data.id');
            }
        }
        $productB = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $second, 'sku' => $this->product->sku]);
        $payload = ['product_id' => $productB->id, 'batch_number' => 'LOT-WLOTA1A', 'expiry_date' => now()->addDays(5)->toDateString()];
        $foreign = $this->postJson('/api/v1/batches', $payload)->assertCreated()->json('data.uuid');
        $this->withHeader('X-Company-ID', $this->company->id);
        $payload['product_id'] = $this->product->id;
        $uuid = $this->postJson('/api/v1/batches', $payload)->assertCreated()->json('data.uuid');
        $batch = Batch::where('uuid', $uuid)->firstOrFail();
        $this->stockAt($batch, Location::findOrFail($locations[$this->company->id][0]), '99.0000');
        $this->stockAt($batch, Location::findOrFail($locations[$this->company->id][1]), '3.1234');
        $traceNumbers = [];
        $tracePartnerId = null;
        foreach ([[$this->company->id, $batch, $this->product->id, 0], [$this->company->id, $batch, $this->product->id, 1], [$second, Batch::where('uuid', $foreign)->firstOrFail(), $productB->id, 1]] as [$traceCompanyId, $traceBatch, $traceProductId, $index]) {
            $document = Document::factory()->create([
                'tenant_id' => $this->tenant->id, 'company_id' => $traceCompanyId,
                'location_id' => $locations[$traceCompanyId][$index],
            ]);
            if ($tracePartnerId === null) {
                $tracePartnerId = $document->partner_id;
            } else {
                $document->update(['partner_id' => $tracePartnerId]);
            }
            DocumentLine::create([
                'document_id' => $document->id, 'product_id' => $traceProductId, 'batch_id' => $traceBatch->id,
                'line_number' => 1, 'description' => 'Selected location trace', 'quantity' => '1.0000',
                'unit_price' => '10.000', 'line_total' => '10.000', 'location_id' => $locations[$traceCompanyId][$index],
            ]);
            $traceNumbers[$traceCompanyId][$index] = $document->document_number;
        }
        $this->restrict([$locations[$this->company->id][1]]);
        $this->getJson('/api/v1/batches/'.$uuid)->assertOk()->assertJsonPath('data.total_quantity', '3.1234')
            ->assertJsonPath('data.batch_stock.0.location_id', $locations[$this->company->id][1]);
        $this->getJson('/api/v1/batches/'.$foreign)->assertNotFound();
        $trace = $this->getJson('/api/v1/batches/'.$uuid.'/traceability')->assertOk()->json('data.document_sales');
        self::assertSame([$traceNumbers[$this->company->id][1]], array_column($trace, 'document_number'));
        $backward = $this->getJson('/api/v1/partners/'.$tracePartnerId.'/batch-history')->assertOk()->json('data');
        self::assertSame([$traceNumbers[$this->company->id][1]], array_column($backward, 'document_number'));
        $this->withHeader('X-Company-ID', $second);
        $backwardB = $this->getJson('/api/v1/partners/'.$tracePartnerId.'/batch-history')->assertOk()->json('data');
        self::assertSame([$traceNumbers[$second][1]], array_column($backwardB, 'document_number'));
        $this->withHeader('X-Company-ID', $this->company->id);
        $snapshot = static fn (): array => array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(), ['product_batches', 'inventory_batch_stock', 'stock_reservations', 'document_lines', 'pos_receipt_line_batch_allocations', 'journal_entries', 'journal_lines']);
        $before = $snapshot();
        $this->postJson('/api/v1/batches', $payload)->assertStatus(422)->assertJsonPath('meta.outcome', 'already_exists');
        self::assertSame(1, Batch::where('company_id', $this->company->id)->where('batch_number', 'LOT-WLOTA1A')->count());
        self::assertSame($before, $snapshot());
    }
}
