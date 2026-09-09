<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\BatchExpiry\Presentation\Middleware\BatchActionAccess;
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

    public function test_flag_off_preserves_pre_activation_access_and_payloads(): void
    {
        config(['lot_action_permissions.enforce' => false]);
        $middleware = $this->app->make(BatchActionAccess::class);
        $response = $middleware->handle(Request::create('/'), fn () => response()->json(['legacy' => true]), 'batches.recall');
        self::assertSame('{"legacy":true}', $response->getContent());
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
