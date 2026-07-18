<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class OutboundInstrumentEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    private User $manager;

    private User $accountant;

    private Partner $partner;

    private PaymentRepository $bank;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->user('admin');
        $this->manager = $this->user('manager');
        $this->accountant = $this->user('accountant');
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->bank = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'balance' => '500.000',
        ]);
        $this->method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
    }

    public function test_admin_can_drive_all_four_outbound_actions(): void
    {
        $instrument = $this->instrument();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/clear-outbound", ['occurred_at' => '2026-07-18'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cleared');
        $this->actingAs($this->admin)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/bounce-outbound", ['reason' => 'Dishonored'])
            ->assertOk()
            ->assertJsonPath('data.status', 'bounced');
        $this->actingAs($this->admin)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/represent")
            ->assertOk()
            ->assertJsonPath('data.status', 'cleared');

        $cancelInstrument = $this->instrument();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/payment-instruments/{$cancelInstrument->id}/cancel-outbound", ['reason' => 'Void paper'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_accountant_receives_both_outbound_permissions(): void
    {
        self::assertTrue($this->accountant->can('instruments.clear-outbound'));
        self::assertTrue($this->accountant->can('instruments.cancel-outbound'));

        $instrument = $this->instrument();
        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/cancel-outbound", ['reason' => 'Accountant cancellation'])
            ->assertOk();
    }

    public function test_manager_is_forbidden_from_all_outbound_actions(): void
    {
        $instrument = $this->instrument();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/clear-outbound")
            ->assertForbidden();
        $this->actingAs($this->manager)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/bounce-outbound")
            ->assertForbidden();
        $this->actingAs($this->manager)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/represent")
            ->assertForbidden();
        $this->actingAs($this->manager)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/cancel-outbound", ['reason' => 'No authority'])
            ->assertForbidden();
    }

    public function test_all_outbound_actions_hide_another_company_instrument(): void
    {
        $otherCompany = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(ChartOfAccountsService::class)->seedForCompany($otherCompany);
        $otherPartner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);
        $otherBank = PaymentRepository::factory()->for($otherCompany)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => Account::findByPurposeOrFail($otherCompany->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'balance' => '500.000',
        ]);
        $otherMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'payment_method_id' => $otherMethod->id,
            'partner_id' => $otherPartner->id,
            'reference' => 'OTHER-COMPANY-'.Str::upper(Str::random(8)),
            'amount' => '25.000',
            'currency' => 'TND',
            'received_date' => '2026-07-18',
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
            'direction' => InstrumentDirection::Outbound,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $otherBank->id,
            'created_by' => $this->admin->id,
        ]);

        foreach ([
            ['clear-outbound', []],
            ['bounce-outbound', []],
            ['represent', []],
            ['cancel-outbound', ['reason' => 'No cross-company mutation']],
        ] as [$action, $payload]) {
            $this->actingAs($this->admin)
                ->postJson("/api/v1/payment-instruments/{$instrument->id}/{$action}", $payload)
                ->assertNotFound();
        }

        $this->assertSame(InstrumentStatus::Received, $instrument->fresh()?->status);
    }

    public function test_inbound_instrument_and_invalid_outbound_transition_return_422(): void
    {
        $inbound = $this->instrument(InstrumentDirection::Inbound);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/payment-instruments/{$inbound->id}/clear-outbound")
            ->assertUnprocessable();

        $received = $this->instrument();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/payment-instruments/{$received->id}/bounce-outbound")
            ->assertUnprocessable();
    }

    public function test_malformed_outbound_action_uuid_is_404_not_500(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/payment-instruments/not-a-uuid/clear-outbound')
            ->assertNotFound();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function instrument(InstrumentDirection $direction = InstrumentDirection::Outbound): PaymentInstrument
    {
        return PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $this->method->id,
            'partner_id' => $this->partner->id,
            'reference' => 'ENDPOINT-'.Str::upper(Str::random(8)),
            'amount' => '25.000',
            'currency' => 'TND',
            'received_date' => '2026-07-18',
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
            'direction' => $direction,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $this->bank->id,
            'created_by' => $this->admin->id,
        ]);
    }
}
