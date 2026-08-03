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
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * N2/N3/H1/M1 hardening (2026-08-02 treasury-fix-lane minor-followups
 * ticket + 2026-08-03 gate finding H1 fix round):
 * `InstrumentLifecycleService::cancelForPaymentReversal()` is the
 * `InstrumentReversalCancellerInterface` port `PaymentRefundService::
 * reversePayment()` depends on to resolve the MTP-TRE-23 deadlock. These
 * tests exercise the port directly (bypassing `PaymentRefundService`) to
 * prove the hardening fixes in isolation, plus one full HTTP-level
 * reproduction of the H1 exploit:
 *
 * - N2: the instrument's linked payment must be `Completed`
 *   (`PaymentStatus::canReverse()`) — replacing reliance on convention.
 * - N3: tenant/company scoping on the instrument lookup (M2: a scope miss
 *   is a clean `DomainException`, not a leaked `ModelNotFoundException`).
 * - H1 (2026-08-03 gate finding — REQUIRED, N2 ALONE DID NOT CLOSE THIS):
 *   the caller must pass the id of the payment it is actually reversing,
 *   and the port asserts that id equals the instrument's OWN linked
 *   payment (`payment_instruments.payment_id`) — closing the
 *   publicly-reachable hole where a second payment referencing (but not
 *   owning) another payment's instrument could cancel that OTHER payment's
 *   instrument while leaving it untouched.
 *
 * M1 ruling (this file's `test_a_failed_payment_cannot_be_reversed` +
 * `PaymentRefundService::reversePayment()`): a `Failed` payment was never
 * `Completed` in the first place and is therefore NOT reversible — the
 * guard now matches `PaymentStatus::canReverse()` exactly.
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
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('admin');
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
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'currency' => 'TND',
            'balance' => '200.000',
        ]);
    }

    /**
     * M3 reword (2026-08-03 gate finding — this test previously encoded the
     * H1 hole as intended behaviour): the legitimate shape is an in-flight
     * reversal of THE payment that owns the instrument — the paymentId
     * passed IS the instrument's own `payment_id`, and that payment is
     * `Completed`. That configuration is not a defect; H1a/H1b below prove
     * every OTHER configuration (identity mismatch) is refused.
     */
    public function test_it_cancels_a_received_instrument_when_the_reversed_payment_id_matches_the_instruments_own_payment(): void
    {
        [$payment, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Completed);

        DB::transaction(function () use ($instrument, $payment): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $payment->id,
                $this->tenant->id,
                $this->company->id,
                $this->user->id,
                'legitimate in-flight reversal',
            );
        });

        $this->assertSame(InstrumentStatus::Cancelled, $instrument->fresh()?->status);
    }

    /**
     * N2: before the N2 fix, nothing inside the port checked the linked
     * payment's status at all — it would happily cancel a Received
     * instrument whose payment was already Reversed (a stale/duplicate call,
     * or a direct caller bypassing PaymentRefundService entirely).
     */
    public function test_it_rejects_a_received_instrument_whose_payment_is_already_reversed(): void
    {
        [$payment, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Reversed);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('requires the instrument\'s linked payment to be Completed');

        DB::transaction(function () use ($instrument, $payment): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $payment->id,
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
        [$payment, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Pending);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('requires the instrument\'s linked payment to be Completed');

        DB::transaction(function () use ($instrument, $payment): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $payment->id,
                $this->tenant->id,
                $this->company->id,
                $this->user->id,
                'N2 pending guard',
            );
        });
    }

    /**
     * M1 ruling: a `Failed` payment was never `Completed` — it is NOT
     * reversible. `PaymentRefundService::reversePayment()` now refuses it at
     * the very first guard (before any transaction opens); this test proves
     * that refusal is intentional, not an accidental regression from the H1
     * fix round. Legacy/imported data only — no Treasury writer ever sets
     * `Failed` on a real payment.
     */
    public function test_a_failed_payment_cannot_be_reversed(): void
    {
        [$payment] = $this->receivedInstrumentFor(PaymentStatus::Failed);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only completed payments can be reversed');

        app(PaymentRefundService::class)->reversePayment($payment->fresh() ?? $payment, 'legacy failed payment');
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

        // No instrument.payment_id to match, so ANY non-null paymentId the
        // caller supplies fails the H1 identity check first — that is
        // itself proof there is no legitimate payment this call could be
        // reversing.
        $bystanderPaymentId = Str::uuid()->toString();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('refused: instrument is not linked to the payment being reversed');

        DB::transaction(function () use ($instrument, $bystanderPaymentId): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $bystanderPaymentId,
                $this->tenant->id,
                $this->company->id,
                $this->user->id,
                'N2 no-payment guard',
            );
        });
    }

    /**
     * H1a (2026-08-03 gate finding — direct port call, no reversal in
     * flight): the instrument's OWN linked payment ($owningPayment) is
     * Completed and eligible, but the caller supplies a DIFFERENT
     * (impostor) payment's id — nobody is actually reversing the owning
     * payment. Before the H1 fix this call still succeeded purely on the
     * owning payment's Completed status: the instrument cancelled, the
     * owning payment's own AR-restoring GL entry posted, and the owning
     * payment itself stayed Completed untouched — a GL-vs-subledger
     * divergence created by the port itself. Must now refuse.
     */
    public function test_h1a_it_rejects_a_direct_call_whose_payment_id_does_not_match_the_instruments_own_payment(): void
    {
        [$owningPayment, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Completed);
        $impostorPayment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'amount' => '30.000',
            'currency' => 'TND',
            'status' => PaymentStatus::Completed,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('refused: instrument is not linked to the payment being reversed');

        try {
            DB::transaction(function () use ($instrument, $impostorPayment): void {
                app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                    $instrument->id,
                    $impostorPayment->id,
                    $this->tenant->id,
                    $this->company->id,
                    $this->user->id,
                    'H1a mismatched payment id, no reversal in flight',
                );
            });
        } finally {
            $this->assertSame(
                InstrumentStatus::Received,
                $instrument->fresh()?->status,
                'the instrument must remain untouched when identity does not match',
            );
            $this->assertSame(PaymentStatus::Completed, $owningPayment->fresh()?->status);
        }
    }

    /**
     * H1b (2026-08-03 gate finding — the exact publicly-reachable exploit,
     * driven end to end over the real HTTP API): payment P1 owns a Received
     * instrument. Payment P2 is a completely ordinary (non-deferred)
     * payment created with `instrument_id` pointing at P1's instrument —
     * `PaymentController::store()` accepts this with no ownership check and
     * writes NO back-link (that only happens for a freshly-issued deferred
     * instrument), so `P2.instrument_id === P1's instrument` while
     * `instrument.payment_id` stays `P1.id`. Reversing P2 over
     * `POST /payments/{id}/reverse` must refuse cleanly (422, no leaked
     * exception/model internals) and leave P1's instrument and payment
     * completely untouched.
     */
    public function test_h1b_reversing_a_payment_that_only_references_a_foreign_instrument_is_refused_over_http(): void
    {
        [$owningPayment, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Completed);

        $immediateMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        $impostorResponse = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $immediateMethod->id,
            'repository_id' => $this->bank->id,
            'instrument_id' => $instrument->id,
            'amount' => '10.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();
        $impostorPaymentId = (string) $impostorResponse->json('data.id');

        // Proves the exploit setup: P2 references P1's instrument, but the
        // instrument's own back-link is unchanged (still P1).
        $this->assertSame($instrument->id, $impostorResponse->json('data.instrument_id'));
        $this->assertSame($owningPayment->id, $instrument->fresh()?->payment_id);

        $reverseResponse = $this->actingAs($this->user)->postJson(
            "/api/v1/payments/{$impostorPaymentId}/reverse",
            ['reason' => 'H1b cross-payment instrument_id exploit attempt'],
        );

        $reverseResponse->assertStatus(422);
        $errorMessage = (string) $reverseResponse->json('error');
        $this->assertStringContainsString('refused', $errorMessage);
        $this->assertStringNotContainsString('PaymentInstrument', $errorMessage, 'must not leak an internal model class name (M2)');
        $this->assertStringNotContainsString('ModelNotFoundException', $errorMessage);

        // P1 — the REAL owner — is completely untouched.
        $instrument->refresh();
        $this->assertSame(InstrumentStatus::Received, $instrument->status);
        $this->assertSame(PaymentStatus::Completed, $owningPayment->fresh()?->status);

        // P2 itself was never reversed either — the whole transaction rolled back.
        $this->assertSame(PaymentStatus::Completed, Payment::query()->findOrFail($impostorPaymentId)->status);
    }

    /**
     * N3/M2: the instrument lookup is scoped by tenant/company — a caller
     * passing the wrong company id (even for the SAME instrument id, which
     * cannot collide across tenants under db-per-tenant, but company_id can
     * collide within a tenant's DB) must fail closed. M2: the scope miss is
     * a clean `DomainException`, never the ORM's raw `ModelNotFoundException`
     * (which would leak an internal model class name + uuid through the
     * controller's catch-`\Exception` 422).
     */
    public function test_it_scopes_the_instrument_lookup_by_company(): void
    {
        [$payment, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Completed);
        $otherCompany = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Instrument not found in the given tenant/company scope.');

        DB::transaction(function () use ($instrument, $payment, $otherCompany): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $payment->id,
                $this->tenant->id,
                $otherCompany->id,
                $this->user->id,
                'N3 company-scope guard',
            );
        });
    }

    /** N3/M2: same guard for tenant scope. */
    public function test_it_scopes_the_instrument_lookup_by_tenant(): void
    {
        [$payment, $instrument] = $this->receivedInstrumentFor(PaymentStatus::Completed);
        $otherTenant = Tenant::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Instrument not found in the given tenant/company scope.');

        DB::transaction(function () use ($instrument, $payment, $otherTenant): void {
            app(InstrumentLifecycleService::class)->cancelForPaymentReversal(
                $instrument->id,
                $payment->id,
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
