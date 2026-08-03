<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * N2/N3 hardening (2026-08-02 treasury-fix-lane minor-followups ticket):
 * `InstrumentLifecycleService::cancelForPaymentReversal()` is the
 * `InstrumentReversalCancellerInterface` port `PaymentRefundService::
 * reversePayment()` depends on to resolve the MTP-TRE-23 deadlock. These
 * tests exercise the port directly (bypassing `PaymentRefundService`) to
 * prove the two hardening fixes in isolation:
 *
 * - N2: a real, testable domain precondition on the linked payment's status
 *   (must be `Completed`) — replacing reliance on convention (the sole
 *   caller flips the payment status right after this call returns) and on
 *   the `DB::transactionLevel()` guard, which is unverifiable under
 *   `RefreshDatabase`.
 * - N3: tenant/company scoping on the instrument lookup.
 */
final class InstrumentReversalCancellerHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentRepository $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->bank = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
            'balance' => '200.000',
        ]);
    }

    /**
     * N2: a Completed payment is the ONLY state that legitimises this call —
     * proves the port no longer trusts convention.
     */
    public function test_it_cancels_a_received_instrument_whose_payment_is_completed(): void
    {
        [$payment, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Completed);

        DB::transaction(function () use ($instrument): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $this->tenant->id,
                $this->company->id,
                $this->user->id,
                'N2 positive control',
            );
        });

        $this->assertSame(InstrumentStatus::Cancelled, $instrument->fresh()?->status);
    }

    /**
     * N2: before the fix, nothing inside the port checked the linked
     * payment's status at all — it would happily cancel a Received
     * instrument whose payment was already Reversed (a stale/duplicate call,
     * or a direct caller bypassing PaymentRefundService entirely).
     */
    public function test_it_rejects_a_received_instrument_whose_payment_is_already_reversed(): void
    {
        [, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Reversed);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('requires the instrument\'s linked payment to be Completed');

        DB::transaction(function () use ($instrument): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $this->tenant->id,
                $this->company->id,
                $this->user->id,
                'N2 already-reversed guard',
            );
        });
    }

    /** N2: a Pending payment can never be "in the middle of a reversal". */
    public function test_it_rejects_a_received_instrument_whose_payment_is_pending(): void
    {
        [, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Pending);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('requires the instrument\'s linked payment to be Completed');

        DB::transaction(function () use ($instrument): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $this->tenant->id,
                $this->company->id,
                $this->user->id,
                'N2 pending guard',
            );
        });
    }

    /** N2: an instrument with no linked payment at all cannot be "in-flight". */
    public function test_it_rejects_a_received_instrument_with_no_linked_payment(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $method->id,
            'payment_id' => null,
            'partner_id' => $this->partner->id,
            'reference' => 'NO-PAYMENT-LINKED',
            'amount' => '30.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
            'direction' => InstrumentDirection::Inbound,
            'origin' => 'web',
            'repository_id' => $this->bank->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no linked payment');

        DB::transaction(function () use ($instrument): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $this->tenant->id,
                $this->company->id,
                $this->user->id,
                'N2 no-payment guard',
            );
        });
    }

    /**
     * N3: the instrument lookup is scoped by tenant/company — a caller
     * passing the wrong company id (even for the SAME instrument id, which
     * cannot collide across tenants under db-per-tenant, but company_id can
     * collide within a tenant's DB) must fail closed with not-found rather
     * than silently operating on it.
     */
    public function test_it_scopes_the_instrument_lookup_by_company(): void
    {
        [, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Completed);
        $otherCompany = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);

        $this->expectException(ModelNotFoundException::class);

        DB::transaction(function () use ($instrument, $otherCompany): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $this->tenant->id,
                $otherCompany->id,
                $this->user->id,
                'N3 company-scope guard',
            );
        });
    }

    /** N3: same guard for tenant scope. */
    public function test_it_scopes_the_instrument_lookup_by_tenant(): void
    {
        [, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Completed);
        $otherTenant = Tenant::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        DB::transaction(function () use ($instrument, $otherTenant): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $otherTenant->id,
                $this->company->id,
                $this->user->id,
                'N3 tenant-scope guard',
            );
        });
    }

    /** @return array{Payment, PaymentInstrument} */
    private function receivedInstrumentFor(PaymentStatus $paymentStatus): array
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '30.000',
            'currency' => 'TND',
            'status' => $paymentStatus,
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $method->id,
            'payment_id' => $payment->id,
            'partner_id' => $this->partner->id,
            'reference' => 'HARDENING-'.Str::upper($paymentStatus->value),
            'amount' => '30.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'kind' => InstrumentKind::Cheque,
            'direction' => InstrumentDirection::Inbound,
            'origin' => 'web',
            'repository_id' => $this->bank->id,
        ]);
        $payment->update(['instrument_id' => $instrument->id]);

        return [$payment, $instrument];
    }
}
