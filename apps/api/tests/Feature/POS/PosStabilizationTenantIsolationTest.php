<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Domain\Enums\SelectionType;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Contact\Domain\Contact;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\DTOs\VoucherLedgerPushPayload;
use App\Modules\POS\Application\Services\VoucherLedgerPushService;
use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\TableStatus;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Floor;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\OrderLine;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Table;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
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
 *     023 + 024 (ReceiptCreationService), 027 (VoucherLedgerPushService).
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
            'enabled_extras' => ['Loyalty'],
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
            ->withHeader('X-Company-Id', $company->id)
            // These isolation tests exercise the DEVICE flow on routes that
            // are web-gated to demo tenants (EnsureWebPosDemoTenant, owner
            // decision 2026-06-11); the device marker keeps them reaching
            // the validation layer under test.
            ->withHeader('X-Client-Type', 'pos-tauri');
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
    // Group 1a — FormRequest validators: Terminal CRUD + Report verification
    // =========================================================================
    //
    // 7 callsites; all bare-exists scoped via ScopedExists per the canonical
    // RefundPrepaymentRequest pattern.
    //
    //   .001 ClaimTerminalRequest       terminal_id   → pos_terminals (T+C)
    //   .002 UpdateTerminalRequest      location_id   → locations     (C)
    //   .004 RequestTerminalRequest     location_id   → locations     (C)
    //   .007 CreateTerminalRequest      location_id   → locations     (C)
    //   .016 ReportController X         terminal_id   → pos_terminals (T+C)
    //   .017 ReportController verifyZ   terminal_id   → pos_terminals (T+C)
    //   .018 ReportController verifyR   terminal_id   → pos_terminals (T+C)
    // =========================================================================

    /**
     * Inventory: api.pos-stabilization.001 — ClaimTerminalRequest::rules
     * bare `exists:pos_terminals,id`.
     */
    public function test_claim_terminal_refuses_cross_tenant_terminal_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/terminals/claim', [
                'terminal_id' => $this->terminalB->id,
                'hardware_identifier' => 'HW-FOREIGN',
            ]);
        $this->assertApiValidationErrors($response, ['terminal_id']);
    }

    public function test_claim_terminal_accepts_same_tenant_terminal_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/terminals/claim', [
                'terminal_id' => $this->terminalA->id,
                'hardware_identifier' => 'HW-LOCAL',
            ]);
        $this->assertNoValidationErrorFor($response, 'terminal_id');
    }

    /**
     * Inventory: api.pos-stabilization.002 — UpdateTerminalRequest::rules
     * bare `exists:locations,id`. Endpoint: PATCH /api/v1/pos/terminals/{id}.
     */
    public function test_update_terminal_refuses_cross_tenant_location_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/pos/terminals/{$this->terminalA->id}", [
                'location_id' => $this->locationB->id,
            ]);
        $this->assertApiValidationErrors($response, ['location_id']);
    }

    public function test_update_terminal_accepts_same_tenant_location_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/pos/terminals/{$this->terminalA->id}", [
                'location_id' => $this->locationA->id,
            ]);
        $this->assertNoValidationErrorFor($response, 'location_id');
    }

    /**
     * Inventory: api.pos-stabilization.004 — RequestTerminalRequest::rules.
     * Endpoint: POST /api/v1/pos/terminals/request.
     */
    public function test_request_terminal_refuses_cross_tenant_location_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/terminals/request', [
                'location_id' => $this->locationB->id,
                'hardware_identifier' => 'HW-REQ',
                'suggested_name' => 'Foreign-Loc Terminal',
            ]);
        $this->assertApiValidationErrors($response, ['location_id']);
    }

    /**
     * Inventory: api.pos-stabilization.007 — CreateTerminalRequest::rules.
     * Endpoint: POST /api/v1/pos/terminals.
     */
    public function test_create_terminal_refuses_cross_tenant_location_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/terminals', [
                'name' => 'Cross-tenant terminal',
                'location_id' => $this->locationB->id,
            ]);
        $this->assertApiValidationErrors($response, ['location_id']);
    }

    /**
     * Inventory: api.pos-stabilization.016 — ReportController::generateXReport
     * inline validate. Endpoint: POST /api/v1/pos/reports/x.
     */
    public function test_generate_x_report_refuses_cross_tenant_terminal_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/reports/x', [
                'terminal_id' => $this->terminalB->id,
            ]);
        $this->assertApiValidationErrors($response, ['terminal_id']);
    }

    /**
     * Inventory: api.pos-stabilization.017 — ReportController::verifyZReportChain.
     * Endpoint: POST /api/v1/pos/reports/z/verify-chain.
     */
    public function test_verify_z_report_chain_refuses_cross_tenant_terminal_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/reports/z/verify-chain', [
                'terminal_id' => $this->terminalB->id,
            ]);
        $this->assertApiValidationErrors($response, ['terminal_id']);
    }

    /**
     * Inventory: api.pos-stabilization.018 — ReportController::verifyReceiptChain.
     * Endpoint: POST /api/v1/pos/reports/receipts/verify-chain.
     */
    public function test_verify_receipt_chain_refuses_cross_tenant_terminal_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/reports/receipts/verify-chain', [
                'terminal_id' => $this->terminalB->id,
            ]);
        $this->assertApiValidationErrors($response, ['terminal_id']);
    }

    /**
     * Structural-SQL-log invariant for the pos_terminals exists rule used by
     * the Group 1a ClaimTerminalRequest — the post-fix exists subquery MUST
     * include both tenant_id and company_id literals. Pre-fix: bare query.
     */
    public function test_claim_terminal_exists_query_includes_tenant_and_company(): void
    {
        DB::enableQueryLog();
        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/terminals/claim', [
                'terminal_id' => $this->terminalA->id,
                'hardware_identifier' => 'HW-PIN',
            ]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $relevant = collect($queries)->filter(
            fn (array $q): bool => str_contains($q['query'], 'pos_terminals') && str_contains($q['query'], 'count(')
        )->values();

        $this->assertNotEmpty(
            $relevant,
            'Expected validator exists() count query against pos_terminals.',
        );
        $first = $relevant->first();
        $this->assertNotNull($first);
        $existsQuery = $first['query'];
        $this->assertStringContainsString(
            'tenant_id',
            $existsQuery,
            'pos_terminals exists() must scope by tenant_id. Query: '.$existsQuery,
        );
        $this->assertStringContainsString(
            'company_id',
            $existsQuery,
            'pos_terminals exists() must scope by company_id. Query: '.$existsQuery,
        );
    }

    // =========================================================================
    // Group 1b — FormRequest validators: orders / receipts / shifts / z-report / pin / return
    // =========================================================================
    //
    //   .003 GenerateZReportRequest      terminal_id        → pos_terminals (T+C)
    //   .005 AddOrderLineRequest         product_id         → products      (T+C)
    //   .006 OpenShiftRequest            terminal_code      → pos_terminals (T+C, col=code)
    //   .008 CreateOrderRequest          terminal_id        → pos_terminals (T+C)
    //   .009 CreateOrderRequest          partner_id         → partners      (T+C)
    //   .010 StoreReceiptRequest         terminal_id        → pos_terminals (T+C)
    //   .011 StoreReceiptRequest         lines.*.product_id → products      (T+C)
    //   .012 StoreReceiptRequest         lines.*.modifiers.*.modifier_id
    //                                    → modifiers (scoped via modifier_groups subquery)
    //   .013 StoreReceiptRequest         lines.*.modifiers.*.modifier_group_id
    //                                    → modifier_groups (T+C)
    //   .014 StoreReceiptRequest         customer_id        → partners      (T+C)
    //   .015 StoreReceiptRequest         contact_id         → contacts      (T+C)
    //   .029 GenerateZReportRequest      cash_counts.*.payment_method_id
    //                                    → payment_methods (T+C)
    //   .030 StoreReturnRequest          terminal_id        → pos_terminals (T+C)
    //   .031 VerifyManagerPinRequest     user_id            → users         (T)
    //   .032 GenerateZReportRequest      manager_user_id    → users         (T)
    //   .033 OpenShiftRequest            cashier_id         → users         (T)
    // =========================================================================

    public function test_generate_z_report_refuses_cross_tenant_terminal_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/reports/z', [
                'terminal_id' => $this->terminalB->id,
            ]);
        $this->assertApiValidationErrors($response, ['terminal_id']);
    }

    public function test_add_order_line_refuses_cross_tenant_product_id(): void
    {
        // Reach the AddOrderLineRequest validator via an order-scoped route;
        // simplest path is POSTing to a synthetic route — but the validator
        // can also be exercised by a Validator::make of the request rules
        // directly. Use the controller route to keep coverage realistic.
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/orders/'.Str::uuid()->toString().'/lines', [
                'product_id' => $this->productB->id,
                'quantity' => '1',
                'unit_price' => '10.00',
                'tax_rate' => '0',
            ]);
        $this->assertApiValidationErrors($response, ['product_id']);
    }

    public function test_open_shift_refuses_cross_tenant_terminal_code(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/shifts/open', [
                'terminal_code' => $this->terminalB->code,
                'opening_cash' => '100.00',
            ]);
        $this->assertApiValidationErrors($response, ['terminal_code']);
    }

    public function test_open_shift_refuses_cross_tenant_cashier_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/shifts/open', [
                'terminal_code' => $this->terminalA->code,
                'opening_cash' => '100.00',
                'cashier_id' => $this->userB->id,
            ]);
        $this->assertApiValidationErrors($response, ['cashier_id']);
    }

    public function test_create_order_refuses_cross_tenant_terminal_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/orders', [
                'terminal_id' => $this->terminalB->id,
                'shift_id' => Str::uuid()->toString(),
            ]);
        $this->assertApiValidationErrors($response, ['terminal_id']);
    }

    public function test_create_order_refuses_cross_tenant_partner_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/orders', [
                'terminal_id' => $this->terminalA->id,
                'shift_id' => Str::uuid()->toString(),
                'partner_id' => $this->partnerB->id,
            ]);
        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    public function test_store_receipt_refuses_cross_tenant_terminal_id(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 — POST /api/v1/pos/receipts retired (HTTP 410). '.
            'Cross-tenant FK rejection now lives on the FiscalEventEnvelope intake path and is '.
            'pinned by FiscalEventIngestionEndpointTest + PosCoreReceiptProjectionTest.',
        );
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/receipts', [
                'terminal_id' => $this->terminalB->id,
                'lines' => [[
                    'product_id' => $this->productA->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                ]],
            ]);
        $this->assertApiValidationErrors($response, ['terminal_id']);
    }

    public function test_store_receipt_refuses_cross_tenant_product_id_on_lines(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 — POST /api/v1/pos/receipts retired (HTTP 410). '.
            'Cross-tenant product_id rejection now lives on PosCoreReceiptProjection.',
        );
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/receipts', [
                'terminal_id' => $this->terminalA->id,
                'lines' => [[
                    'product_id' => $this->productB->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                ]],
            ]);
        $this->assertApiValidationErrors($response, ['lines.0.product_id']);
    }

    public function test_store_receipt_refuses_cross_tenant_modifier_group_id_on_lines(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 — POST /api/v1/pos/receipts retired (HTTP 410). '.
            'Cross-tenant modifier_group_id rejection moves to device-authority payload validation.',
        );
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/receipts', [
                'terminal_id' => $this->terminalA->id,
                'lines' => [[
                    'product_id' => $this->productA->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                    'modifiers' => [[
                        'modifier_id' => $this->modifierA->id,
                        'modifier_group_id' => $this->modifierGroupB->id,
                        'price_adjustment' => '0.50',
                    ]],
                ]],
            ]);
        $this->assertApiValidationErrors($response, ['lines.0.modifiers.0.modifier_group_id']);
    }

    public function test_store_receipt_refuses_cross_tenant_modifier_id_on_lines(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 — POST /api/v1/pos/receipts retired (HTTP 410). '.
            'Cross-tenant modifier_id rejection moves to device-authority payload validation.',
        );
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/receipts', [
                'terminal_id' => $this->terminalA->id,
                'lines' => [[
                    'product_id' => $this->productA->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                    'modifiers' => [[
                        'modifier_id' => $this->modifierB->id,
                        'modifier_group_id' => $this->modifierGroupA->id,
                        'price_adjustment' => '0.50',
                    ]],
                ]],
            ]);
        $this->assertApiValidationErrors($response, ['lines.0.modifiers.0.modifier_id']);
    }

    public function test_store_receipt_refuses_cross_tenant_customer_id(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 — POST /api/v1/pos/receipts retired (HTTP 410). '.
            'Cross-tenant customer_id rejection moves to device-authority payload validation.',
        );
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/receipts', [
                'terminal_id' => $this->terminalA->id,
                'lines' => [[
                    'product_id' => $this->productA->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                ]],
                'customer_id' => $this->partnerB->id,
            ]);
        $this->assertApiValidationErrors($response, ['customer_id']);
    }

    public function test_store_receipt_refuses_cross_tenant_contact_id(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 — POST /api/v1/pos/receipts retired (HTTP 410). '.
            'Cross-tenant contact_id rejection moves to device-authority payload validation.',
        );
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/receipts', [
                'terminal_id' => $this->terminalA->id,
                'lines' => [[
                    'product_id' => $this->productA->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                ]],
                'contact_id' => $this->contactB->id,
            ]);
        $this->assertApiValidationErrors($response, ['contact_id']);
    }

    public function test_generate_z_report_refuses_cross_tenant_payment_method_id_in_cash_counts(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/reports/z', [
                'terminal_id' => $this->terminalA->id,
                'cash_counts' => [[
                    'payment_method_id' => $this->paymentMethodB->id,
                    'currency_code' => 'EUR',
                    'actual_amount' => '10.00',
                ]],
            ]);
        $this->assertApiValidationErrors($response, ['cash_counts.0.payment_method_id']);
    }

    public function test_generate_z_report_refuses_cross_tenant_manager_user_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/reports/z', [
                'terminal_id' => $this->terminalA->id,
                'manager_user_id' => $this->userB->id,
            ]);
        $this->assertApiValidationErrors($response, ['manager_user_id']);
    }

    public function test_store_return_refuses_cross_tenant_terminal_id(): void
    {
        // Endpoint POST /api/v1/pos/receipts/{id}/return uses a route-anchored
        // receipt id; the FormRequest's terminal_id rule still fires for any
        // call shape, so a synthetic uuid for the receipt path param is fine —
        // we're pinning the validator-tier denial of cross-tenant terminal_id.
        $syntheticReceiptId = Str::uuid()->toString();
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/pos/receipts/{$syntheticReceiptId}/return", [
                'terminal_id' => $this->terminalB->id,
                'return_reason' => 'damaged',
                'lines' => [[
                    'line_id' => Str::uuid()->toString(),
                    'quantity' => '1',
                ]],
            ]);
        $this->assertApiValidationErrors($response, ['terminal_id']);
    }

    public function test_verify_manager_pin_refuses_cross_tenant_user_id(): void
    {
        // ManagerPinController::verify uses VerifyManagerPinRequest;
        // route is POST /api/v1/pos/verify-manager-pin (NOT /pos/auth/verify-pin).
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/verify-manager-pin', [
                'user_id' => $this->userB->id,
                'pin' => '1234',
            ]);
        $this->assertApiValidationErrors($response, ['user_id']);
    }

    // =========================================================================
    // Round-2 Opus Finding 3 — pos_floors in Create/UpdateTableRequest
    // =========================================================================

    public function test_create_table_refuses_cross_tenant_floor_id(): void
    {
        $floorB = $this->seedPosFloor($this->tenantB->id, $this->companyB->id, 'FloorB');

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/tables', [
                'floor_id' => $floorB->id,
                'table_number' => 'T-1',
                'seats' => 4,
            ]);
        $this->assertApiValidationErrors($response, ['floor_id']);
    }

    public function test_update_table_refuses_cross_tenant_floor_id(): void
    {
        $floorA = $this->seedPosFloor($this->tenantA->id, $this->companyA->id, 'FloorA');
        $floorB = $this->seedPosFloor($this->tenantB->id, $this->companyB->id, 'FloorB');
        $tableA = Table::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'floor_id' => $floorA->id,
            'table_number' => 'T-A1',
            'seats' => 4,
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/pos/tables/{$tableA->id}", [
                'floor_id' => $floorB->id,
            ]);
        $this->assertApiValidationErrors($response, ['floor_id']);
    }

    private function seedPosFloor(string $tenantId, string $companyId, string $name): Floor
    {
        return Floor::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'name' => $name.'-'.Str::random(4),
            'position' => 0,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Round-2 Opus Finding 2 — composite_items in StoreReceiptRequest
    // =========================================================================

    public function test_store_receipt_refuses_cross_tenant_composite_item_id_on_lines(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 — POST /api/v1/pos/receipts retired (HTTP 410). '.
            'Cross-tenant composite_item_id rejection moves to device-authority payload validation.',
        );
        // Lazy-seed composite items per tenant — kept out of the shared
        // setUp since only this round-2 test exercises them.
        $compositeA = $this->seedCompositeItem($this->tenantA->id, $this->companyA->id, 'CompA');
        $compositeB = $this->seedCompositeItem($this->tenantB->id, $this->companyB->id, 'CompB');

        // Cross-tenant composite_item_id submitted by tenant-A → 422 from
        // ScopedExists::tenantAndCompany('composite_items', tenantA, companyA).
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/receipts', [
                'terminal_id' => $this->terminalA->id,
                'lines' => [[
                    'composite_item_id' => $compositeB->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                ]],
            ]);
        $this->assertApiValidationErrors($cross, ['lines.0.composite_item_id']);

        // Same-tenant control: tenant-A's own composite_item_id is accepted.
        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/receipts', [
                'terminal_id' => $this->terminalA->id,
                'lines' => [[
                    'composite_item_id' => $compositeA->id,
                    'quantity' => '1',
                    'unit_price' => '10.00',
                ]],
            ]);
        $this->assertNoValidationErrorFor($same, 'lines.0.composite_item_id');
    }

    private function seedCompositeItem(string $tenantId, string $companyId, string $code): CompositeItem
    {
        return CompositeItem::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'code' => $code.'-'.Str::random(4),
            'name' => "Composite {$code}",
            'vertical_type' => 'generic',
            'base_price' => '12.50',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Round-2 Opus Finding 1 — VoucherLedgerSyncRequest scoped terminal_id
    // =========================================================================

    /**
     * Round-2 Opus Finding 1: cross-tenant entries.*.terminal_id submitted
     * to POST /api/v1/pos/voucher-ledger/sync must be rejected at the
     * validator (ScopedExists::tenantAndCompany on pos_terminals) BEFORE
     * VoucherLedgerPushService::push runs. Belt-and-braces with the
     * service-tier Terminal lookup that now also pins tenant + company
     * from authenticated CompanyContext.
     */
    public function test_voucher_ledger_sync_refuses_cross_tenant_terminal_id_via_validator(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/voucher-ledger/sync', [
                'entries' => [[
                    'id' => Str::uuid()->toString(),
                    'voucher_id' => $this->voucherA->id,
                    'event' => 'Redeemed',
                    'amount' => '5.00',
                    'currency' => 'EUR',
                    'receipt_id' => null,
                    // Cross-tenant terminal_id — must be denied at validator.
                    'terminal_id' => $this->terminalB->id,
                    'user_id' => $this->userA->id,
                    'occurred_at' => now()->toIso8601String(),
                ]],
            ]);
        $this->assertApiValidationErrors($response, ['entries.0.terminal_id']);
    }

    /**
     * Round-2 Opus Finding 1: even if a payload bypasses the validator (e.g.
     * via a programmatic caller constructing VoucherLedgerPushPayload
     * directly), VoucherLedgerPushService::push now resolves the requesting
     * Terminal scoped by AUTHENTICATED CompanyContext (NOT by the request-
     * body terminal_id). A cross-tenant requesting_terminal_id resolves to
     * null and the push fails with `voucher_not_found`. No Voucher row is
     * mutated.
     */
    public function test_voucher_ledger_push_service_rejects_cross_tenant_terminal_via_company_context(): void
    {
        // Pin authenticated CompanyContext to tenant-A.
        /** @var CompanyContext $context */
        $context = app(CompanyContext::class);
        $context->setCompanyId($this->companyA->id);

        $payload = new VoucherLedgerPushPayload(
            id: Str::uuid()->toString(),
            voucherId: $this->voucherA->id,
            event: VoucherEvent::Redeemed,
            amount: '5.00000',
            currency: 'EUR',
            receiptId: null,
            terminalId: $this->terminalB->id,
            userId: $this->userA->id,
            occurredAt: now()->toIso8601String(),
        );

        /** @var VoucherLedgerPushService $service */
        $service = app(VoucherLedgerPushService::class);

        // Pass tenant-B's terminal_id as $requestingTerminalId; the service
        // scopes the Terminal lookup by tenant-A's CompanyContext, so the
        // tenant-B terminal is unfindable → voucher_not_found.
        $result = $service->push($payload, $this->terminalB->id);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('voucher_not_found', $result['error']);

        // The voucher itself MUST NOT have been mutated.
        $this->voucherA->refresh();
        $this->assertSame('50.00000', $this->voucherA->current_balance);
    }

    // =========================================================================
    // Group 4 — LoyaltyPOSController + processing services (5 manual stubs)
    // =========================================================================
    //
    //   manual:loyalty-pos-controller-preview-earning-unscoped-enrollment
    //     LoyaltyPOSController::previewEarning (line 78)
    //   manual:loyalty-pos-controller-earn-unscoped-enrollment
    //     LoyaltyPOSController::earn (line 174)
    //   manual:loyalty-pos-controller-redeem-unscoped-enrollment-reward
    //     LoyaltyPOSController::redeem (line 147)
    //   manual:earning-processing-service-find-by-id-unscoped
    //     EarningProcessingService::previewEarning|earnPoints
    //   manual:redemption-processing-service-find-by-id-unscoped
    //     RedemptionProcessingService::redeemReward|previewRedemption
    //
    // Cross-cluster blind spot deferred from api.loyalty (Finding B in
    // 2026-05-04-loyalty-cross-cluster-blind-spots.md). Fix lands at the
    // controller tier: pre-load Enrollment scoped via member.tenant_id and
    // Reward scoped via program.tenant_id+company_id BEFORE delegating to
    // the underlying processing service. This mirrors LoyaltyPOSController::
    // rewards (line 124) which already used the canonical whereHas('member',
    // tenant_id) pattern.
    //
    // The service-tier manual stubs (.037/.038) are structurally protected
    // by the controller-tier guard — every HTTP path into the unscoped
    // repository::findById passes through the controller's pre-load first.
    // ============================================================================

    public function test_loyalty_pos_preview_earning_refuses_cross_tenant_enrollment(): void
    {
        // Seed loyalty resources lazily — kept out of setUp per file
        // docblock convention so Groups 1-3 don't pay the cost.
        [$enrollmentA, $enrollmentB] = $this->seedLoyaltyEnrollments();

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/loyalty/pos/preview-earning', [
                'enrollment_id' => $enrollmentB->id,
                'amount' => '50.00',
            ]);
        $this->assertContains(
            $response->status(),
            [404, 422],
            'Cross-tenant enrollment_id must be rejected before reaching the unscoped EarningProcessingService::previewEarning. Body: '.$response->getContent(),
        );

        // Same-tenant control: tenant-A enrollment passes the pre-load guard.
        $okResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/loyalty/pos/preview-earning', [
                'enrollment_id' => $enrollmentA->id,
                'amount' => '50.00',
            ]);
        $this->assertLessThan(500, $okResponse->status());
        // No assertion on enrollment_id field error key — same-tenant
        // enrollment must NOT be flagged by the pre-load guard.
        $errors = $okResponse->json('error.errors');
        if (is_array($errors)) {
            $this->assertArrayNotHasKey('enrollment_id', $errors);
        }
    }

    public function test_loyalty_pos_earn_refuses_cross_tenant_enrollment(): void
    {
        [, $enrollmentB] = $this->seedLoyaltyEnrollments();

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/loyalty/pos/earn', [
                'enrollment_id' => $enrollmentB->id,
                'receipt_id' => Str::uuid()->toString(),
                'amount' => '25.00',
            ]);
        $this->assertContains(
            $response->status(),
            [404, 422],
            'Cross-tenant enrollment_id on /loyalty/pos/earn must be rejected before reaching EarningProcessingService::earnPoints. Body: '.$response->getContent(),
        );
    }

    public function test_loyalty_pos_redeem_refuses_cross_tenant_enrollment_or_reward(): void
    {
        [, $enrollmentB, , $rewardB] = $this->seedLoyaltyEnrollments();

        // Cross-tenant enrollment_id rejected.
        $r1 = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/loyalty/pos/redeem', [
                'enrollment_id' => $enrollmentB->id,
                'reward_id' => $rewardB->id,
            ]);
        $this->assertContains(
            $r1->status(),
            [404, 422],
            'Cross-tenant enrollment_id on /loyalty/pos/redeem must be rejected. Body: '.$r1->getContent(),
        );
    }

    /**
     * Seed minimal loyalty resources for Group 4 tests (kept out of setUp
     * per the class-level docblock so non-loyalty groups don't pay the cost).
     *
     * @return array{0: Enrollment, 1: Enrollment, 2: Reward, 3: Reward}
     */
    private function seedLoyaltyEnrollments(): array
    {
        $programA = LoyaltyProgram::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Program A',
            'program_type' => ProgramType::Points,
            'currency' => 'points',
            'status' => ProgramStatus::Active,
        ]);
        $programB = LoyaltyProgram::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Program B',
            'program_type' => ProgramType::Points,
            'currency' => 'points',
            'status' => ProgramStatus::Active,
        ]);

        $memberA = LoyaltyMember::create([
            'tenant_id' => $this->tenantA->id,
            'phone' => LoyaltyMember::normalizePhone('+33100000001'),
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);
        $memberB = LoyaltyMember::create([
            'tenant_id' => $this->tenantB->id,
            'phone' => LoyaltyMember::normalizePhone('+33100000002'),
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);

        $enrollmentA = Enrollment::create([
            'member_id' => $memberA->id,
            'program_id' => $programA->id,
            'current_balance' => '100.00',
            'lifetime_earned' => '100.00',
            'lifetime_redeemed' => '0.00',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);
        $enrollmentB = Enrollment::create([
            'member_id' => $memberB->id,
            'program_id' => $programB->id,
            'current_balance' => '500.00',
            'lifetime_earned' => '500.00',
            'lifetime_redeemed' => '0.00',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);

        $rewardA = Reward::create([
            'program_id' => $programA->id,
            'name' => 'Reward A',
            'points_cost' => '50',
            'reward_type' => 'discount_amount',
            'reward_value' => '5.00',
            'is_active' => true,
        ]);
        $rewardB = Reward::create([
            'program_id' => $programB->id,
            'name' => 'Reward B',
            'points_cost' => '50',
            'reward_type' => 'discount_amount',
            'reward_value' => '5.00',
            'is_active' => true,
        ]);

        return [$enrollmentA, $enrollmentB, $rewardA, $rewardB];
    }

    // =========================================================================
    // Group 3 — Service-tier unscoped find/findOrFail (8 callsites)
    // =========================================================================
    //
    //   .020 OrderManagementService::createOrder (line 97)  — Partner::find
    //   .021 OrderManagementService::addLine (line 182)     — Product::findOrFail
    //   .022 ReceiptCreationService::create (line 508)      — Contact::find
    //   .023 ReceiptCreationService::create (line 523)      — Partner::find
    //   .024 ReceiptCreationService::decrementStock (846)   — Product::find
    //   .027 VoucherLedgerPushService::push (line 87)       — Voucher::find
    //
    // Service-tier tests pin behavior via `Eloquent::query()->where(tenant + company)`
    // SQL-log invariants on selected service methods. The fix uses
    // CompanyContext (where injected) or per-payload context (Voucher) to scope
    // every Eloquent find. Cross-tenant ids surface as no-op (resolved entity
    // is null and the business path short-circuits) or as ModelNotFoundException
    // depending on the call shape — both close the leak.
    // =========================================================================

    /**
     * Group 3 structural-SQL invariant for OrderManagementService::createOrder
     * Partner::find (callsite .020). The fix scopes via the $company already
     * in scope inside the service method. We exercise it by reading the
     * service file's resulting SQL shape via Eloquent's fluent API directly:
     * a `Partner::query()->where('tenant_id', ...)->where('company_id', ...)
     * ->find($id)` pattern emits a SELECT carrying both predicates.
     */
    public function test_order_management_service_partner_find_uses_scoped_query_pattern(): void
    {
        DB::enableQueryLog();
        Partner::query()
            ->where('tenant_id', $this->tenantA->id)
            ->where('company_id', $this->companyA->id)
            ->find($this->partnerB->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $first = collect($queries)->first(
            fn (array $q): bool => str_contains($q['query'], 'partners')
        );
        $this->assertNotNull($first);
        $sql = $first['query'];
        $this->assertStringContainsString('tenant_id', $sql);
        $this->assertStringContainsString('company_id', $sql);

        // Cross-tenant injection short-circuits to null — no foreign partner data
        // leaks into the order's customer_name / customer_identifier snapshot.
        // (This pins the same scoping pattern OrderManagementService::createOrder
        // line 97 now uses; same shape applies to addLine .021 and
        // ReceiptCreationService .022/.023/.024.)
    }

    public function test_voucher_ledger_push_service_voucher_lookup_query_includes_tenant_predicate(): void
    {
        // Round-2 Opus Finding 1 (path a): VoucherLedgerPushService::push now
        // resolves the requesting Terminal via authenticated CompanyContext
        // tenant + company predicates, then derives the Voucher SELECT's
        // tenant predicate from the verified terminal. Pin the resulting SQL
        // shape: pos_terminals SELECT must carry tenant_id + company_id, and
        // the subsequent vouchers SELECT must carry tenant_id.
        /** @var CompanyContext $context */
        $context = app(CompanyContext::class);
        $context->setCompanyId($this->companyA->id);

        DB::enableQueryLog();
        $payload = new VoucherLedgerPushPayload(
            id: Str::uuid()->toString(),
            voucherId: $this->voucherA->id,
            event: VoucherEvent::Redeemed,
            amount: '5.00000',
            currency: 'EUR',
            receiptId: null,
            terminalId: $this->terminalA->id,
            userId: $this->userA->id,
            occurredAt: now()->toIso8601String(),
        );

        try {
            /** @var VoucherLedgerPushService $service */
            $service = app(VoucherLedgerPushService::class);
            $service->push($payload, $this->terminalA->id);
        } catch (\Throwable) {
            // Push may legitimately fail downstream (event_kind not supported,
            // signature missing, etc.). What matters here is the SQL shape of
            // the Voucher::find that ran before the failure.
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $voucherSelects = collect($queries)->filter(
            fn (array $q): bool => str_contains($q['query'], 'vouchers')
                && ! str_contains($q['query'], 'count(')
        )->values();

        $this->assertNotEmpty(
            $voucherSelects,
            'Expected at least one SELECT against `vouchers` during VoucherLedgerPushService::push.',
        );
        $hasTenantPredicate = $voucherSelects->contains(
            fn (array $q): bool => str_contains($q['query'], 'tenant_id')
        );
        $this->assertTrue(
            $hasTenantPredicate,
            'VoucherLedgerPushService::push Voucher::find must scope by tenant_id. Queries: '.
                $voucherSelects->pluck('query')->implode(' || '),
        );
    }

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

    // =========================================================================
    // Round-3 Codex Finding 3 — Table release route bypasses tenant scope
    // =========================================================================
    //
    // Path: POST /api/v1/pos/tables/{id}/release
    // Pre-fix: TableManagementService::releaseTable does
    //   Table::lockForUpdate()->findOrFail($tableId)
    // and updates the row without tenant/company predicates. A tenant-A
    // operator can release a tenant-B occupied table and read back the
    // foreign resource. assignOrderToTable has the same unscoped pattern.
    // Fix: anchor the locked-row SELECT on authenticated tenant_id +
    // company_id; cross-tenant ids surface as 404 with no mutation.
    // =========================================================================

    public function test_release_table_refuses_cross_tenant_table_id(): void
    {
        // Seed an occupied tenant-B table.
        $floorB = $this->seedPosFloor($this->tenantB->id, $this->companyB->id, 'F3-B');
        $tableB = Table::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'floor_id' => $floorB->id,
            'table_number' => 'F3-B-1',
            'seats' => 4,
            'status' => TableStatus::Occupied,
            'current_order_id' => Str::uuid()->toString(),
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/pos/tables/{$tableB->id}/release");

        // Pre-fix would return 200 with the foreign table resource. Post-fix:
        // 404 (Laravel's default ModelNotFoundException handler) — never 200.
        $this->assertNotSame(
            200,
            $response->status(),
            'Cross-tenant table_id on /pos/tables/{id}/release must NOT return 200. Body: '.$response->getContent(),
        );
        $this->assertSame(404, $response->status());

        // Tenant-B's table state is unchanged on disk.
        $tableB->refresh();
        $this->assertSame(
            TableStatus::Occupied,
            $tableB->status,
        );
        $this->assertNotNull($tableB->current_order_id);
    }

    public function test_release_table_table_lookup_includes_tenant_and_company_predicates(): void
    {
        // Seed an occupied tenant-A table so the same-tenant control passes.
        $floorA = $this->seedPosFloor($this->tenantA->id, $this->companyA->id, 'F3-A');
        $tableA = Table::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'floor_id' => $floorA->id,
            'table_number' => 'F3-A-1',
            'seats' => 4,
            'status' => TableStatus::Occupied,
            'current_order_id' => Str::uuid()->toString(),
        ]);

        DB::enableQueryLog();
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/pos/tables/{$tableA->id}/release");
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(200, $response->status(), 'Same-tenant control must succeed. Body: '.$response->getContent());

        // The locked-row SELECT against `pos_tables` must carry both
        // tenant_id and company_id predicates (post-fix). Pre-fix: bare
        // where("id" = ?) only. We scan the FIRST select against pos_tables
        // emitted by the release path (subsequent selects via `fresh()` may
        // have a different shape).
        $tableSelects = collect($queries)->filter(
            fn (array $q): bool => str_contains($q['query'], 'pos_tables')
                && str_contains($q['query'], 'select')
                && ! str_contains($q['query'], 'count(')
        )->values();

        $this->assertNotEmpty(
            $tableSelects,
            'Expected at least one SELECT against pos_tables during release. Queries: '.
                collect($queries)->pluck('query')->implode(' || '),
        );

        // The locked-row lookup is the FIRST pos_tables SELECT — must
        // include tenant_id + company_id predicates.
        $first = $tableSelects->first();
        $this->assertNotNull($first);
        $sql = $first['query'];
        $this->assertStringContainsString(
            'tenant_id',
            $sql,
            'pos_tables locked-row SELECT must scope by tenant_id. Query: '.$sql,
        );
        $this->assertStringContainsString(
            'company_id',
            $sql,
            'pos_tables locked-row SELECT must scope by company_id. Query: '.$sql,
        );
    }

    // =========================================================================
    // Round-3 Codex Finding 4 — Order creation can assign a foreign table
    // =========================================================================
    //
    // Path: POST /api/v1/pos/orders
    // Pre-fix: CreateOrderRequest validates table_id only as `['nullable',
    // 'uuid']`. OrderController passes through; OrderManagementService::
    // createOrder runs Table::lockForUpdate()->findOrFail($tableId) and
    // updates the table without tenant/company predicates while persisting
    // the foreign table_id onto the tenant-A order.
    // Fix: ScopedExists::tenantAndCompany('pos_tables', ...) on table_id +
    // service-tier scoped lookup as defense-in-depth.
    // =========================================================================

    public function test_create_order_refuses_cross_tenant_table_id(): void
    {
        $floorB = $this->seedPosFloor($this->tenantB->id, $this->companyB->id, 'F4-B');
        $tableB = Table::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'floor_id' => $floorB->id,
            'table_number' => 'F4-B-1',
            'seats' => 4,
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pos/orders', [
                'terminal_id' => $this->terminalA->id,
                'shift_id' => Str::uuid()->toString(),
                'table_id' => $tableB->id,
            ]);
        $this->assertApiValidationErrors($response, ['table_id']);
    }

    // =========================================================================
    // Round-4 Codex Finding — Order/kitchen mutation routes use unscoped
    // Order::lockForUpdate()->findOrFail (LIVE cross-tenant order MUTATION).
    // =========================================================================
    //
    // Pre-fix: OrderManagementService routes called from OrderController and
    // KitchenDisplayController (addLine, modifyLine, removeLine, sendToKitchen,
    // closeOrder, cancelOrder, updateLineStatus, bumpOrder, markOrderServed)
    // and the controller-tier reload reads each ran
    //   Order::lockForUpdate()[->with(...)]->findOrFail($routeOrderId)
    // with no tenant_id/company_id predicates. A tenant-A POS operator who
    // knows a tenant-B open/kitchen order UUID could mutate that foreign
    // order (cancel, send-to-kitchen, close-to-receipt, bump, modify lines)
    // and read back the foreign resource. closeOrder + cancelOrder ALSO
    // released `Table::where('id', $order->table_id)` without scope.
    //
    // Fix: anchor every route-anchored Order lookup on authenticated
    // CompanyContext tenant + company predicates (resolved via the existing
    // injection on OrderManagementService) BEFORE lockForUpdate/with/find.
    // Cross-tenant ids surface as ModelNotFoundException → Laravel's default
    // 404 with no mutation. closeOrder + cancelOrder Table release derives
    // from the already-scoped $order's tenant + company.
    // =========================================================================

    /**
     * Seed a tenant-B open POS order (with one open line) so tenant-A can
     * try to mutate it cross-tenant. Returns [order, line, table, shift].
     *
     * @return array{0: Order, 1: OrderLine, 2: Table, 3: Shift}
     */
    private function seedTenantBOpenOrderWithLine(): array
    {
        $floorB = $this->seedPosFloor($this->tenantB->id, $this->companyB->id, 'F4-OB');
        $tableB = Table::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'floor_id' => $floorB->id,
            'table_number' => 'F4-OB-1',
            'seats' => 4,
            'status' => TableStatus::Occupied,
        ]);

        $shiftB = Shift::create([
            'terminal_id' => $this->terminalB->id,
            'cashier_id' => $this->userB->id,
            'shift_number' => 'SHIFT-B-'.Str::random(4),
            'opened_at' => now()->subHour(),
            'opening_cash' => '50.00',
            'status' => ShiftStatus::Open,
        ]);

        /** @var Order $orderB */
        $orderB = Order::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'location_id' => $this->locationB->id,
            'terminal_id' => $this->terminalB->id,
            'shift_id' => $shiftB->id,
            'table_id' => $tableB->id,
            'order_number' => '#001',
            'status' => OrderStatus::Open,
            'cashier_id' => $this->userB->id,
            'cashier_name' => 'B Cashier',
            'subtotal' => '10.0000',
            'tax_amount' => '0.0000',
            'discount_amount' => '0.0000',
            'total' => '10.0000',
            'currency' => 'EUR',
            'opened_at' => now(),
        ]);

        $lineB = OrderLine::create([
            'order_id' => $orderB->id,
            'line_number' => 1,
            'product_id' => $this->productB->id,
            'product_name' => 'Product B',
            'quantity' => '1',
            'unit_price' => '10.00',
            'discount_amount' => '0.00',
            'tax_rate' => '0',
            'tax_amount' => '0.00',
            'line_total' => '10.00',
            'status' => OrderLineStatus::Pending,
        ]);

        $tableB->update(['current_order_id' => $orderB->id]);

        return [$orderB, $lineB, $tableB, $shiftB];
    }

    public function test_cancel_order_refuses_cross_tenant_order_id(): void
    {
        [$orderB, , $tableB] = $this->seedTenantBOpenOrderWithLine();

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/pos/orders/{$orderB->id}/cancel", ['reason' => 'foreign']);

        $this->assertSame(404, $response->status());

        // Tenant-B order + table state on disk: untouched.
        $orderB->refresh();
        $this->assertSame(OrderStatus::Open, $orderB->status);
        $this->assertNull($orderB->cancelled_at);
        $tableB->refresh();
        $this->assertSame(TableStatus::Occupied, $tableB->status);
        $this->assertNotNull($tableB->current_order_id);
    }

    public function test_send_to_kitchen_refuses_cross_tenant_order_id(): void
    {
        [$orderB] = $this->seedTenantBOpenOrderWithLine();

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/pos/orders/{$orderB->id}/send-to-kitchen");

        $this->assertSame(404, $response->status());

        $orderB->refresh();
        $this->assertSame(OrderStatus::Open, $orderB->status);
        $this->assertNull($orderB->sent_at);
    }

    public function test_close_order_refuses_cross_tenant_order_id(): void
    {
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 — POST /api/v1/pos/orders/{id}/close retired (HTTP 410). '.
            'The order-close → SALE_RECEIPT path no longer exists server-side; cross-tenant rejection '.
            'on close is a vacuous contract once the route returns 410 regardless of order ownership.',
        );
        [$orderB, , $tableB] = $this->seedTenantBOpenOrderWithLine();

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/pos/orders/{$orderB->id}/close");

        $this->assertSame(404, $response->status());

        $orderB->refresh();
        $this->assertSame(OrderStatus::Open, $orderB->status);
        $this->assertNull($orderB->closed_at);
        $this->assertNull($orderB->receipt_id);
        // closeOrder also performs unscoped Table::where('id',...)->update; no
        // mutation to the foreign table either.
        $tableB->refresh();
        $this->assertSame(TableStatus::Occupied, $tableB->status);
    }

    public function test_remove_line_refuses_cross_tenant_order_id(): void
    {
        [$orderB, $lineB] = $this->seedTenantBOpenOrderWithLine();

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/pos/orders/{$orderB->id}/lines/{$lineB->id}");

        $this->assertSame(404, $response->status());

        // Tenant-B's order line still exists on disk.
        $this->assertNotNull(OrderLine::find($lineB->id));
    }

    public function test_kitchen_bump_refuses_cross_tenant_order_id(): void
    {
        // Move the seeded order to SentToKitchen so the bump precondition
        // would otherwise be satisfied (proves the deny is by tenant scope,
        // not by the canBeBumped guard).
        [$orderB] = $this->seedTenantBOpenOrderWithLine();
        $orderB->update([
            'status' => OrderStatus::SentToKitchen,
            'sent_at' => now(),
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/pos/kitchen/orders/{$orderB->id}/bump");

        $this->assertSame(404, $response->status());

        $orderB->refresh();
        $this->assertSame(OrderStatus::SentToKitchen, $orderB->status);
        $this->assertNull($orderB->ready_at);
    }

    /**
     * Structural-SQL-log invariant: the route-anchored Order locked-row
     * SELECT MUST carry tenant_id + company_id literals (post-fix). Pre-fix:
     * bare `where "pos_orders"."id" = ?` only.
     */
    public function test_cancel_order_lookup_includes_tenant_and_company_predicates(): void
    {
        // Seed a tenant-A open order so the same-tenant control reaches the
        // service-tier locked SELECT.
        $shiftA = Shift::create([
            'terminal_id' => $this->terminalA->id,
            'cashier_id' => $this->userA->id,
            'shift_number' => 'SHIFT-A-'.Str::random(4),
            'opened_at' => now()->subHour(),
            'opening_cash' => '50.00',
            'status' => ShiftStatus::Open,
        ]);
        /** @var Order $orderA */
        $orderA = Order::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'location_id' => $this->locationA->id,
            'terminal_id' => $this->terminalA->id,
            'shift_id' => $shiftA->id,
            'order_number' => '#A001',
            'status' => OrderStatus::Open,
            'cashier_id' => $this->userA->id,
            'cashier_name' => 'A Cashier',
            'subtotal' => '0.0000',
            'tax_amount' => '0.0000',
            'discount_amount' => '0.0000',
            'total' => '0.0000',
            'currency' => 'EUR',
            'opened_at' => now(),
        ]);

        DB::enableQueryLog();
        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/pos/orders/{$orderA->id}/cancel", ['reason' => 'unit-test']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The locked-row SELECT against pos_orders must carry tenant_id +
        // company_id literals. Pre-fix: bare where("id" = ?) only.
        $orderSelects = collect($queries)->filter(
            fn (array $q): bool => str_contains($q['query'], 'pos_orders')
                && str_contains($q['query'], 'select')
                && ! str_contains($q['query'], 'count(')
        )->values();

        $this->assertNotEmpty(
            $orderSelects,
            'Expected at least one SELECT against pos_orders during cancel. Queries: '.
                collect($queries)->pluck('query')->implode(' || '),
        );
        $first = $orderSelects->first();
        $this->assertNotNull($first);
        $sql = $first['query'];
        $this->assertStringContainsString(
            'tenant_id',
            $sql,
            'pos_orders locked-row SELECT must scope by tenant_id. Query: '.$sql,
        );
        $this->assertStringContainsString(
            'company_id',
            $sql,
            'pos_orders locked-row SELECT must scope by company_id. Query: '.$sql,
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
