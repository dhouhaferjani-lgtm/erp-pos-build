<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
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
 * Enforces per-user location scoping on stock transfers.
 *
 * A membership with allowed_location_ids = [X] may only:
 *  - SEE transfers where source OR destination is in its allowed set (so it
 *    sees incoming transfers from elsewhere),
 *  - COMPLETE (receive) a transfer whose DESTINATION it can access,
 *  - CANCEL a transfer whose SOURCE it can access (cancel restocks source),
 *  - INITIATE a transfer whose SOURCE it can access (destination may be anywhere).
 *
 * A membership with NULL allowed_location_ids is unrestricted (sees/does all).
 */
class StockTransferLocationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    /** User restricted to $shop only. */
    private User $restricted;

    /** User with NULL allowed_location_ids (all locations). */
    private User $unrestricted;

    private Location $warehouse;

    private Location $shop;

    private Location $depot;

    private Product $product;

    private StockTransferService $service;

    private StockAdjustmentService $stockService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Scope Tenant',
            'slug' => 'scope-tenant',
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

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->shop = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SH-01',
            'name' => 'Shop',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->depot = Location::create([
            'company_id' => $this->company->id,
            'code' => 'DP-01',
            'name' => 'Depot',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->restricted = $this->makeUser('restricted@e.com', allowed: [$this->shop->id]);
        $this->unrestricted = $this->makeUser('all@e.com', allowed: null);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'WIDGET',
            'name' => 'Widget',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);

        $this->service = app(StockTransferService::class);
        $this->stockService = app(StockAdjustmentService::class);
    }

    /**
     * @param  array<int, string>|null  $allowed
     */
    private function makeUser(string $email, ?array $allowed): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $user->givePermissionTo([
            'inventory.transfers.view',
            'inventory.transfers.create',
            'inventory.transfers.complete',
            'inventory.transfers.cancel',
            'products.view',
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'allowed_location_ids' => $allowed,
            'status' => 'active',
        ]);

        return $user;
    }

    private function seedStock(Location $loc, string $qty): void
    {
        $this->stockService->receive(
            productId: $this->product->id,
            locationId: $loc->id,
            quantity: $qty,
            reference: 'SEED',
            userId: $this->unrestricted->id,
            expectedCompanyId: $this->company->id,
        );
    }

    private function makeTransfer(Location $from, Location $to, string $qty = '2'): StockTransfer
    {
        $this->seedStock($from, $qty);

        return $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $from->id,
            destinationLocationId: $to->id,
            initiatedByUserId: $this->unrestricted->id,
            lines: [
                new InitiateTransferLineData(productId: $this->product->id, quantity: $qty),
            ],
        ));
    }

    // ------------------------------------------------------------------
    // Visibility (index + show)
    // ------------------------------------------------------------------

    public function test_restricted_user_sees_incoming_and_outgoing_only(): void
    {
        $incoming = $this->makeTransfer($this->warehouse, $this->shop); // -> shop (visible)
        $outgoing = $this->makeTransfer($this->shop, $this->warehouse);  // shop -> (visible)
        $foreign = $this->makeTransfer($this->warehouse, $this->depot);  // neither (hidden)

        $response = $this->actingAs($this->restricted)->getJson('/api/v1/stock-transfers');
        $response->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($incoming->id, $ids);
        $this->assertContains($outgoing->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_unrestricted_user_sees_all_transfers(): void
    {
        $incoming = $this->makeTransfer($this->warehouse, $this->shop);
        $foreign = $this->makeTransfer($this->warehouse, $this->depot);

        $response = $this->actingAs($this->unrestricted)->getJson('/api/v1/stock-transfers');
        $response->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($incoming->id, $ids);
        $this->assertContains($foreign->id, $ids);
    }

    public function test_restricted_user_can_show_transfer_touching_their_location(): void
    {
        $incoming = $this->makeTransfer($this->warehouse, $this->shop);

        $this->actingAs($this->restricted)
            ->getJson("/api/v1/stock-transfers/{$incoming->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $incoming->id);
    }

    public function test_restricted_user_cannot_show_foreign_transfer(): void
    {
        $foreign = $this->makeTransfer($this->warehouse, $this->depot);

        $this->actingAs($this->restricted)
            ->getJson("/api/v1/stock-transfers/{$foreign->id}")
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Complete (requires DESTINATION access)
    // ------------------------------------------------------------------

    public function test_restricted_user_can_complete_incoming_transfer_at_their_location(): void
    {
        $incoming = $this->makeTransfer($this->warehouse, $this->shop);

        $this->actingAs($this->restricted)
            ->postJson("/api/v1/stock-transfers/{$incoming->id}/complete")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_restricted_user_cannot_complete_transfer_between_two_other_locations(): void
    {
        $foreign = $this->makeTransfer($this->warehouse, $this->depot);

        $this->actingAs($this->restricted)
            ->postJson("/api/v1/stock-transfers/{$foreign->id}/complete")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LOCATION_ACCESS_DENIED');
    }

    public function test_restricted_user_cannot_complete_outgoing_transfer_to_foreign_destination(): void
    {
        // shop -> warehouse: destination is warehouse (not allowed) -> cannot receive.
        $outgoing = $this->makeTransfer($this->shop, $this->warehouse);

        $this->actingAs($this->restricted)
            ->postJson("/api/v1/stock-transfers/{$outgoing->id}/complete")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LOCATION_ACCESS_DENIED');
    }

    // ------------------------------------------------------------------
    // Cancel (requires SOURCE access)
    // ------------------------------------------------------------------

    public function test_restricted_user_can_cancel_transfer_from_their_location(): void
    {
        $outgoing = $this->makeTransfer($this->shop, $this->warehouse);

        $this->actingAs($this->restricted)
            ->postJson("/api/v1/stock-transfers/{$outgoing->id}/cancel", ['reason' => 'oops'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_restricted_user_cannot_cancel_transfer_from_foreign_source(): void
    {
        // warehouse -> shop: source is warehouse (not allowed) -> cannot cancel/restock.
        $incoming = $this->makeTransfer($this->warehouse, $this->shop);

        $this->actingAs($this->restricted)
            ->postJson("/api/v1/stock-transfers/{$incoming->id}/cancel", ['reason' => 'oops'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LOCATION_ACCESS_DENIED');
    }

    // ------------------------------------------------------------------
    // Initiate / store (requires SOURCE access)
    // ------------------------------------------------------------------

    public function test_restricted_user_cannot_initiate_from_location_they_lack(): void
    {
        $this->seedStock($this->warehouse, '5');

        $this->actingAs($this->restricted)
            ->postJson('/api/v1/stock-transfers', [
                'source_location_id' => $this->warehouse->id,
                'destination_location_id' => $this->shop->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '2'],
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LOCATION_ACCESS_DENIED');
    }

    public function test_restricted_user_can_initiate_from_their_location_to_any_destination(): void
    {
        // source = shop (allowed), destination = warehouse (not allowed) -> still OK,
        // because you may send TO places you cannot act at.
        $this->seedStock($this->shop, '5');

        $this->actingAs($this->restricted)
            ->postJson('/api/v1/stock-transfers', [
                'source_location_id' => $this->shop->id,
                'destination_location_id' => $this->warehouse->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '2'],
                ],
            ])
            ->assertStatus(201);
    }

    public function test_unrestricted_user_can_initiate_from_anywhere(): void
    {
        $this->seedStock($this->warehouse, '5');

        $this->actingAs($this->unrestricted)
            ->postJson('/api/v1/stock-transfers', [
                'source_location_id' => $this->warehouse->id,
                'destination_location_id' => $this->depot->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '2'],
                ],
            ])
            ->assertStatus(201);
    }
}
