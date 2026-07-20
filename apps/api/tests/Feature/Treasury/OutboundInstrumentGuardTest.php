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
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\BounceInstrumentData;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\InstrumentRemittanceService;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class OutboundInstrumentGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $method;

    private PaymentRepository $safe;

    private PaymentRepository $otherSafe;

    private PaymentRepository $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->givePermissionTo([
            'instruments.create',
            'instruments.cancel',
            'instruments.transfer',
            'instruments.clear',
            'instruments.bounce',
            'instruments.remit',
        ]);
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $this->safe = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'safe',
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
        $this->otherSafe = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'safe',
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
        $this->bank = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
            'balance' => '0.000',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
        ]);
    }

    public function test_outbound_custody_transfer_is_rejected(): void
    {
        $instrument = $this->register('outbound');

        $this->expectOutboundGuard(fn () => app(InstrumentLifecycleService::class)->custodyTransfer(
            $instrument->id,
            $this->otherSafe->id,
            $this->user->id,
        ));
    }

    public function test_outbound_deposit_is_rejected(): void
    {
        $instrument = $this->register('outbound');

        $this->expectOutboundGuard(fn () => app(InstrumentLifecycleService::class)->deposit(
            $instrument->id,
            $this->bank->id,
            $this->user->id,
        ));
    }

    public function test_outbound_clear_is_rejected(): void
    {
        $instrument = $this->register('outbound');

        $this->expectOutboundGuard(fn () => app(InstrumentLifecycleService::class)->clear(new ClearInstrumentData(
            instrumentId: $instrument->id,
            currency: 'TND',
            feeAmount: '0.000',
            feeVatAmount: '0.000',
            valueDate: null,
            userId: $this->user->id,
        )));
    }

    public function test_outbound_bounce_is_rejected(): void
    {
        $instrument = $this->register('outbound');

        $this->expectOutboundGuard(fn () => app(InstrumentLifecycleService::class)->bounce(new BounceInstrumentData(
            instrumentId: $instrument->id,
            routing: DishonorRouting::Receivable,
            currency: 'TND',
            feeAmount: '0.000',
            feeVatAmount: '0.000',
            reason: 'Not a collection instrument',
            userId: $this->user->id,
        )));
    }

    public function test_outbound_remittance_line_is_rejected(): void
    {
        $instrument = $this->register('outbound');
        $remittance = app(InstrumentRemittanceService::class)->createDraft(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            bankRepositoryId: $this->bank->id,
            type: RemittanceType::Collection,
            kind: InstrumentKind::Cheque,
            userId: $this->user->id,
        );

        $this->expectOutboundGuard(fn () => app(InstrumentRemittanceService::class)->addLine(
            $remittance->id,
            $instrument->id,
        ));
    }

    public function test_outbound_receive_remains_allowed_but_generic_cancel_is_rejected(): void
    {
        $instrument = $this->register('outbound');

        $this->assertSame('outbound', $instrument->fresh()?->direction->value);
        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/cancel", [
                'reason' => 'Supplier instrument deferred to outbound lifecycle.',
            ])
            ->assertUnprocessable();
        $this->assertSame(InstrumentStatus::Received, $instrument->fresh()?->status);
    }

    public function test_inbound_instrument_still_clears_end_to_end(): void
    {
        $instrument = $this->register('inbound');

        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/deposit", [
                'repository_id' => $this->bank->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'deposited');

        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/clear")
            ->assertOk()
            ->assertJsonPath('data.status', 'cleared');
    }

    private function expectOutboundGuard(\Closure $operation): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Outbound (supplier-direction) instruments have no collection lifecycle; deposit/clear/bounce apply to inbound instruments only.',
        );
        $operation();
    }

    private function register(string $direction): PaymentInstrument
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-instruments', [
            'payment_method_id' => $this->method->id,
            'reference' => 'REF-'.Str::upper(Str::random(8)),
            'amount' => '125.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'repository_id' => $this->safe->id,
            'direction' => $direction,
        ])->assertCreated();

        return PaymentInstrument::query()->findOrFail((string) $response->json('data.id'));
    }
}
