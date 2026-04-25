<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 2 / Task 8 — pos.tolerance.apply permission gate.
 *
 * The short-pay branch in StoreReceiptPaymentsRequest::authorize() requires
 * the new `pos.tolerance.apply` permission. Exact-tender / overpay flows
 * remain ungated.
 */
final class StoreReceiptPaymentsToleranceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private PaymentMethod $cashMethod;

    private PaymentRepository $cashRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        Country::create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => '€',
        ]);
        CountryPaymentSettings::create([
            'country_code' => 'FR',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.500',
        ]);

        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '530',
            'name' => 'Cash',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Product Revenue',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '658',
            'name' => 'Payment Tolerance Expense',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);
        $this->cashRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Main register',
            'code' => 'CASH-01',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'EUR',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        Permission::findOrCreate('pos.tolerance.apply', 'sanctum');
    }

    public function test_short_pay_denied_when_user_lacks_apply_tolerance_permission(): void
    {
        $cashier = $this->makeCashier(withApplyTolerance: false);
        $receipt = $this->seedReceipt('10.00', $cashier);

        Sanctum::actingAs($cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '9.700',
                'payment_method_id' => $this->cashMethod->id,
                'repository_id' => $this->cashRepo->id,
            ]],
        ]);

        $response->assertStatus(403);
    }

    public function test_short_pay_authorized_when_user_has_apply_tolerance_permission(): void
    {
        $cashier = $this->makeCashier(withApplyTolerance: true);
        $receipt = $this->seedReceipt('10.00', $cashier);

        Sanctum::actingAs($cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '9.700',
                'payment_method_id' => $this->cashMethod->id,
                'repository_id' => $this->cashRepo->id,
            ]],
        ]);

        // Authorization passes when permission held — the request reaches the service.
        // The actual happy-path response is asserted by Task 9's tests; here we only
        // need to prove that the gate does NOT return 403.
        $this->assertNotSame(403, $response->status());
    }

    public function test_exact_tender_does_not_require_apply_tolerance_permission(): void
    {
        $cashier = $this->makeCashier(withApplyTolerance: false);
        $receipt = $this->seedReceipt('10.00', $cashier);

        Sanctum::actingAs($cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->cashMethod->id,
                'repository_id' => $this->cashRepo->id,
            ]],
        ]);

        $this->assertNotSame(403, $response->status());
    }

    public function test_seeder_grants_apply_tolerance_to_cashier_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $role = Role::findByName('cashier', 'sanctum');
        $this->assertTrue(
            $role->hasPermissionTo('pos.tolerance.apply'),
            'Cashier role must be granted pos.tolerance.apply by RolesAndPermissionsSeeder.',
        );
    }

    private function makeCashier(bool $withApplyTolerance): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user->givePermissionTo('pos.operate_terminal');
        if ($withApplyTolerance) {
            $user->givePermissionTo('pos.tolerance.apply');
        }

        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '100.000',
        ]);

        return $user;
    }

    private function seedReceipt(string $total, User $cashier): Receipt
    {
        return Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('T001-C001-L01-POS01-2026-%08d', mt_rand(1, 99999999)),
            'chain_sequence' => 1,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'fiscal-'.uniqid()),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat-'.uniqid()),
            'payment_methods_hash' => hash('sha256', 'pay-'.uniqid()),
            'posted_at' => Carbon::now(),
            'cashier_id' => $cashier->id,
            'cashier_name' => $cashier->name,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'currency' => 'EUR',
            'is_voided' => false,
            'is_training' => false,
        ]);
    }
}
