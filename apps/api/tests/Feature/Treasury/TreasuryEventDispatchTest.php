<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\BankReconciliation;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\ReconciliationStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Events\InstrumentBounced;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Events\InstrumentDeposited;
use App\Modules\Treasury\Domain\Events\InstrumentTransferred;
use App\Modules\Treasury\Domain\Events\PaymentRefunded;
use App\Modules\Treasury\Domain\Events\PaymentReversed;
use App\Modules\Treasury\Domain\Events\ReconciliationCompleted;
use App\Modules\Treasury\Domain\Events\RepositoryBalanceChanged;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TreasuryEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;
    private Partner $partner;
    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::factory()->for($this->tenant)->create();

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Set CompanyContext for service-level tests
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    private function createCompletedPayment(string $amount = '100.000'): Payment
    {
        return Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'reference' => 'PAY-' . Str::random(6),
        ]);
    }

    private function createInstrument(InstrumentStatus $status = InstrumentStatus::Received): PaymentInstrument
    {
        return PaymentInstrument::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'reference' => 'CHK-' . Str::random(6),
            'amount' => '500.000',
            'currency' => 'TND',
            'status' => $status,
            'received_date' => now(),
            'maturity_date' => now()->addDays(30),
        ]);
    }

    private function createBankRepository(): PaymentRepository
    {
        return PaymentRepository::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BNK-' . Str::random(4),
            'name' => 'Bank Account',
            'type' => RepositoryType::BankAccount,
            'balance' => '0.000',
            'currency' => 'TND',
        ]);
    }

    // --- Task 1: PaymentRefunded ---

    public function test_refund_payment_dispatches_payment_refunded_event(): void
    {
        Event::fake([PaymentRefunded::class]);

        $payment = $this->createCompletedPayment();
        $service = app(PaymentRefundService::class);
        $refund = $service->refundPayment($payment, 'Customer request');

        Event::assertDispatched(PaymentRefunded::class, function (PaymentRefunded $event) use ($refund, $payment) {
            return $event->paymentId === $refund->id
                && $event->originalPaymentId === $payment->id
                && $event->amount === $refund->amount
                && $event->reason === 'Customer request';
        });
    }

    // --- Task 2: PaymentReversed ---

    public function test_reverse_payment_dispatches_payment_reversed_event(): void
    {
        Event::fake([PaymentReversed::class]);

        $payment = $this->createCompletedPayment();
        $service = app(PaymentRefundService::class);
        $service->reversePayment($payment, 'Entry error');

        Event::assertDispatched(PaymentReversed::class, function (PaymentReversed $event) use ($payment) {
            return $event->paymentId === $payment->id
                && $event->amount === $payment->amount
                && $event->currency === 'TND';
        });
    }

    // --- Task 3: Instrument Events ---

    public function test_deposit_instrument_dispatches_instrument_deposited_event(): void
    {
        Event::fake([InstrumentDeposited::class]);

        $instrument = $this->createInstrument(InstrumentStatus::Received);
        $repository = $this->createBankRepository();

        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/deposit", [
                'repository_id' => $repository->id,
            ])
            ->assertOk();

        Event::assertDispatched(InstrumentDeposited::class, function (InstrumentDeposited $event) use ($instrument, $repository) {
            return $event->instrumentId === $instrument->id
                && $event->repositoryId === $repository->id
                && $event->amount === '500.000';
        });
    }

    public function test_clear_instrument_dispatches_instrument_cleared_event(): void
    {
        Event::fake([InstrumentCleared::class]);

        $instrument = $this->createInstrument(InstrumentStatus::Deposited);

        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/clear")
            ->assertOk();

        Event::assertDispatched(InstrumentCleared::class, function (InstrumentCleared $event) use ($instrument) {
            return $event->instrumentId === $instrument->id
                && $event->amount === '500.000';
        });
    }

    public function test_bounce_instrument_dispatches_instrument_bounced_event(): void
    {
        Event::fake([InstrumentBounced::class]);

        $instrument = $this->createInstrument(InstrumentStatus::Deposited);

        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/bounce", [
                'reason' => 'Insufficient funds',
            ])
            ->assertOk();

        Event::assertDispatched(InstrumentBounced::class, function (InstrumentBounced $event) use ($instrument) {
            return $event->instrumentId === $instrument->id
                && $event->reason === 'Insufficient funds';
        });
    }

    public function test_transfer_instrument_dispatches_instrument_transferred_event(): void
    {
        Event::fake([InstrumentTransferred::class]);

        $originalRepo = $this->createBankRepository();
        $instrument = $this->createInstrument(InstrumentStatus::Received);
        $instrument->update(['repository_id' => $originalRepo->id]);

        $targetRepo = $this->createBankRepository();

        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-instruments/{$instrument->id}/transfer", [
                'to_repository_id' => $targetRepo->id,
            ])
            ->assertOk();

        Event::assertDispatched(InstrumentTransferred::class, function (InstrumentTransferred $event) use ($instrument, $originalRepo, $targetRepo) {
            return $event->instrumentId === $instrument->id
                && $event->fromRepositoryId === $originalRepo->id
                && $event->toRepositoryId === $targetRepo->id;
        });
    }

    // --- Task 4: ReconciliationCompleted ---

    public function test_complete_reconciliation_dispatches_reconciliation_completed_event(): void
    {
        Event::fake([ReconciliationCompleted::class]);

        $repository = $this->createBankRepository();

        $reconciliation = BankReconciliation::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'repository_id' => $repository->id,
            'statement_date' => now()->toDateString(),
            'opening_balance' => '0.000',
            'closing_balance' => '10000.000',
            'statement_balance' => '10000.000',
            'difference' => '0.000',
            'status' => ReconciliationStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        $service = app(\App\Modules\Treasury\Application\Services\BankReconciliationService::class);
        $service->completeReconciliation($reconciliation->id, $this->user->id);

        Event::assertDispatched(ReconciliationCompleted::class, function (ReconciliationCompleted $event) use ($reconciliation, $repository) {
            return $event->reconciliationId === $reconciliation->id
                && $event->repositoryId === $repository->id
                && $event->matchedCount === 0;
        });
    }
}
