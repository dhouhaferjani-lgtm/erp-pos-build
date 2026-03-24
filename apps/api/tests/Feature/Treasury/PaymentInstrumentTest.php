<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentInstrumentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $checkMethod;

    private PaymentRepository $checkSafe;

    private PaymentRepository $bankAccount;

    private Partner $partner;

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
        $this->user->givePermissionTo(['instruments.view', 'instruments.create', 'instruments.transfer', 'instruments.clear']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->checkMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK',
            'name' => 'Check',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
        ]);

        $this->checkSafe = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK_SAFE',
            'name' => 'Check Safe',
            'type' => 'safe',
            'is_active' => true,
        ]);

        $this->bankAccount = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK_MAIN',
            'name' => 'Main Bank',
            'type' => 'bank_account',
            'is_active' => true,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);
    }

    public function test_can_list_payment_instruments(): void
    {
        PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-001',
            'partner_id' => $this->partner->id,
            'amount' => '1500.00',
            'received_date' => now(),
            'status' => 'received',
            'repository_id' => $this->checkSafe->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payment-instruments');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_create_check_instrument(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-instruments', [
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-123456',
            'partner_id' => $this->partner->id,
            'drawer_name' => 'ACME Corporation',
            'amount' => '2500.00',
            'received_date' => now()->toDateString(),
            'repository_id' => $this->checkSafe->id,
            'bank_name' => 'Societe Generale',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.reference', 'CHK-123456');
        $response->assertJsonPath('data.status', 'received');
        $response->assertJsonPath('data.amount', '2500.000');
    }

    public function test_can_create_pdc_with_maturity_date(): void
    {
        $pdcMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'PDC',
            'name' => 'Post-dated Check',
            'is_physical' => true,
            'has_maturity' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-instruments', [
            'payment_method_id' => $pdcMethod->id,
            'reference' => 'PDC-001',
            'partner_id' => $this->partner->id,
            'amount' => '5000.00',
            'received_date' => now()->toDateString(),
            'maturity_date' => now()->addDays(30)->toDateString(),
            'repository_id' => $this->checkSafe->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.maturity_date', now()->addDays(30)->toDateString());
    }

    public function test_can_deposit_instrument_to_bank(): void
    {
        $instrument = PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-001',
            'partner_id' => $this->partner->id,
            'amount' => '1500.00',
            'received_date' => now(),
            'status' => 'received',
            'repository_id' => $this->checkSafe->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/deposit", [
            'repository_id' => $this->bankAccount->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'deposited');
        $response->assertJsonPath('data.deposited_to_id', $this->bankAccount->id);
    }

    public function test_can_clear_deposited_instrument(): void
    {
        $instrument = PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-001',
            'partner_id' => $this->partner->id,
            'amount' => '1500.00',
            'received_date' => now(),
            'status' => 'deposited',
            'repository_id' => $this->checkSafe->id,
            'deposited_at' => now(),
            'deposited_to_id' => $this->bankAccount->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/clear");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'cleared');
    }

    public function test_can_bounce_deposited_instrument(): void
    {
        $instrument = PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-001',
            'partner_id' => $this->partner->id,
            'amount' => '1500.00',
            'received_date' => now(),
            'status' => 'deposited',
            'repository_id' => $this->checkSafe->id,
            'deposited_at' => now(),
            'deposited_to_id' => $this->bankAccount->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/bounce", [
            'reason' => 'Insufficient funds',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'bounced');
        $response->assertJsonPath('data.bounce_reason', 'Insufficient funds');
    }

    public function test_can_transfer_instrument_between_repositories(): void
    {
        $secondSafe = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SAFE_02',
            'name' => 'Secondary Safe',
            'type' => 'safe',
            'is_active' => true,
        ]);

        $instrument = PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-001',
            'partner_id' => $this->partner->id,
            'amount' => '1500.00',
            'received_date' => now(),
            'status' => 'received',
            'repository_id' => $this->checkSafe->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/transfer", [
            'to_repository_id' => $secondSafe->id,
            'reason' => 'Moving to secondary safe',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.repository_id', $secondSafe->id);
    }

    public function test_can_filter_instruments_by_status(): void
    {
        PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-001',
            'partner_id' => $this->partner->id,
            'amount' => '1000.00',
            'received_date' => now(),
            'status' => 'received',
            'repository_id' => $this->checkSafe->id,
        ]);

        PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-002',
            'partner_id' => $this->partner->id,
            'amount' => '2000.00',
            'received_date' => now(),
            'status' => 'deposited',
            'repository_id' => $this->checkSafe->id,
            'deposited_at' => now(),
            'deposited_to_id' => $this->bankAccount->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payment-instruments?status=received');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.status', 'received');
    }

    public function test_can_filter_instruments_by_partner(): void
    {
        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Corp',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-001',
            'partner_id' => $this->partner->id,
            'amount' => '1000.00',
            'received_date' => now(),
            'status' => 'received',
            'repository_id' => $this->checkSafe->id,
        ]);

        PaymentInstrument::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-002',
            'partner_id' => $otherPartner->id,
            'amount' => '2000.00',
            'received_date' => now(),
            'status' => 'received',
            'repository_id' => $this->checkSafe->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/payment-instruments?partner_id='.$this->partner->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_unauthorized_user_cannot_create_instrument(): void
    {
        $this->user->revokePermissionTo('instruments.create');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-instruments', [
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-001',
            'partner_id' => $this->partner->id,
            'amount' => '1000.00',
            'received_date' => now()->toDateString(),
            'repository_id' => $this->checkSafe->id,
        ]);

        $response->assertStatus(403);
    }
}
