<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Presentation\Middleware\BatchActionAccess;
use App\Modules\Company\Domain\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/BatchReadLocationScopeTest.php';

final class BatchActionPermissionsTest extends BatchPermissionFixture
{
    public function test_each_existing_batch_route_requires_its_exact_action_permission(): void
    {
        $routes = [
            ['GET', '/batches', 'view'], ['GET', '/batches/expiring', 'view'],
            ['GET', '/batches/expired', 'view'], ['GET', '/batches/uuid', 'view'],
            ['GET', '/batches/uuid/stock', 'view'], ['GET', '/products/uuid/batch-stock', 'view'],
            ['GET', '/pos/products/uuid/batches', 'view'],
            ['GET', '/batches/uuid/traceability', 'traceability'],
            ['GET', '/partners/uuid/batch-history', 'traceability'],
            ['DELETE', '/batches/uuid', 'delete'], ['POST', '/batches/uuid/recall', 'recall'],
        ];
        foreach ($routes as [$method, $path, $permission]) {
            $route = $this->app['router']->getRoutes()->match(Request::create('/api/v1'.$path, $method));
            self::assertContains(BatchActionAccess::class.':batches.'.$permission, $route->gatherMiddleware(), $path);
        }
    }

    public function test_create_update_transfer_and_write_off_keep_existing_authorization_without_batch_action_middleware(): void
    {
        foreach ([['POST', '/batches'], ['PATCH', '/batches/uuid'], ['POST', '/batches/uuid/transfer'], ['POST', '/batches/uuid/write-off']] as [$method, $path]) {
            $route = $this->app['router']->getRoutes()->match(Request::create('/api/v1'.$path, $method));
            self::assertSame([], array_values(array_filter($route->gatherMiddleware(), fn (string $middleware): bool => str_starts_with($middleware, BatchActionAccess::class))));
        }
    }

    public function test_flag_off_preserves_access_and_declared_string_scoped_payload_contract(): void
    {
        $this->travelTo(now()->startOfDay());
        $this->restrict(null);
        config(['lot_action_permissions.enforce' => false]);
        Role::findByName('admin', 'sanctum')->revokePermissionTo('batches.view');
        $this->user->unsetRelations();
        self::assertFalse($this->user->can('batches.view'));
        $batch = $this->lot();
        $batch->update(['expiry_date' => now()->subDay(), 'is_expired' => true]);
        $batch->refresh();
        $other = Location::factory()->create(['company_id' => $this->company->id]);
        $this->stockAt($batch, $this->location, '3.1234', '0.1000');
        $this->stockAt($batch, $other, '9.0000');
        $stock = [
            ['location_id' => $this->location->id, 'quantity' => '3.1234', 'reserved_quantity' => '0.1000', 'available_quantity' => '3.0234'],
            ['location_id' => $other->id, 'quantity' => '9.0000', 'reserved_quantity' => '0.0000', 'available_quantity' => '9.0000'],
        ];
        $expected = [
            'id' => $batch->id, 'uuid' => $batch->uuid, 'product_id' => $this->product->id, 'variant_id' => null,
            'batch_number' => 'LOT-WLOTA1A', 'manufacturing_date' => null, 'expiry_date' => now()->subDay()->toDateString(),
            'days_until_expiry' => -1, 'is_active' => true, 'is_expired' => true, 'is_recalled' => false,
            'recall_reason' => null, 'recalled_at' => null, 'notes' => null, 'expiry_status' => 'EXPIRED', 'can_be_sold' => false,
            'total_quantity' => '12.1234', 'available_quantity' => '12.0234',
            'product' => ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku, 'quantity_decimals' => 4],
            'batch_stock' => $stock, 'created_at' => $batch->created_at->toIso8601String(), 'updated_at' => $batch->updated_at->toIso8601String(),
        ];
        foreach (['/batches', '/products/'.$this->product->id.'/batch-stock'] as $path) {
            $endpointExpected = $expected;
            if (str_starts_with($path, '/products/')) {
                unset($endpointExpected['product']);
            }
            $this->getJson('/api/v1'.$path)->assertOk()->assertExactJson(['data' => [$endpointExpected]]);
        }
        $this->getJson('/api/v1/batches/'.$batch->uuid)->assertOk()->assertExactJson(['data' => $expected]);
        $scoped = [...$expected, 'total_quantity' => '3.1234', 'available_quantity' => '3.0234', 'batch_stock' => [$stock[0]]];
        $this->getJson('/api/v1/batches/expired?'.http_build_query(['location_ids' => [$this->location->id]]))
            ->assertOk()->assertExactJson(['data' => [$scoped]]);
        $batch->update(['expiry_date' => now()->addDays(5), 'is_expired' => false]);
        $expiring = [...$scoped, 'expiry_date' => now()->addDays(5)->toDateString(), 'days_until_expiry' => 5,
            'is_expired' => false, 'expiry_status' => 'CRITICAL', 'can_be_sold' => true];
        $this->getJson('/api/v1/batches/expiring?'.http_build_query(['location_id' => $this->location->id]))
            ->assertOk()->assertExactJson(['data' => [$expiring]]);
        $created = $this->postJson('/api/v1/batches', ['product_id' => $this->product->id, 'batch_number' => 'CREATED-CONTRACT', 'expiry_date' => now()->addDays(60)->toDateString()])
            ->assertCreated();
        $model = Batch::where('uuid', $created->json('data.uuid'))->firstOrFail();
        // Existing create response does not hydrate database-default booleans; retain that flag-off shape.
        $newExpected = [...$expected, 'id' => $model->id, 'uuid' => $model->uuid, 'batch_number' => 'CREATED-CONTRACT',
            'expiry_date' => now()->addDays(60)->toDateString(), 'days_until_expiry' => 60, 'is_active' => null, 'is_expired' => null, 'is_recalled' => null,
            'expiry_status' => 'APPROACHING', 'can_be_sold' => false,
            'total_quantity' => '0.0000', 'available_quantity' => '0.0000', 'batch_stock' => [],
            'created_at' => $model->created_at->toIso8601String(), 'updated_at' => $model->updated_at->toIso8601String()];
        $created->assertExactJson(['data' => $newExpected]);
    }

    public function test_cashier_denied_recall_leaves_stock_history_reservations_and_gl_unchanged(): void
    {
        $this->deniedMutation('cashier', 'POST', '/recall');
    }

    public function test_viewer_denied_delete_leaves_stock_history_reservations_and_gl_unchanged(): void
    {
        $this->deniedMutation('viewer', 'DELETE', '');
    }

    public function test_manager_denied_global_recall_leaves_stock_history_reservations_and_gl_unchanged(): void
    {
        $this->deniedMutation('manager', 'POST', '/recall');
    }

    private function deniedMutation(string $roleName, string $method, string $suffix): void
    {
        $batch = $this->lot();
        $this->stockAt($batch, $this->location, '8.1234', '2.0000');
        $role = Role::findByName($roleName, 'sanctum');
        $role->revokePermissionTo('batches.recall');
        $this->user->syncRoles([$role]);
        $this->restrict(null);
        $tables = ['product_batches', 'inventory_batch_stock', 'stock_reservations', 'document_lines',
            'pos_receipt_line_batch_allocations', 'journal_entries', 'journal_lines'];
        $snapshot = static fn (): array => array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(), $tables);
        $before = $snapshot();
        $this->json($method, '/api/v1/batches/'.$batch->uuid.$suffix, ['reason' => 'safety'])->assertForbidden();
        self::assertSame($before, $snapshot());
    }
}
