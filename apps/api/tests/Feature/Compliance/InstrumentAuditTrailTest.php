<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Compliance\Listeners\DomainEventSubscriber;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Events\InstrumentBounced;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Events\InstrumentDeposited;
use App\Modules\Treasury\Domain\Events\InstrumentReceived;
use App\Modules\Treasury\Domain\Events\InstrumentTransferred;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstrumentAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_instrument_subscriber_registers_all_five_domain_events(): void
    {
        $subscriptions = app(DomainEventSubscriber::class)->subscribe(app(Dispatcher::class));

        foreach ([
            InstrumentReceived::class,
            InstrumentDeposited::class,
            InstrumentCleared::class,
            InstrumentBounced::class,
            InstrumentTransferred::class,
        ] as $eventClass) {
            $this->assertSame('handleInstrumentEvent', $subscriptions[$eventClass] ?? null);
        }
    }

    public function test_receive_deposit_clear_cycle_persists_three_typed_audit_events(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        app(ChartOfAccountsService::class)->seedForCompany($company);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $bank = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
            'code' => 'BANK-'.Str::upper(Str::random(6)),
            'currency' => 'TND',
            'balance' => '0.000',
            'gl_account_id' => Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Bank)->id,
        ]);
        $safe = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'safe',
            'code' => 'SAFE-'.Str::upper(Str::random(6)),
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
        $service = app(InstrumentLifecycleService::class);

        $instrument = $service->receive(new ReceiveInstrumentData(
            tenantId: $tenant->id,
            companyId: $company->id,
            paymentMethodId: $method->id,
            kind: InstrumentKind::Cheque,
            direction: InstrumentDirection::Inbound,
            origin: InstrumentOrigin::Web,
            reference: 'AUDIT-CHEQUE-1',
            amount: '50.000',
            currency: 'TND',
            repositoryId: $safe->id,
            createdBy: $user->id,
        ));
        $service->deposit($instrument->id, $bank->id, $user->id);
        $service->clear(new ClearInstrumentData($instrument->id, 'TND', userId: $user->id));

        $events = AuditEvent::query()
            ->where('aggregate_type', 'PaymentInstrument')
            ->where('aggregate_id', $instrument->id)
            ->orderBy('created_at')
            ->pluck('event_type')
            ->all();
        $this->assertSame([
            'treasury.instrument.received',
            'treasury.instrument.deposited',
            'treasury.instrument.cleared',
        ], $events);
    }
}
