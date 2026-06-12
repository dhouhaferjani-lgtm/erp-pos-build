<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for GET /api/v1/pos/stock-levels
 *
 * Spec §4.1 — terminal-located stock feed (full/delta + incoming, server as_of cursor).
 */
final class PosStockLevelEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $locationA;

    private Location $locationB;

    private Terminal $terminal;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');

        $this->locationA = Location::factory()->create(['company_id' => $this->company->id]);
        $this->locationB = Location::factory()->create(['company_id' => $this->company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->locationA->id,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
        ]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function actAsUser(): void
    {
        Sanctum::actingAs($this->user);
    }

    private function stockAt(Location $location, Product $product, string $qty, string $reserved = '0.0000'): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $location->id,
            'quantity' => $qty,
            'reserved' => $reserved,
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_returns_stock_for_the_terminals_location_only(): void
    {
        $this->actAsUser();

        // Stock at location A (terminal's location)
        $this->stockAt($this->locationA, $this->product, '5.0000', '1.0000');

        // Noise: stock at location B — must not appear
        $this->stockAt($this->locationB, $this->product, '99.0000', '0.0000');

        $response = $this->getJson("/api/v1/pos/stock-levels?terminal_id={$this->terminal->id}");

        $response->assertStatus(200);

        // Only location A rows
        $response->assertJsonCount(1, 'data.stock');
        $stock = $response->json('data.stock.0');
        $this->assertSame($this->product->id, $stock['product_id']);
        $this->assertNull($stock['variant_id']);
        $this->assertSame('5.0000', $stock['quantity']);
        $this->assertSame('1.0000', $stock['reserved']);
        $this->assertSame('4.0000', $stock['available']);

        // Quantities are strings (not floats/ints)
        $this->assertIsString($stock['quantity']);
        $this->assertIsString($stock['reserved']);
        $this->assertIsString($stock['available']);

        // as_of is present and ISO-8601 shaped
        $asOf = $response->json('data.as_of');
        $this->assertNotNull($asOf);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $asOf);

        // incoming is present (empty array is fine with no transfers)
        $this->assertIsArray($response->json('data.incoming'));

        // pagination meta
        $response->assertJsonStructure([
            'meta' => [
                'pagination' => ['current_page', 'last_page', 'total'],
            ],
        ]);
        $this->assertSame(1, $response->json('meta.pagination.current_page'));
    }

    public function test_terminal_id_is_required_and_company_scoped(): void
    {
        $this->actAsUser();

        // Missing terminal_id -> 422
        $response = $this->getJson('/api/v1/pos/stock-levels');
        $response->assertStatus(422);

        // Terminal from ANOTHER company in the same tenant -> 404 with TERMINAL_NOT_FOUND
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);

        $response = $this->getJson("/api/v1/pos/stock-levels?terminal_id={$otherTerminal->id}");
        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'TERMINAL_NOT_FOUND');
    }

    public function test_requires_pos_operate_terminal_permission(): void
    {
        // Arrange: a user WITHOUT the permission
        $noPermUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $noPermUser->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
        // do NOT give pos.operate_terminal to this user

        Sanctum::actingAs($noPermUser);

        $response = $this->getJson("/api/v1/pos/stock-levels?terminal_id={$this->terminal->id}");

        $response->assertStatus(403);
    }

    public function test_as_of_echoes_into_next_delta(): void
    {
        $this->actAsUser();

        // Row 1: updated in the past (2026-01-01)
        $oldRow = $this->stockAt($this->locationA, $this->product, '3.0000');
        DB::table('stock_levels')->where('id', $oldRow->id)->update(['updated_at' => '2026-01-01 00:00:00']);

        // Row 2: updated in the far future (2099-01-01) — should always show up in delta
        $futureProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
        ]);
        $futureRow = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $futureProduct->id,
            'variant_id' => null,
            'location_id' => $this->locationA->id,
            'quantity' => '7.0000',
            'reserved' => '0.0000',
        ]);
        DB::table('stock_levels')->where('id', $futureRow->id)->update(['updated_at' => '2099-01-01 00:00:00']);

        // First call: full pull — records as_of at "now"
        $first = $this->getJson("/api/v1/pos/stock-levels?terminal_id={$this->terminal->id}");
        $first->assertStatus(200);
        $first->assertJsonCount(2, 'data.stock');
        $asOf = $first->json('data.as_of');
        $this->assertNotNull($asOf);

        // Second call with updated_since = as_of (now-ish) — only the 2099 row is "after" it
        $second = $this->getJson("/api/v1/pos/stock-levels?terminal_id={$this->terminal->id}&updated_since={$asOf}");
        $second->assertStatus(200);
        $second->assertJsonCount(1, 'data.stock');
        $this->assertSame($futureProduct->id, $second->json('data.stock.0.product_id'));

        // incoming is still present in delta mode (may be empty — asserts shape)
        $this->assertIsArray($second->json('data.incoming'));
    }

    public function test_full_vs_delta_modes(): void
    {
        $this->actAsUser();

        $this->stockAt($this->locationA, $this->product, '10.0000');

        // Full mode: no updated_since -> all rows
        $full = $this->getJson("/api/v1/pos/stock-levels?terminal_id={$this->terminal->id}");
        $full->assertStatus(200);
        $full->assertJsonCount(1, 'data.stock');

        // Delta mode: updated_since far in the future -> empty stock set
        $delta = $this->getJson("/api/v1/pos/stock-levels?terminal_id={$this->terminal->id}&updated_since=2099-12-31T00:00:00Z");
        $delta->assertStatus(200);
        $this->assertSame([], $delta->json('data.stock'));

        // incoming is still present (complete set) on page 1 in delta mode
        $this->assertIsArray($delta->json('data.incoming'));
    }

    public function test_unauthenticated_is_401(): void
    {
        // No Sanctum::actingAs() call — request is unauthenticated
        $response = $this->getJson("/api/v1/pos/stock-levels?terminal_id={$this->terminal->id}");

        $response->assertStatus(401);
    }

    public function test_invalid_updated_since_is_422(): void
    {
        $this->actAsUser();

        $response = $this->getJson("/api/v1/pos/stock-levels?terminal_id={$this->terminal->id}&updated_since=not-a-date");
        $response->assertStatus(422);
    }
}
