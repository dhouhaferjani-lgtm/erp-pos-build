<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\HeldOrderService;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\HeldOrder;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * api.pos-stabilization round-5 — HeldOrder tenant-isolation regression coverage.
 *
 * Targets the four manual callsites added in round-5:
 *   - api.pos-stabilization.050 — HeldOrderService::expireOrders fleet-wide UPDATE
 *   - api.pos-stabilization.051 — HeldOrderController::show controller-tier reload
 *   - api.pos-stabilization.052 — HeldOrderService::recallOrder + ::discardOrder service reads
 *   - api.pos-stabilization.053 — ExpireHeldOrdersCommand command-tier per-tenant iteration
 *
 * Cross-references inventory at:
 *   docs/superpowers/plans/tenant-isolation-sweep-inventory.yml (.050–.053)
 *
 * Construction:
 *
 *   The .051/.052 controller-tier reads currently filter by company_id only,
 *   which already 404s naive cross-tenant attempts because users can't pin a
 *   foreign company. Defense-in-depth requires BOTH predicates so that an
 *   accidental misconfiguration (a row whose tenant_id does not match its
 *   company_id, e.g. a buggy import or a forged write) does not allow read
 *   access. Tests seed artificial misconfigured rows via the QueryBuilder
 *   directly to validate that the tenant_id predicate genuinely filters.
 *
 *   The .050 service-tier UPDATE and .053 command-tier iteration are tested
 *   behaviorally: today expireOrders() takes no args and flips every tenant's
 *   held expired rows in one statement; after the fix, the signature requires
 *   tenant + company and the command iterates per-tenant.
 */
final class HeldOrderTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private Location $locationA;

    private Location $locationB;

    private Terminal $terminalA;

    private Terminal $terminalB;

    private Shift $shiftA;

    private Shift $shiftB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeTenant('held-order-iso-a');
        $this->tenantB = $this->makeTenant('held-order-iso-b');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->companyA = Company::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->companyB = Company::factory()->create(['tenant_id' => $this->tenantB->id]);

        $this->userA = $this->makeUser($this->tenantA, 'held-a@example.com');
        $this->userB = $this->makeUser($this->tenantB, 'held-b@example.com');

        UserCompanyMembership::firstOrCreate(
            ['user_id' => $this->userA->id, 'company_id' => $this->companyA->id],
            ['role' => 'admin'],
        );
        UserCompanyMembership::firstOrCreate(
            ['user_id' => $this->userB->id, 'company_id' => $this->companyB->id],
            ['role' => 'admin'],
        );

        [$this->locationA, $this->terminalA, $this->shiftA] = $this->seedTerminalAndShift($this->tenantA, $this->companyA, $this->userA);
        [$this->locationB, $this->terminalB, $this->shiftB] = $this->seedTerminalAndShift($this->tenantB, $this->companyB, $this->userB);
    }

    // =========================================================================
    // .050 — HeldOrderService::expireOrders signature + scoped UPDATE
    // =========================================================================

    /**
     * After the .050 fix, HeldOrderService::expireOrders accepts (tenantId, companyId)
     * and only flips rows matching BOTH predicates. Today the method is no-arg and
     * issues a fleet-wide UPDATE that crosses tenants — RED on the signature
     * mismatch (TypeError "Too few arguments" or unexpected fleet-wide flip).
     *
     * Inventory: api.pos-stabilization.050
     */
    public function test_expire_orders_does_not_flip_other_tenants_held_orders(): void
    {
        $heldA = $this->createHeldOrder($this->tenantA, $this->companyA, $this->terminalA, $this->shiftA, $this->userA, ['expires_at' => now()->subMinutes(10)]);
        $heldB = $this->createHeldOrder($this->tenantB, $this->companyB, $this->terminalB, $this->shiftB, $this->userB, ['expires_at' => now()->subMinutes(10)]);

        $service = $this->app->make(HeldOrderService::class);

        // Post-fix signature: (tenantId, companyId). Pre-fix: TypeError → RED.
        $count = $service->expireOrders($this->tenantA->id, $this->companyA->id);

        $this->assertSame(1, $count, 'Scoped expireOrders must flip exactly one row (the in-scope tenant+company match).');

        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldA->id,
            'status' => HeldOrderStatus::Expired->value,
        ]);
        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldB->id,
            'status' => HeldOrderStatus::Held->value,
        ]);
    }

    // =========================================================================
    // .051 — HeldOrderController::show defense-in-depth
    // =========================================================================

    /**
     * GET /api/v1/pos/held-orders/{id} must filter by BOTH tenant_id AND
     * company_id. To make the test genuinely RED, seed a row with
     * tenant_id=tenantB BUT company_id=companyA (an artificial cross-tenant
     * misconfiguration that would slip past a company_id-only filter).
     *
     * Pre-fix: company_id matches → row leaks out → 200. RED.
     * Post-fix: tenant_id mismatch → 404. GREEN.
     *
     * Inventory: api.pos-stabilization.051
     */
    public function test_show_rejects_cross_tenant_held_order_id(): void
    {
        $foreignId = $this->seedMisconfiguredHeldOrder($this->tenantB, $this->companyA, $this->terminalA, $this->shiftA, $this->userA);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson('/api/v1/pos/held-orders/'.$foreignId);

        $response->assertStatus(404);
    }

    // =========================================================================
    // .052 — HeldOrderService::recallOrder + ::discardOrder defense-in-depth
    // =========================================================================

    /**
     * POST /api/v1/pos/held-orders/{id}/recall must filter by BOTH predicates.
     * Same misconfigured-row construction as the show test.
     *
     * Inventory: api.pos-stabilization.052
     */
    public function test_recall_rejects_cross_tenant_held_order_id(): void
    {
        $foreignId = $this->seedMisconfiguredHeldOrder($this->tenantB, $this->companyA, $this->terminalA, $this->shiftA, $this->userA);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/held-orders/'.$foreignId.'/recall');

        $response->assertStatus(404);

        // Defense-in-depth: row must NOT have been flipped to Recalled.
        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $foreignId,
            'status' => HeldOrderStatus::Held->value,
        ]);
    }

    /**
     * DELETE /api/v1/pos/held-orders/{id} must filter by BOTH predicates.
     *
     * Inventory: api.pos-stabilization.052
     */
    public function test_discard_rejects_cross_tenant_held_order_id(): void
    {
        $foreignId = $this->seedMisconfiguredHeldOrder($this->tenantB, $this->companyA, $this->terminalA, $this->shiftA, $this->userA);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson('/api/v1/pos/held-orders/'.$foreignId);

        $response->assertStatus(404);

        // Defense-in-depth: row must NOT have been deleted.
        $this->assertDatabaseHas('pos_held_orders', ['id' => $foreignId]);
    }

    // =========================================================================
    // .053 — ExpireHeldOrdersCommand per-tenant iteration
    // =========================================================================

    /**
     * After the .053 fix, `pos:expire-held-orders` iterates Tenant::all() x
     * Company::where('tenant_id', $t->id) and calls the scoped expireOrders()
     * once per (tenant, company) pair. The structural SQL-log invariant is
     * that every UPDATE against pos_held_orders contains BOTH tenant_id and
     * company_id literals.
     *
     * Pre-fix: command issues one fleet-wide UPDATE with no tenant_id /
     * company_id predicate → SQL-log assertion fails → RED.
     * Post-fix: command issues N scoped UPDATEs each carrying both
     * predicates → assertion passes; both tenants' rows are flipped
     * under their own context.
     *
     * Inventory: api.pos-stabilization.053
     */
    public function test_command_iterates_per_tenant(): void
    {
        $heldA = $this->createHeldOrder($this->tenantA, $this->companyA, $this->terminalA, $this->shiftA, $this->userA, ['expires_at' => now()->subMinutes(10)]);
        $heldB = $this->createHeldOrder($this->tenantB, $this->companyB, $this->terminalB, $this->shiftB, $this->userB, ['expires_at' => now()->subMinutes(10)]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $exitCode = Artisan::call('pos:expire-held-orders');
        $output = (string) Artisan::output();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(0, $exitCode, 'Command must succeed. Output: '.$output);

        $heldOrderUpdates = array_values(array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'pos_held_orders')
                && stripos($q['query'], 'update') === 0,
        ));

        $this->assertNotEmpty(
            $heldOrderUpdates,
            'Expected at least one UPDATE against pos_held_orders during command run. Output: '.$output,
        );

        foreach ($heldOrderUpdates as $q) {
            $this->assertStringContainsString(
                'tenant_id',
                $q['query'],
                'Every pos_held_orders UPDATE must filter by tenant_id after per-tenant iteration. Query: '.$q['query'],
            );
            $this->assertStringContainsString(
                'company_id',
                $q['query'],
                'Every pos_held_orders UPDATE must filter by company_id after per-tenant iteration. Query: '.$q['query'],
            );
        }

        // Behavioral: both tenants' expired rows are flipped under their own scope.
        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldA->id,
            'status' => HeldOrderStatus::Expired->value,
        ]);
        $this->assertDatabaseHas('pos_held_orders', [
            'id' => $heldB->id,
            'status' => HeldOrderStatus::Expired->value,
        ]);

        // Output reports aggregate count.
        $this->assertStringContainsString('2', $output, 'Command output must report aggregate count of expired rows. Got: '.$output);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => "Tenant {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function makeUser(Tenant $tenant, string $email): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test '.$email,
            'email' => $email,
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array{0: Location, 1: Terminal, 2: Shift}
     */
    private function seedTerminalAndShift(Tenant $tenant, Company $company, User $user): array
    {
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Main '.$tenant->slug,
            'code' => strtoupper(Str::random(6)),
            'type' => 'shop',
            'address_country' => 'TN',
            'is_active' => true,
        ]);

        $terminal = Terminal::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'type' => TerminalType::Physical,
            'code' => 'POS-'.strtoupper(Str::random(4)),
            'name' => 'Terminal '.$tenant->slug,
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) date('Y'),
            'is_active' => true,
            'fiscal_schema_version' => 2,
        ]);

        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        return [$location, $terminal, $shift];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createHeldOrder(
        Tenant $tenant,
        Company $company,
        Terminal $terminal,
        Shift $shift,
        User $user,
        array $overrides = [],
    ): HeldOrder {
        return HeldOrder::create(array_merge([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'cashier_id' => $user->id,
            'label' => 'Iso Test',
            'cart_snapshot' => $this->makeCartSnapshot(),
            'status' => HeldOrderStatus::Held,
            'held_at' => now(),
            'expires_at' => now()->addHours(4),
        ], $overrides));
    }

    /**
     * Seed a misconfigured row whose tenant_id is foreign to the company_id.
     * Bypasses the model to write the inconsistent FK pair directly. Returns
     * the new row's id.
     */
    private function seedMisconfiguredHeldOrder(
        Tenant $foreignTenant,
        Company $localCompany,
        Terminal $localTerminal,
        Shift $localShift,
        User $localCashier,
    ): string {
        $id = (string) Str::uuid();

        DB::table('pos_held_orders')->insert([
            'id' => $id,
            'tenant_id' => $foreignTenant->id,    // foreign tenant
            'company_id' => $localCompany->id,    // local company (mismatch)
            'terminal_id' => $localTerminal->id,
            'shift_id' => $localShift->id,
            'cashier_id' => $localCashier->id,
            'label' => 'Misconfigured (cross-tenant)',
            'cart_snapshot' => json_encode($this->makeCartSnapshot()),
            'status' => HeldOrderStatus::Held->value,
            'held_at' => now(),
            'expires_at' => now()->addHours(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeCartSnapshot(): array
    {
        return [
            'lines' => [
                [
                    'product_id' => 'prod-iso',
                    'product_name' => 'Iso Item',
                    'quantity' => 1,
                    'unit_price' => '5.00',
                    'discount_amount' => '0.00',
                    'tax_rate' => '20.00',
                    'modifiers' => [],
                    'special_instructions' => null,
                ],
            ],
            'customer' => null,
            'consumption_mode' => 'SUR_PLACE',
            'notes' => null,
            'discount' => null,
        ];
    }

    private function actingAsForTenant(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
