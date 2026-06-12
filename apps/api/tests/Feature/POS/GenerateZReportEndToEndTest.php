<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * End-to-end POST /api/v1/pos/reports/z tests.
 *
 * Covers:
 *  1. No cash_counts  → 201  (legacy Cluster D path preserved)
 *  2. cash_counts balanced → 201
 *  3. Critical variance + manager present → 201
 *  4. Critical variance + no manager → 422 (manager_pin_required)
 *  5. Malformed actual_amount (5 decimal places) → 422
 *  6. Cross-company terminal → 403
 */
final class GenerateZReportEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private User $manager;

    private Terminal $terminal;

    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // These suites exercise the DEVICE/server flow on routes that are
        // web-gated to demo tenants (EnsureWebPosDemoTenant, owner decision
        // 2026-06-11); the device marker keeps them reaching the layer
        // under test.
        $this->defaultHeaders['X-Client-Type'] = 'pos-tauri';
        $this->setupTestData();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — legacy call (no cash_counts) → 201
    // ─────────────────────────────────────────────────────────────────────────

    public function test_generate_z_report_without_cash_counts_returns_201(): void
    {
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/pos/reports/z', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.terminal_id', $this->terminal->id);
        $response->assertJsonStructure(['data' => ['id', 'z_number', 'fiscal_hash', 'was_reused']]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — balanced cash_counts → 201 (variance = 0)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_generate_z_report_with_balanced_cash_counts_returns_201(): void
    {
        Sanctum::actingAs($this->cashier);

        // No receipts → expected = 0.0000. Submitting actual = 0 → balanced.
        $response = $this->postJson('/api/v1/pos/reports/z', [
            'terminal_id' => $this->terminal->id,
            'cash_counts' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'currency_code' => 'EUR',
                    'actual_amount' => '0.0000',
                ],
            ],
            'blind_count_used' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.terminal_id', $this->terminal->id);

        // counts relation is loaded; there must be one count row.
        $response->assertJsonStructure([
            'data' => [
                'id',
                'counts',
            ],
        ]);
        $this->assertCount(1, $response->json('data.counts'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — critical variance + manager_user_id provided → 201
    // ─────────────────────────────────────────────────────────────────────────

    public function test_generate_z_report_with_critical_variance_and_manager_returns_201(): void
    {
        Sanctum::actingAs($this->cashier);

        // expected = 0, actual = 50 → variance = +50 → critical (hard = 20)
        $response = $this->postJson('/api/v1/pos/reports/z', [
            'terminal_id' => $this->terminal->id,
            'cash_counts' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'currency_code' => 'EUR',
                    'actual_amount' => '50.0000',
                ],
            ],
            'variance_reason' => 'Extra float left in drawer from previous shift',
            'manager_user_id' => $this->manager->id,
            'blind_count_used' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.terminal_id', $this->terminal->id);

        // Variance summary must reflect critical severity.
        $varianceSummary = $response->json('data.variance_summary');
        $this->assertNotNull($varianceSummary);
        $this->assertSame('critical', $varianceSummary['severity']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — critical variance without manager → 422 (manager_pin_required)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_generate_z_report_with_critical_variance_and_no_manager_returns_422(): void
    {
        Sanctum::actingAs($this->cashier);

        // expected = 0, actual = 50 → critical variance
        $response = $this->postJson('/api/v1/pos/reports/z', [
            'terminal_id' => $this->terminal->id,
            'cash_counts' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'currency_code' => 'EUR',
                    'actual_amount' => '50.0000',
                ],
            ],
            'variance_reason' => 'Over count',
            // manager_user_id deliberately omitted
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'manager_pin_required');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5 — malformed actual_amount (5 decimals) → 422 from FormRequest
    // ─────────────────────────────────────────────────────────────────────────

    public function test_generate_z_report_with_malformed_actual_amount_returns_422(): void
    {
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/pos/reports/z', [
            'terminal_id' => $this->terminal->id,
            'cash_counts' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'currency_code' => 'EUR',
                    'actual_amount' => '10.12345',   // 5 decimal places — rejected by regex
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['error' => ['code', 'message', 'errors']]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 6 — cross-company terminal → 422 (validator-tier denial)
    //
    // Post api.pos-stabilization.003 fix: GenerateZReportRequest now scopes
    // terminal_id via ScopedExists::tenantAndCompany on pos_terminals, so a
    // cross-company terminal_id fails validation BEFORE the controller's
    // manual company_id check returns 403. The validator-tier denial is the
    // tighter contract; same-tenant cross-company terminals are now rejected
    // with manager_user_id-equivalent semantics on terminal_id.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_generate_z_report_with_cross_company_terminal_returns_422(): void
    {
        Sanctum::actingAs($this->cashier);

        // Create a second company + terminal in the same tenant but a different company.
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);

        $response = $this->postJson('/api/v1/pos/reports/z', [
            'terminal_id' => $otherTerminal->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('terminal_id', $errors);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Setup helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);

        // Cashier — holds pos.generate_z_report
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        // Manager — holds pos.close_shift_with_variance
        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        Permission::findOrCreate('pos.generate_z_report', 'sanctum');
        Permission::findOrCreate('pos.close_shift_with_variance', 'sanctum');

        $this->cashier->givePermissionTo('pos.generate_z_report');
        $this->manager->givePermissionTo('pos.close_shift_with_variance');

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        // Physical payment method (required so CashCountValidationService accepts it).
        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
        ]);

        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.0000',
        ]);
    }
}
