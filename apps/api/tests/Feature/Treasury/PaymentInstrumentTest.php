<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
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
        $this->user->givePermissionTo([
            'instruments.view', 'instruments.create', 'instruments.update',
            'instruments.transfer', 'instruments.clear', 'instruments.bounce',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->checkMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK',
            'name' => 'Check',
            'is_physical' => true,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
            'is_active' => true,
        ]);

        $this->checkSafe = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK_SAFE',
            'name' => 'Check Safe',
            'type' => 'safe',
            'currency' => 'EUR',
            'balance' => '0.000',
            'is_active' => true,
        ]);

        $this->bankAccount = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK_MAIN',
            'name' => 'Main Bank',
            'type' => 'bank_account',
            'currency' => 'EUR',
            'balance' => '0.000',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
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
            'instrument_kind' => InstrumentKind::Cheque,
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
            'kind' => InstrumentKind::Cheque,
            'direction' => 'inbound',
            'origin' => 'web',
            'currency' => 'EUR',
            'repository_id' => $this->checkSafe->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/deposit", [
            'repository_id' => $this->bankAccount->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'deposited');
        $response->assertJsonPath('data.deposited_to_id', $this->bankAccount->id);
        $this->assertSame(1, AuditEvent::query()
            ->where('aggregate_id', $instrument->id)
            ->where('event_type', 'treasury.instrument.deposited')
            ->count());
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
            'status' => 'received',
            'kind' => InstrumentKind::Cheque,
            'direction' => 'inbound',
            'origin' => 'web',
            'currency' => 'EUR',
            'repository_id' => $this->checkSafe->id,
        ]);

        $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/deposit", [
            'repository_id' => $this->bankAccount->id,
        ])->assertOk();

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
            'status' => 'received',
            'kind' => InstrumentKind::Cheque,
            'direction' => 'inbound',
            'origin' => 'web',
            'currency' => 'EUR',
            'repository_id' => $this->checkSafe->id,
        ]);

        $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/deposit", [
            'repository_id' => $this->bankAccount->id,
        ])->assertOk();

        $response = $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/bounce", [
            'routing' => 'receivable',
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

    public function test_company_scope_hides_other_company_instrument_from_show_and_transition(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);
        $instrument = $this->instrument([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'payment_method_id' => $otherMethod->id,
        ]);

        $this->actingAs($this->user)->getJson("/api/v1/payment-instruments/{$instrument->id}")->assertNotFound();
        $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/transfer", [
            'to_repository_id' => $this->checkSafe->id,
        ])->assertNotFound();
    }

    public function test_patch_updates_received_details_and_appends_event(): void
    {
        $this->user->givePermissionTo('instruments.update');
        $instrument = $this->instrument([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'status' => 'received',
            'kind' => InstrumentKind::Cheque,
            'repository_id' => $this->checkSafe->id,
        ]);

        $this->actingAs($this->user)->patchJson("/api/v1/payment-instruments/{$instrument->id}", [
            'reference' => 'CHK-COMPLETED',
            'bank_name' => 'BIAT',
        ])->assertOk()->assertJsonPath('data.reference', 'CHK-COMPLETED');

        $this->assertSame(1, InstrumentEvent::query()->where('instrument_id', $instrument->id)->where('event_type', 'details_updated')->count());
    }

    public function test_patch_rejects_deposited_instrument(): void
    {
        $this->user->givePermissionTo('instruments.update');
        $instrument = $this->instrument([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'status' => 'deposited',
            'kind' => InstrumentKind::Cheque,
            'repository_id' => $this->checkSafe->id,
        ]);

        $this->actingAs($this->user)->patchJson("/api/v1/payment-instruments/{$instrument->id}", [
            'reference' => 'TOO-LATE',
        ])->assertUnprocessable();
    }

    public function test_events_endpoint_returns_the_immutable_timeline_in_order(): void
    {
        $instrument = $this->instrument();
        InstrumentEvent::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'instrument_id' => $instrument->id,
            'event_type' => 'created',
            'to_status' => 'received',
            'payload' => [],
            'occurred_at' => now()->subHour(),
        ]);
        InstrumentEvent::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'instrument_id' => $instrument->id,
            'event_type' => 'custody_transferred',
            'from_status' => 'received',
            'to_status' => 'received',
            'from_repository_id' => $this->checkSafe->id,
            'to_repository_id' => $this->bankAccount->id,
            'payload' => ['reason' => 'bank handoff'],
            'occurred_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/payment-instruments/{$instrument->id}/events")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event_type', 'created')
            ->assertJsonPath('data.1.event_type', 'custody_transferred')
            ->assertJsonPath('data.1.payload.reason', 'bank handoff');
    }

    public function test_cancel_requires_dedicated_permission_not_update_permission(): void
    {
        $instrument = $this->instrument();

        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/cancel", [
                'reason' => 'Drawer requested cancellation',
            ])
            ->assertForbidden();

        $this->user->givePermissionTo('instruments.cancel');

        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/cancel", [
                'reason' => 'Drawer requested cancellation',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('instrument_events', [
            'instrument_id' => $instrument->id,
            'event_type' => 'cancelled',
        ]);
    }

    public function test_bounce_requires_dedicated_permission_not_clear_permission(): void
    {
        $this->user->revokePermissionTo('instruments.bounce');
        $instrument = $this->instrument([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'status' => 'deposited',
            'kind' => InstrumentKind::Cheque,
            'repository_id' => $this->checkSafe->id,
        ]);

        $this->actingAs($this->user)->postJson("/api/v1/payment-instruments/{$instrument->id}/bounce", [
            'routing' => 'receivable',
        ])->assertForbidden();
    }

    public function test_index_is_paginated_and_filters_needs_details(): void
    {
        $this->instrument([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'needs_details' => true,
        ]);
        $this->instrument([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'needs_details' => false,
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/payment-instruments?needs_details=true&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.needs_details', true)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_store_defaults_to_company_currency_and_snapshots_method_kind(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/payment-instruments', [
            'payment_method_id' => $method->id,
            'reference' => 'EUR-CHEQUE',
            'amount' => '10.000',
            'received_date' => now()->toDateString(),
            'repository_id' => $this->checkSafe->id,
        ])->assertCreated()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.kind', 'cheque');
    }

    public function test_store_rejects_before_creation_when_portfolio_account_is_missing(): void
    {
        $accountId = app(InstrumentAccountResolver::class)->resolveOrFail(
            InstrumentAccountPurpose::ChecksToCollect,
            $this->company->id,
        );
        Account::query()->whereKey($accountId)->delete();

        $this->actingAs($this->user)->postJson('/api/v1/payment-instruments', [
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'NO-PORTFOLIO',
            'amount' => '10.000',
            'received_date' => now()->toDateString(),
            'repository_id' => $this->checkSafe->id,
        ])->assertUnprocessable();
        $this->assertSame(0, PaymentInstrument::query()->where('reference', 'NO-PORTFOLIO')->count());
    }

    public function test_manager_and_accountant_receive_new_instrument_permissions(): void
    {
        foreach (['manager', 'accountant'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            foreach (['instruments.update', 'instruments.bounce', 'instruments.remit', 'instruments.cancel'] as $permission) {
                $this->assertTrue($role->hasPermissionTo($permission));
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function instrument(array $overrides = []): PaymentInstrument
    {
        return PaymentInstrument::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->checkMethod->id,
            'reference' => 'CHK-'.Str::upper(Str::random(8)),
            'amount' => '10.000',
            'currency' => 'EUR',
            'received_date' => now()->toDateString(),
            'status' => 'received',
            'kind' => InstrumentKind::Cheque,
            'direction' => 'inbound',
            'origin' => 'web',
            'repository_id' => $this->checkSafe->id,
        ], $overrides));
    }
}
