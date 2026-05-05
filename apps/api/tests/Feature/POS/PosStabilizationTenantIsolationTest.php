<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Domain\Enums\SelectionType;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Contact\Domain\Contact;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Domain\Voucher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * api.pos-stabilization cluster — tenant-isolation regression coverage for the
 * 38 callsites flagged by the sweep inventory (33 mechanical scanner-detected
 * + 5 manual stubs covering the LoyaltyPOSController surface).
 *
 * Cross-references the inventory at:
 *   docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
 *   (api.pos-stabilization.001 .. api.pos-stabilization.033 +
 *    manual:api.pos-stabilization:loyalty-pos-controller-* /
 *    earning-processing-service-* / redemption-processing-service-*)
 *
 * Sub-step 3b grouping (mirrors RefundPrepaymentRequest canonical pattern):
 *
 *   Group 1 — FormRequest validators (24 callsites)
 *     Tests need pos_terminals + locations + partners + products + modifiers
 *     + modifier_groups + payment_methods + contacts + users + vouchers seeded
 *     per tenant. Cross-tenant id submission → 422; same-tenant control → no
 *     validator error on the same field.
 *     Callsites: 001 (ClaimTerminalRequest), 002 (UpdateTerminalRequest), 003 +
 *     029 + 032 (GenerateZReportRequest), 004 (RequestTerminalRequest), 005
 *     (AddOrderLineRequest), 006 + 033 (OpenShiftRequest), 007 (CreateTerminal
 *     Request), 008 + 009 (CreateOrderRequest), 010-015 (StoreReceiptRequest),
 *     016-018 (ReportController inline validate), 019 (TerminalController
 *     inline validate), 030 (StoreReturnRequest), 031 (VerifyManagerPinRequest).
 *
 *   Group 2 — Controller-tier route-anchored finds (1 callsite)
 *     api.pos-stabilization.028 — TerminalController::getOrCreateWebTerminal
 *     Location::findOrFail($payload['location_id']).
 *     Test exercises POST /api/v1/pos/terminals/web with cross-tenant
 *     location_id; verify 422/404 + same-tenant control.
 *
 *   Group 3 — Service-tier finds (8 callsites)
 *     Tests can call services directly with tenant-A CompanyContext + cross-
 *     tenant id; assert ModelNotFoundException OR no-op (depending on
 *     control flow). Callsites: 020 + 021 (OrderManagementService), 022 +
 *     023 + 024 (ReceiptCreationService), 025 + 026 (ReceiptSyncService),
 *     027 (VoucherLedgerPushService).
 *
 *   Group 4 — LoyaltyPOSController + processing services (5 manual stubs)
 *     Tests need loyalty_programs + loyalty_members + loyalty_enrollments +
 *     loyalty_rewards seeded per tenant. POST /api/v1/loyalty/pos/{action}
 *     with cross-tenant enrollment_id / reward_id → 422 (validator) or 404
 *     (pre-load); same-tenant control passes.
 *
 * Per-callsite tests are grouped by Surface (FormRequest / Controller-tier /
 * Service-tier / LoyaltyPOS) so partial fixes are obvious in the report.
 *
 * Loyalty resources are NOT seeded in the shared setUp — the Group 4 tests
 * lazily create program/member/enrollment/reward rows since those tables
 * are not exercised by Groups 1-3. This keeps the base setUp lean.
 */
final class PosStabilizationTenantIsolationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    // ---------- Resources owned by tenant A ----------
    private Location $locationA;

    private Terminal $terminalA;

    private Partner $partnerA;

    private Product $productA;

    private PaymentMethod $paymentMethodA;

    private Contact $contactA;

    private ModifierGroup $modifierGroupA;

    private Modifier $modifierA;

    private Voucher $voucherA;

    // ---------- Resources owned by tenant B ----------
    private Location $locationB;

    private Terminal $terminalB;

    private Partner $partnerB;

    private Product $productB;

    private PaymentMethod $paymentMethodB;

    private Contact $contactB;

    private ModifierGroup $modifierGroupB;

    private Modifier $modifierB;

    private Voucher $voucherB;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenants
        $this->tenantA = $this->makeTenant('pos-stab-a');
        $this->tenantB = $this->makeTenant('pos-stab-b');

        // Spatie team-scoped permissions (sanctum guard) — seed per tenant
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Companies (one per tenant)
        $this->companyA = Company::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->companyB = Company::factory()->create(['tenant_id' => $this->tenantB->id]);

        // Admin users with full POS permissions
        $this->userA = $this->makeUser($this->tenantA, 'pos-a@example.com');
        $this->userB = $this->makeUser($this->tenantB, 'pos-b@example.com');

        // Per-tenant POS-relevant resources
        [$this->locationA, $this->terminalA, $this->partnerA, $this->productA,
            $this->paymentMethodA, $this->contactA, $this->modifierGroupA,
            $this->modifierA, $this->voucherA]
            = $this->seedTenantResources($this->tenantA, $this->companyA, $this->userA);

        [$this->locationB, $this->terminalB, $this->partnerB, $this->productB,
            $this->paymentMethodB, $this->contactB, $this->modifierGroupB,
            $this->modifierB, $this->voucherB]
            = $this->seedTenantResources($this->tenantB, $this->companyB, $this->userB);
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
     * Seed POS-relevant resources for a (tenant, company, user) tuple.
     *
     * @return array{0: Location, 1: Terminal, 2: Partner, 3: Product, 4: PaymentMethod, 5: Contact, 6: ModifierGroup, 7: Modifier, 8: Voucher}
     */
    private function seedTenantResources(Tenant $tenant, Company $company, User $user): array
    {
        // Membership pin so X-Company-Id middleware passes.
        UserCompanyMembership::firstOrCreate([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ], ['role' => 'admin']);

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

        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Product '.$tenant->slug,
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'type' => ProductType::Part,
            'is_physical' => true,
            'sale_price' => '10.0000',
            'is_active' => true,
        ]);

        $paymentMethod = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => strtoupper(Str::random(4)),
            'name' => 'Cash',
            'is_active' => true,
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => false,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'first_name' => 'Test',
            'last_name' => 'Contact-'.$tenant->slug,
            'email' => 'contact-'.$tenant->slug.'@example.com',
            'is_active' => true,
        ]);

        $modifierGroup = ModifierGroup::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'MG-'.strtoupper(Str::random(4)),
            'name' => 'Modifier Group '.$tenant->slug,
            'selection_type' => SelectionType::Single,
            'min_selections' => 0,
            'max_selections' => 1,
            'is_required' => false,
            'is_active' => true,
            'display_order' => 0,
        ]);

        $modifier = Modifier::create([
            'modifier_group_id' => $modifierGroup->id,
            'code' => 'M-'.strtoupper(Str::random(4)),
            'name' => 'Modifier '.$tenant->slug,
            'price_adjustment' => '0.0000',
            'is_default' => false,
            'is_active' => true,
            'display_order' => 0,
        ]);

        $voucher = Voucher::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'issued_by_user_id' => $user->id,
            'issued_at_terminal_id' => $terminal->id,
            'redeemable_at_terminal_id' => $terminal->id,
        ]);

        return [$location, $terminal, $partner, $product, $paymentMethod, $contact, $modifierGroup, $modifier, $voucher];
    }

    /**
     * Authenticate `$user` and pin the company context header to `$company`.
     */
    private function actingAsForTenant(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }

    /**
     * Assert that the response has NO validation error for `$key`. Same pattern
     * as TreasuryTenantIsolationTest::assertNoValidationErrorFor — used by
     * same-tenant control assertions where the FormRequest rule must accept
     * the value but the downstream service may still 422 for unrelated domain
     * reasons.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertNoValidationErrorFor(TestResponse $response, string $key): void
    {
        $json = $response->json();
        if (! is_array($json) || ! isset($json['error']['errors']) || ! is_array($json['error']['errors'])) {
            $this->assertLessThan(
                500,
                $response->status(),
                "Same-tenant control: response is 5xx for valid '{$key}' (status {$response->status()}). Body: ".$response->getContent(),
            );

            return;
        }
        $this->assertArrayNotHasKey(
            $key,
            $json['error']['errors'],
            "Same-tenant control failed: validator reported error for '{$key}'. Body: ".$response->getContent(),
        );
    }

    /**
     * Smoke test — proves the multi-tenant setUp itself works before per-callsite
     * tests pile on. Asserts each per-tenant resource is owned by the correct
     * tenant + company. If this test fails, ALL per-callsite tests below fail
     * for the same reason — the seeder is broken.
     */
    // =========================================================================
    // Group 2 — Controller-tier route-anchored finds
    // =========================================================================
    //
    // api.pos-stabilization.019 — TerminalController::getOrCreateWebTerminal
    //   inline validate() with bare `exists:locations,id` (line 403).
    // api.pos-stabilization.028 — TerminalController::getOrCreateWebTerminal
    //   Location::findOrFail($locationId) (line 423).
    //
    // Both reachable from POST /api/v1/pos/terminals/web. locations has
    // company_id only (no tenant_id), so the canonical scope is
    // ScopedExists::company + Location::where('company_id', ...)->findOrFail.
    // Pre-fix: tenant-A user passing tenant-B location_id (1) passes the bare
    // exists, then (2) findOrFail returns the foreign location, and the new
    // web terminal is created with tenant_id = tenant-A but location_id =
    // tenant-B's location. Because Terminal::create is forced to the caller's
    // tenant_id+company_id, the persisted row is internally inconsistent
    // (location_id points to a foreign company's row). This is a real cross-
    // company write defect.
    // =========================================================================

    /**
     * Inventory: api.pos-stabilization.019 — bare `exists:locations,id`
     * validator at TerminalController::getOrCreateWebTerminal:403.
     */
    public function test_get_or_create_web_terminal_refuses_cross_tenant_location_via_validator(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/terminals/web', [
                'location_id' => $this->locationB->id,
            ]);
        $this->assertApiValidationErrors($response, ['location_id']);
    }

    /**
     * Same-tenant control for callsites .019/.028 — proves the route accepts
     * a legitimate location_id from the caller's company without 5xx.
     */
    public function test_get_or_create_web_terminal_accepts_same_tenant_location(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/terminals/web', [
                'location_id' => $this->locationA->id,
            ]);
        $this->assertNoValidationErrorFor($response, 'location_id');
        // Route may legitimately 200 (existing web terminal) or 201 (created)
        // — both indicate the validator accepted the value and the route
        // executed without a server error.
        $this->assertContains(
            $response->status(),
            [200, 201],
            'Same-tenant control: web-terminal route must succeed for caller\'s own location. Body: '.$response->getContent(),
        );
    }

    /**
     * Inventory: api.pos-stabilization.028 — Location::findOrFail($locationId)
     * at TerminalController::getOrCreateWebTerminal:423 must short-circuit on
     * cross-tenant ids before any Terminal write. Structural-SQL-log
     * invariant: the SELECT against `locations` MUST include a `company_id`
     * predicate (post-fix). Pre-fix: bare `where "id" = ?` only.
     *
     * Note: depending on the order of guards this assertion may pin the
     * validator's underlying exists-query OR the post-validation Location
     * lookup; either way, the fix scopes the locations SELECT by company_id.
     */
    public function test_get_or_create_web_terminal_locations_query_includes_company_predicate(): void
    {
        DB::enableQueryLog();
        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/terminals/web', [
                'location_id' => $this->locationA->id,
            ]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $relevant = collect($queries)->filter(
            fn (array $q): bool => str_contains($q['query'], 'locations')
        )->values();

        $this->assertNotEmpty(
            $relevant,
            'Expected at least one query against `locations` during web-terminal route.',
        );

        $sawCompanyPredicate = $relevant->contains(
            fn (array $q): bool => str_contains($q['query'], 'company_id')
        );
        $this->assertTrue(
            $sawCompanyPredicate,
            'Locations SELECT during web-terminal route must include company_id predicate. Queries: '.
                $relevant->pluck('query')->implode(' || '),
        );
    }

    public function test_setup_creates_per_tenant_resources_correctly(): void
    {
        // Tenant A side
        $this->assertSame($this->tenantA->id, $this->terminalA->tenant_id);
        $this->assertSame($this->companyA->id, $this->terminalA->company_id);
        $this->assertSame($this->locationA->id, $this->terminalA->location_id);
        $this->assertSame($this->companyA->id, $this->locationA->company_id);
        $this->assertSame($this->tenantA->id, $this->productA->tenant_id);
        $this->assertSame($this->companyA->id, $this->productA->company_id);
        $this->assertSame($this->tenantA->id, $this->partnerA->tenant_id);
        $this->assertSame($this->tenantA->id, $this->paymentMethodA->tenant_id);
        $this->assertSame($this->companyA->id, $this->paymentMethodA->company_id);
        $this->assertSame($this->tenantA->id, $this->contactA->tenant_id);
        $this->assertSame($this->companyA->id, $this->contactA->company_id);
        $this->assertSame($this->tenantA->id, $this->modifierGroupA->tenant_id);
        $this->assertSame($this->modifierGroupA->id, $this->modifierA->modifier_group_id);
        $this->assertSame($this->tenantA->id, $this->voucherA->tenant_id);
        $this->assertSame($this->companyA->id, $this->voucherA->company_id);

        // Tenant B side — same shape, different ownership.
        // (Reading all B-side properties here proves the seeder produces
        // distinct resources and primes per-callsite tests below to use them
        // as cross-tenant injectors.)
        $this->assertSame($this->tenantB->id, $this->terminalB->tenant_id);
        $this->assertSame($this->companyB->id, $this->terminalB->company_id);
        $this->assertSame($this->locationB->id, $this->terminalB->location_id);
        $this->assertSame($this->companyB->id, $this->locationB->company_id);
        $this->assertSame($this->tenantB->id, $this->productB->tenant_id);
        $this->assertSame($this->companyB->id, $this->productB->company_id);
        $this->assertSame($this->tenantB->id, $this->partnerB->tenant_id);
        $this->assertSame($this->tenantB->id, $this->paymentMethodB->tenant_id);
        $this->assertSame($this->companyB->id, $this->paymentMethodB->company_id);
        $this->assertSame($this->tenantB->id, $this->contactB->tenant_id);
        $this->assertSame($this->companyB->id, $this->contactB->company_id);
        $this->assertSame($this->tenantB->id, $this->modifierGroupB->tenant_id);
        $this->assertSame($this->modifierGroupB->id, $this->modifierB->modifier_group_id);
        $this->assertSame($this->tenantB->id, $this->voucherB->tenant_id);
        $this->assertSame($this->companyB->id, $this->voucherB->company_id);

        // Cross-tenant distinctness sanity check.
        $this->assertNotSame($this->terminalA->id, $this->terminalB->id);
        $this->assertNotSame($this->productA->id, $this->productB->id);
        $this->assertNotSame($this->voucherA->id, $this->voucherB->id);

        // Same-tenant control: actingAsForTenant authenticates the right user
        // and the userA exists with admin role on tenantA's permission team.
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson('/api/v1/pos/terminals');
        $this->assertNoValidationErrorFor($response, 'tenant_id');
        $this->assertLessThan(500, $response->status());
    }
}
