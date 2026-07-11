<?php

declare(strict_types=1);

namespace Tests\Feature\Replenishment;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ReplenishmentCriticalPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_capture_is_fulfilled_by_web_transfer_and_returned_in_pos_feed(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CompanyContext::class)->setCompanyId($company->id);

        $warehouse = Location::factory()->create(['company_id' => $company->id, 'type' => 'warehouse']);
        $shop = Location::factory()->create(['company_id' => $company->id, 'type' => 'shop']);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $shop->id,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $cashier = $this->user($tenant, $company, 'cashier', 'critical-cashier@example.test');
        $cashier->givePermissionTo('pos.operate_terminal');
        $processor = $this->user($tenant, $company, 'manager', 'critical-manager@example.test');
        $processor->givePermissionTo([
            'replenishment.view',
            'replenishment.process',
            'inventory.transfers.create',
        ]);

        $capture = $this->actingAs($cashier)
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/api/v1/pos/replenishment-requests', [
                'client_request_uuid' => Str::uuid()->toString(),
                'terminal_id' => $terminal->id,
                'product_id' => $product->id,
                'requested_qty' => '2',
            ])
            ->assertCreated();
        $requestId = (string) $capture->json('data.id');

        $this->actingAs($processor)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/v1/replenishment-requests?status=open')
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.status', ReplenishmentStatus::Pending->value);

        app(StockAdjustmentService::class)->receive(
            productId: $product->id,
            locationId: $warehouse->id,
            quantity: '5',
            reference: 'REPLENISHMENT-E2E-SEED',
            userId: $processor->id,
            expectedCompanyId: $company->id,
        );

        $this->actingAs($processor)
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/api/v1/replenishment-requests/actions/create-transfer', [
                'source_location_id' => $warehouse->id,
                'lines' => [['request_id' => $requestId, 'quantity' => '2.0000']],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.transfer_ids');

        $this->assertSame(
            ReplenishmentStatus::Fulfilled,
            ReplenishmentRequest::query()->findOrFail($requestId)->status,
        );

        $this->actingAs($cashier)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/v1/pos/replenishment-requests?terminal_id='.$terminal->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.status', ReplenishmentStatus::Fulfilled->value);
    }

    private function user(Tenant $tenant, Company $company, string $role, string $email): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => $email]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => $role,
            'status' => 'active',
        ]);

        return $user;
    }
}
