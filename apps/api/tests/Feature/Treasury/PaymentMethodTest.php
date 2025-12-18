<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class PaymentMethodTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['treasury.view', 'treasury.manage']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_can_list_payment_methods(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
            'is_active' => true,
        ]);

        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK',
            'name' => 'Check',
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payment-methods');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_can_create_cash_payment_method(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-methods', [
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'CASH');
        $response->assertJsonPath('data.is_physical', true);

        $this->assertDatabaseHas('payment_methods', [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'is_physical' => true,
        ]);
    }

    public function test_can_create_card_payment_method_with_fees(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-methods', [
            'code' => 'CARD',
            'name' => 'Credit/Debit Card',
            'is_physical' => false,
            'has_maturity' => false,
            'requires_third_party' => true,
            'is_push' => true,
            'has_deducted_fees' => true,
            'is_restricted' => false,
            'fee_type' => 'percentage',
            'fee_percent' => '1.50',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.has_deducted_fees', true);
        $response->assertJsonPath('data.fee_type', 'percentage');
        $response->assertJsonPath('data.fee_percent', '1.50');
    }

    public function test_can_create_pdc_payment_method_with_maturity(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-methods', [
            'code' => 'PDC',
            'name' => 'Post-dated Check',
            'is_physical' => true,
            'has_maturity' => true,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.is_physical', true);
        $response->assertJsonPath('data.has_maturity', true);
    }

    public function test_can_create_restricted_payment_method(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-methods', [
            'code' => 'MEAL_VOUCHER',
            'name' => 'Ticket Restaurant',
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => true,
            'restriction_type' => 'food',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.is_restricted', true);
        $response->assertJsonPath('data.restriction_type', 'food');
    }

    public function test_cannot_create_duplicate_payment_method_code(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-methods', [
            'code' => 'CASH',
            'name' => 'Another Cash',
            'is_physical' => true,
        ]);

        $this->assertApiValidationErrors($response, ['code']);
    }

    public function test_can_update_payment_method(): void
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/payment-methods/{$method->id}", [
            'name' => 'Cash (Updated)',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Cash (Updated)');
    }

    public function test_can_show_single_payment_method(): void
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/payment-methods/{$method->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.code', 'CASH');
    }

    public function test_can_calculate_fee_for_percentage_method(): void
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Card',
            'is_physical' => false,
            'has_maturity' => false,
            'requires_third_party' => true,
            'is_push' => true,
            'has_deducted_fees' => true,
            'is_restricted' => false,
            'is_active' => true,
            'fee_type' => 'percentage',
            'fee_percent' => '2.00',
        ]);

        // Fee on 100.00 at 2% = 2.00
        $this->assertEquals('2.00', $method->calculateFee('100.00'));
        $this->assertEquals('98.00', $method->calculateNetAmount('100.00'));
    }

    public function test_can_calculate_fee_for_fixed_method(): void
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'WIRE',
            'name' => 'Wire Transfer',
            'is_physical' => false,
            'has_maturity' => false,
            'requires_third_party' => true,
            'is_push' => true,
            'has_deducted_fees' => true,
            'is_restricted' => false,
            'is_active' => true,
            'fee_type' => 'fixed',
            'fee_fixed' => '5.00',
        ]);

        // Fixed fee of 5.00
        $this->assertEquals('5.00', $method->calculateFee('100.00'));
        $this->assertEquals('95.00', $method->calculateNetAmount('100.00'));
    }

    public function test_can_calculate_fee_for_mixed_method(): void
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'MOBILE',
            'name' => 'Mobile Money',
            'is_physical' => false,
            'has_maturity' => false,
            'requires_third_party' => true,
            'is_push' => true,
            'has_deducted_fees' => true,
            'is_restricted' => false,
            'is_active' => true,
            'fee_type' => 'mixed',
            'fee_fixed' => '0.50',
            'fee_percent' => '1.00',
        ]);

        // Mixed: 0.50 + 1% of 100 = 0.50 + 1.00 = 1.50
        $this->assertEquals('1.50', $method->calculateFee('100.00'));
        $this->assertEquals('98.50', $method->calculateNetAmount('100.00'));
    }

    public function test_unauthorized_user_cannot_create_payment_method(): void
    {
        $this->user->revokePermissionTo('treasury.manage');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-methods', [
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_payment_methods_are_tenant_isolated(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
        ]);

        // Create another tenant with a user
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Permissions already seeded for guard, just set tenant context
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);

        $otherUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $otherUser->givePermissionTo(['treasury.view', 'treasury.manage']);

        UserCompanyMembership::create([
            'user_id' => $otherUser->id,
            'company_id' => $otherCompany->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($otherCompany->id);

        $response = $this->actingAs($otherUser)->getJson('/api/v1/payment-methods');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }
}
