<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Requests\GenerateZReportRequest;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Validation tests for GenerateZReportRequest (Task 20).
 *
 * Uses an inline test route bound in setUp() so the FormRequest is resolved
 * through Laravel's normal DI chain (authorize() + rules() + withValidator()).
 *
 * The app's exception handler wraps ValidationException under error.errors,
 * so this test uses the project helper `$this->assertJsonValidationErrors(...)`
 * from `AssertsApiValidation`, which reads from `error.errors` by default.
 *
 * Covered scenarios:
 * 1. Valid payload with cash_counts passes (200).
 * 2. Valid payload without cash_counts passes (200).
 * 3. Missing payment_method_id in a cash_counts row → 422.
 * 4. Malformed actual_amount (scale > 4) → 422.
 * 5. manager_user_id present but user lacks pos.close_shift_with_variance → 422.
 * 6. Cross-tenant manager_user_id → 422.
 * 7. Typed accessor defaults.
 * 8. Typed accessor values.
 */
final class GenerateZReportRequestValidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cashier;

    private PaymentMethod $paymentMethod;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        // Register a minimal inline route that resolves GenerateZReportRequest.
        Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
            ->post('/test/generate-z', fn (GenerateZReportRequest $r) => response()->json([
                'ok' => true,
                'validated' => $r->validated(),
            ]));

        $this->tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        // Set permissions team scope, create required permissions, and grant the cashier pos.generate_z_report.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.generate_z_report', 'sanctum');
        Permission::findOrCreate('pos.close_shift_with_variance', 'sanctum');
        $this->cashier->givePermissionTo('pos.generate_z_report');

        $this->paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $location = Location::factory()->create(['company_id' => $company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        Sanctum::actingAs($this->cashier);
    }

    // -------------------------------------------------------------------------
    // 1. Valid payload with cash_counts passes
    // -------------------------------------------------------------------------

    public function test_valid_payload_with_cash_counts_passes(): void
    {
        $response = $this->postJson('/test/generate-z', [
            'terminal_id' => $this->terminal->id,
            'cash_counts' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'currency_code' => 'TND',
                    'actual_amount' => '150.2500',
                ],
            ],
            'variance_reason' => 'Counted twice, still same.',
            'blind_count_used' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
    }

    // -------------------------------------------------------------------------
    // 2. Valid payload without cash_counts passes
    // -------------------------------------------------------------------------

    public function test_valid_payload_without_cash_counts_passes(): void
    {
        $response = $this->postJson('/test/generate-z', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
    }

    // -------------------------------------------------------------------------
    // 3. Missing payment_method_id in a cash_counts row → 422
    // -------------------------------------------------------------------------

    public function test_missing_payment_method_id_in_cash_counts_row_returns_422(): void
    {
        $response = $this->postJson('/test/generate-z', [
            'cash_counts' => [
                [
                    // payment_method_id omitted intentionally
                    'currency_code' => 'TND',
                    'actual_amount' => '50.0000',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['cash_counts.0.payment_method_id']);
    }

    // -------------------------------------------------------------------------
    // 4. Malformed actual_amount (scale 5) → 422
    // -------------------------------------------------------------------------

    public function test_actual_amount_with_scale_5_returns_422(): void
    {
        $response = $this->postJson('/test/generate-z', [
            'cash_counts' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'currency_code' => 'TND',
                    'actual_amount' => '1.23456', // 5 decimal places — rejected
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['cash_counts.0.actual_amount']);
    }

    // -------------------------------------------------------------------------
    // 5. manager_user_id present but user lacks pos.close_shift_with_variance
    // -------------------------------------------------------------------------

    public function test_manager_without_variance_permission_returns_422(): void
    {
        // Create a manager in the same tenant but without the permission.
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->postJson('/test/generate-z', [
            'manager_user_id' => $manager->id,
            'manager_pin' => '1234',
        ]);

        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['manager_user_id']);
        $response->assertJsonPath(
            'error.errors.manager_user_id.0',
            'Manager does not hold pos.close_shift_with_variance.'
        );
    }

    // -------------------------------------------------------------------------
    // 6. Cross-tenant manager → 422
    // -------------------------------------------------------------------------

    public function test_cross_tenant_manager_returns_422(): void
    {
        $otherTenant = Tenant::factory()->create();
        $crossTenantManager = User::factory()->create(['tenant_id' => $otherTenant->id]);

        // Grant the permission in the other tenant's scope.
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        Permission::findOrCreate('pos.close_shift_with_variance', 'sanctum');
        $crossTenantManager->givePermissionTo('pos.close_shift_with_variance');

        // Restore scope so the SetPermissionsTeam middleware can set it correctly for the request.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $response = $this->postJson('/test/generate-z', [
            'manager_user_id' => $crossTenantManager->id,
            'manager_pin' => '5678',
        ]);

        // The exists:users,id rule is tenant-unaware, so the UUID itself passes the rule.
        // withValidator() must catch the cross-tenant violation and return 422.
        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['manager_user_id']);
        $response->assertJsonPath(
            'error.errors.manager_user_id.0',
            'Manager must be in the same tenant.'
        );
    }

    // -------------------------------------------------------------------------
    // 7. Typed accessor defaults when fields absent
    // -------------------------------------------------------------------------

    public function test_typed_accessors_return_defaults_when_fields_absent(): void
    {
        $request = GenerateZReportRequest::create('/test/generate-z', 'POST', []);

        $this->assertSame([], $request->getCashCountsInput());
        $this->assertNull($request->getVarianceReason());
        $this->assertFalse($request->getBlindCountUsed());
        $this->assertNull($request->getManagerUserId());
        $this->assertNull($request->getManagerPin());
    }

    // -------------------------------------------------------------------------
    // 8. Typed accessors return correct values when all fields present
    // -------------------------------------------------------------------------

    public function test_typed_accessors_return_correct_values(): void
    {
        $request = GenerateZReportRequest::create('/test/generate-z', 'POST', [
            'cash_counts' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'currency_code' => 'TND',
                    'actual_amount' => '200.0000',
                ],
            ],
            'variance_reason' => 'Some reason',
            'blind_count_used' => true,
            'manager_user_id' => $this->cashier->id,
            'manager_pin' => '9999',
        ]);

        $counts = $request->getCashCountsInput();
        $this->assertCount(1, $counts);
        $this->assertSame($this->paymentMethod->id, $counts[0]['payment_method_id']);
        $this->assertSame('TND', $counts[0]['currency_code']);
        $this->assertSame('200.0000', $counts[0]['actual_amount']);

        $this->assertSame('Some reason', $request->getVarianceReason());
        $this->assertTrue($request->getBlindCountUsed());
        $this->assertSame($this->cashier->id, $request->getManagerUserId());
        $this->assertSame('9999', $request->getManagerPin());
    }
}
