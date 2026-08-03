<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\OutboundInstrumentService;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DeferredTenderGuardsTest extends TestCase
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
            'currency' => 'TND',
            'balance' => '200.000',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
        ]);
    }

    /**
     * REWRITTEN (2026-08-02 minor-followups ticket, dev-hygiene section):
     * this test was stale, not a bug. `9ae7db934` (2026-07-18) deliberately
     * moved supplier deferred tenders to portfolio-ISSUANCE at creation
     * (Dr 401 SupplierPayable / Cr portfolio account e.g. 403 Effets à
     * payer — `GeneralLedgerService::createOutboundInstrumentIssueEntry()`,
     * `source_type='instrument'`) with the cash movement + bank-leg GL
     * entry deferred to `OutboundInstrumentService::clear()` — see
     * `DeferredSupplierPaymentTest::
     * test_deferred_supplier_cheque_posts_one_issue_entry_no_bank_line_and_no_movement()`
     * for the sibling coverage this test never got updated to match. This
     * test now asserts the CURRENT settlement-time design end to end: zero
     * `repository_movements` and an unchanged bank balance at creation, then
     * drives `OutboundInstrumentService::clear()` and asserts the movement
     * + balance change land THERE instead.
     */
    public function test_supplier_traite_settles_at_clear_not_at_creation(): void
    {
        $invoice = $this->supplierInvoice('50.000');
        $method = $this->method(InstrumentKind::Effet);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '50.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [['document_id' => $invoice->id, 'amount' => '50.000']],
            'instrument' => ['reference' => 'OUT-TR-50', 'maturity_date' => now()->addDays(20)->toDateString()],
        ])->assertCreated();

        $payment = Payment::query()->whereKey((string) $response->json('data.id'))->firstOrFail();
        $instrument = PaymentInstrument::query()->where('payment_id', $payment->id)->sole();
        $this->assertSame('outbound', $instrument->direction->value);
        $this->assertSame(InstrumentStatus::Received, $instrument->status);

        // Creation time: no cash has moved yet — the instrument was only
        // ISSUED into the portfolio, not settled.
        $this->assertDatabaseCount('repository_movements', 0);
        $this->assertSame('200.000', $this->bank->fresh()?->balance);

        $supplierPayableId = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::SupplierPayable,
        )->id;
        $entry = JournalEntry::query()->with('lines.account')->findOrFail($payment->journal_entry_id);
        $this->assertSame('instrument', $entry->source_type);
        $this->assertSame($instrument->id, $entry->source_id);
        $this->assertCount(2, $entry->lines);
        $this->assertSame('50.000', $entry->lines->firstWhere('account_id', $supplierPayableId)?->debit);
        $this->assertSame('50.000', $entry->lines->firstWhere('account.code', '403')?->credit, 'credit lands on the Effets à payer portfolio account, not the bank');
        $this->assertSame(0, $entry->lines->where('account_id', $this->bank->gl_account_id)->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'instrument')->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'supplier_payment')->count());

        // Drive OutboundInstrumentService::clear() — the movement + bank GL
        // land HERE, not at creation.
        app(OutboundInstrumentService::class)->clear(
            instrumentId: $instrument->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
        );

        $this->assertSame(InstrumentStatus::Cleared, $instrument->fresh()?->status);
        $this->assertDatabaseCount('repository_movements', 1);
        $this->assertSame('150.000', $this->bank->fresh()?->balance);
        $clearingEntry = JournalEntry::query()
            ->with('lines.account')
            ->where('source_type', 'instrument')
            ->where('source_id', $instrument->id)
            ->where('id', '!=', $entry->id)
            ->sole();
        $this->assertSame('50.000', $clearingEntry->lines->firstWhere('account.code', '403')?->debit);
        $this->assertSame('50.000', $clearingEntry->lines->firstWhere('account_id', $this->bank->gl_account_id)?->credit);
    }

    public function test_all_four_side_doors_reject_maturity_methods(): void
    {
        $method = $this->method(InstrumentKind::Cheque);
        $invoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => '20.000',
            'balance_due' => '20.000',
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'document_id' => $invoice->id,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'payments' => [[
                'payment_method_id' => $method->id,
                'repository_id' => $this->bank->id,
                'amount' => '20.000',
            ]],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'DEFERRED_METHOD_NOT_SUPPORTED');
        $this->actingAs($this->user)->postJson("/api/v1/documents/{$invoice->id}/split-payment", [
            'splits' => [
                ['payment_method_id' => $method->id, 'amount' => '10.000', 'repository_id' => $this->bank->id],
                ['payment_method_id' => $method->id, 'amount' => '10.000', 'repository_id' => $this->bank->id],
            ],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'DEFERRED_METHOD_NOT_SUPPORTED');
        $this->actingAs($this->user)->postJson('/api/v1/payments/deposit', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'amount' => '20.000',
            'currency' => 'TND',
            'repository_id' => $this->bank->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'DEFERRED_METHOD_NOT_SUPPORTED');
        $this->actingAs($this->user)->postJson('/api/v1/payments/on-account', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'amount' => '20.000',
            'currency' => 'TND',
            'repository_id' => $this->bank->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'DEFERRED_METHOD_NOT_SUPPORTED');
    }

    public function test_all_four_side_doors_still_accept_immediate_methods(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        $first = $this->customerInvoice('20.000');
        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'document_id' => $first->id,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'payments' => [[
                'payment_method_id' => $method->id,
                'repository_id' => $this->bank->id,
                'amount' => '20.000',
            ]],
        ])->assertCreated();

        $second = $this->customerInvoice('20.000');
        $this->actingAs($this->user)->postJson("/api/v1/documents/{$second->id}/split-payment", [
            'splits' => [
                ['payment_method_id' => $method->id, 'amount' => '10.000', 'repository_id' => $this->bank->id],
                ['payment_method_id' => $method->id, 'amount' => '10.000', 'repository_id' => $this->bank->id],
            ],
        ])->assertCreated();

        $this->actingAs($this->user)->postJson('/api/v1/payments/deposit', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'amount' => '5.000',
            'currency' => 'TND',
            'repository_id' => $this->bank->id,
        ])->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/payments/on-account', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'amount' => '5.000',
            'currency' => 'TND',
            'repository_id' => $this->bank->id,
        ])->assertCreated();
    }

    public function test_supplier_maturity_method_with_other_kind_creates_no_instrument(): void
    {
        $invoice = $this->supplierInvoice('10.000');
        $method = $this->method(InstrumentKind::Other);

        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '10.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [['document_id' => $invoice->id, 'amount' => '10.000']],
        ])->assertCreated();

        $this->assertDatabaseCount('payment_instruments', 0);
        $this->assertDatabaseCount('repository_movements', 1);
    }

    /**
     * REVISED (2026-08-02 adversarial-review remediation, review findings
     * C1/C2/C3/I8): the orchestrator's original MTP-TRE-23 ruling — atomic
     * instrument cancellation for ALL of reverse/refund/partial-refund —
     * was found to money-leak on the refund paths (phantom cash OUT of a
     * repository that never received it, a doubled AR debit, and a WHOLE
     * instrument destroyed to satisfy a PARTIAL refund) and was narrowed to
     * reversePayment()-ONLY. `full`/`partial` therefore stay in THIS
     * blocked loop for ALL THREE non-settled statuses — including
     * `Received`, restored here (I8: the previous revision of this test
     * removed it, which is exactly how C1/C2/C3 went untested). `reverse`
     * is excluded from this loop and covered by its own test below with
     * `Received`, since it alone resolves the instrument atomically.
     */
    public function test_pending_instrument_blocks_refund_and_partial_but_cleared_allows_refund(): void
    {
        $method = $this->method(InstrumentKind::Cheque);
        $service = app(PaymentRefundService::class);
        foreach ([InstrumentStatus::Received, InstrumentStatus::Deposited, InstrumentStatus::Bounced] as $status) {
            foreach (['full', 'partial'] as $operationName) {
                [$payment] = $this->pendingPayment($method, $status->value.'-'.$operationName, $status);
                try {
                    match ($operationName) {
                        'full' => $service->refundPayment($payment, 'blocked'),
                        'partial' => $service->partialRefund($payment, '5.000', 'blocked'),
                    };
                    $this->fail("{$status->value} instrument must block the {$operationName} refund path");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('instrument', strtolower($exception->getMessage()));
                }
            }
        }

        // Deposited/Bounced also still block reverse() — only Received
        // resolves atomically (see the dedicated reverse test below).
        foreach ([InstrumentStatus::Deposited, InstrumentStatus::Bounced] as $status) {
            [$payment] = $this->pendingPayment($method, $status->value.'-reverse', $status);
            try {
                $service->reversePayment($payment, 'blocked');
                $this->fail("{$status->value} instrument must block reversePayment()");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('instrument', strtolower($exception->getMessage()));
            }
        }

        [$payment, $instrument] = $this->pendingPayment($method, 'cleared');
        $instrument->update(['status' => InstrumentStatus::Cleared]);
        $this->assertTrue($service->canRefund($payment->fresh() ?? $payment));

        [$cancelledPayment, $cancelledInstrument] = $this->pendingPayment($method, 'cancelled');
        $cancelledInstrument->update(['status' => InstrumentStatus::Cancelled]);
        $this->assertTrue($service->canRefund($cancelledPayment->fresh() ?? $cancelledPayment));
    }

    /**
     * I8 fix: HTTP-level coverage for the SAME fail-closed paths above —
     * confirms the RuntimeException PaymentRefundService throws surfaces as
     * a clean 422 through PaymentRefundController (`catch (\Exception $e)`
     * -> 422), not a 500, for both full and partial refund of a payment
     * backed by a `Received` instrument.
     */
    public function test_refund_and_partial_refund_422_over_the_http_api_for_a_received_instrument(): void
    {
        $method = $this->method(InstrumentKind::Cheque);

        [$fullPayment] = $this->pendingPayment($method, 'http-full-received');
        $fullResponse = $this->actingAs($this->user)->postJson("/api/v1/payments/{$fullPayment->id}/refund", [
            'reason' => 'I8 http full refund attempt',
            'refund_request_id' => Str::uuid()->toString(),
        ]);
        $fullResponse->assertStatus(422);

        [$partialPayment] = $this->pendingPayment($method, 'http-partial-received');
        $partialResponse = $this->actingAs($this->user)->postJson("/api/v1/payments/{$partialPayment->id}/partial-refund", [
            'amount' => '5.000',
            'reason' => 'I8 http partial refund attempt',
            'refund_request_id' => Str::uuid()->toString(),
        ]);
        $partialResponse->assertStatus(422);
    }

    /**
     * MTP-TRE-23: the circular precondition deadlock. Before the fix, ALL
     * THREE of these operations threw for a Received instrument, and
     * InstrumentLifecycleService::cancel() refused too (requires the
     * payment already Reversed) — no valid API ordering existed for a
     * cheque/effet payment whose instrument hadn't cleared. This test
     * proves the atomic resolution: reversePayment() cancels the
     * `Received` instrument INSIDE its own transaction (same GL entry +
     * InstrumentEvent audit row a standalone cancel() would emit) and THEN
     * flips the payment to Reversed.
     *
     * Review C1/C2 (ruling #2) assertions added on remediation: reversing a
     * payment whose instrument never cleared must move NO cash (the money
     * never arrived — a deferred customer payment records no cash-IN
     * movement until the instrument clears) and post NO bank-leg refund
     * entry — the ONLY GL effect is the instrument's own Dr 411 / Cr
     * portfolio cancellation entry, i.e. exactly ONE AR restoration, never
     * two.
     */
    public function test_reverse_atomically_cancels_a_received_instrument_and_resolves_the_deadlock(): void
    {
        $method = $this->method(InstrumentKind::Cheque);
        $invoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => '40.000',
            'balance_due' => '40.000',
        ]);

        $bankBalanceBeforePayment = $this->bank->fresh()?->balance;

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '40.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [['document_id' => $invoice->id, 'amount' => '40.000']],
            'instrument' => ['reference' => 'MTP-TRE-23-CHQ'],
        ])->assertCreated();

        $payment = Payment::query()->whereKey((string) $response->json('data.id'))->firstOrFail();
        $instrument = PaymentInstrument::query()->where('payment_id', $payment->id)->sole();
        $this->assertSame(InstrumentStatus::Received, $instrument->status);
        $this->assertNotNull($payment->journal_entry_id);
        // Deferred-customer guard (PaymentController.php `$isDeferredCustomer`):
        // receiving a cheque records the receivable, NOT cash in hand.
        $this->assertSame($bankBalanceBeforePayment, $this->bank->fresh()?->balance, 'receiving a cheque moves no cash');
        $this->assertDatabaseCount('repository_movements', 0);

        // Pre-fix, this threw: "Settle the payment instrument first (bounce
        // or cancel) before using the cash refund/reverse path."
        app(PaymentRefundService::class)->reversePayment(
            $payment->fresh() ?? $payment,
            'MTP-TRE-23 atomic reversal'
        );

        $payment->refresh();
        $instrument->refresh();
        $this->assertSame(PaymentStatus::Reversed, $payment->status);
        $this->assertSame(InstrumentStatus::Cancelled, $instrument->status);

        // C6: the reversal's allocation-lineage wipe must leave NOTHING
        // behind for this payment (no prior partial refunds exist here, so
        // this is just the base "reverse deletes its own allocations" case
        // — the multi-generation lineage case is covered separately by
        // PaymentRefundTest::test_reverse_after_partial_refund_neutralises_the_whole_allocation_lineage()).
        $this->assertSame(0, PaymentAllocation::where('payment_id', $payment->id)->count());

        $invoice->refresh();
        $this->assertSame('40.000', $invoice->balance_due, 'balance_due reopens to the full total');
        $this->assertSame(DocumentStatus::Posted, $invoice->status);

        $cancelEvent = InstrumentEvent::query()
            ->where('instrument_id', $instrument->id)
            ->where('event_type', 'cancelled')
            ->sole();
        $this->assertSame('received', $cancelEvent->from_status);
        $this->assertSame('cancelled', $cancelEvent->to_status);
        $this->assertNotNull(
            $cancelEvent->journal_entry_id,
            'the atomic cancel must post the same GL reversal a standalone cancel() would'
        );
        $this->assertNotSame($payment->journal_entry_id, $cancelEvent->journal_entry_id);

        // Ruling #2: no cash movement, no bank-leg GL entry — reversePayment()
        // never calls postRefundGlAndMovement().
        $this->assertSame($bankBalanceBeforePayment, $this->bank->fresh()?->balance, 'no cash leaves the repository — it never arrived');
        $this->assertDatabaseCount('repository_movements', 0);
        $this->assertSame(
            0,
            JournalEntry::where('company_id', $this->company->id)->where('source_type', 'customer_payment_refund')->count(),
            'no bank-leg refund entry may be posted for an instrument that never cleared'
        );

        // Ruling #2: exactly ONE AR restoration — the instrument's own
        // cancellation entry — never two (review C2's double-debit).
        $instrumentEntries = JournalEntry::where('company_id', $this->company->id)
            ->where('source_type', 'instrument')
            ->where('source_id', $instrument->id)
            ->get();
        $this->assertCount(1, $instrumentEntries);
        $arAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CustomerReceivable);
        $arLines = JournalLine::where('journal_entry_id', $instrumentEntries->first()->id)
            ->where('account_id', $arAccount->id)
            ->get();
        $this->assertCount(1, $arLines);
        $this->assertSame('40.000', $arLines->first()->debit);
        $this->assertSame('0.000', $arLines->first()->credit);
    }

    /**
     * MTP-TRE-23: standalone InstrumentLifecycleService::cancel() keeps its
     * existing fail-closed precondition for DIRECT callers — the atomic
     * resolution above lives ONLY inside the reverse/refund transactions
     * (PaymentRefundService::resolveInstrumentForReversal() ->
     * cancelForPaymentReversal()), it does not weaken cancel() itself.
     */
    public function test_standalone_cancel_still_fails_closed_for_a_received_instrument_with_an_unreversed_payment(): void
    {
        $method = $this->method(InstrumentKind::Cheque);
        [, $instrument] = $this->pendingPayment($method, 'standalone-cancel-guard');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Settle or reverse the linked payment before cancelling its instrument.');

        app(InstrumentLifecycleService::class)->cancel($instrument->id, $this->user->id, 'direct cancel attempt');
    }

    public function test_cleared_deferred_payment_can_refund_from_the_cleared_bank_repository(): void
    {
        $method = $this->method(InstrumentKind::Cheque);
        $invoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => '30.000',
            'balance_due' => '30.000',
        ]);
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '30.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [['document_id' => $invoice->id, 'amount' => '30.000']],
            'instrument' => ['reference' => 'CLEAR-THEN-REFUND'],
        ])->assertCreated();
        $payment = Payment::query()->whereKey((string) $response->json('data.id'))->firstOrFail();
        $instrument = PaymentInstrument::query()->where('payment_id', $payment->id)->sole();
        $lifecycle = app(InstrumentLifecycleService::class);
        $lifecycle->deposit($instrument->id, $this->bank->id, $this->user->id);
        $lifecycle->clear(new ClearInstrumentData($instrument->id, 'TND', userId: $this->user->id));

        $refund = app(PaymentRefundService::class)->refundPayment($payment->fresh() ?? $payment, 'after clear');

        $this->assertSame('-30.000', $refund->amount);
        $this->assertDatabaseHas('repository_movements', [
            'payment_repository_id' => $this->bank->id,
            'source_type' => 'refund',
            'source_id' => $payment->id,
            'direction' => 'out',
        ]);
    }

    public function test_payment_api_exposes_dishonored_at(): void
    {
        $payment = Payment::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'dishonored_at' => now(),
        ]);

        $this->actingAs($this->user)->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.dishonored_at', $payment->dishonored_at?->toIso8601String());
    }

    private function method(InstrumentKind $kind): PaymentMethod
    {
        return PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'has_maturity' => true,
            'instrument_kind' => $kind,
        ]);
    }

    private function supplierInvoice(string $amount): Document
    {
        $invoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SupplierInvoice,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => $amount,
            'balance_due' => $amount,
        ]);
        app(GeneralLedgerService::class)->createSupplierInvoiceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            invoiceId: $invoice->id,
            totalAmount: $amount,
            netAmount: $amount,
            vatAmount: '0.000',
            expenseAccountId: Account::findByPurposeOrFail(
                $this->company->id,
                SystemAccountPurpose::PurchaseExpenses,
            )->id,
            date: now(),
            user: $this->user,
            currencyCode: 'TND',
        );

        return $invoice;
    }

    private function customerInvoice(string $amount): Document
    {
        return Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'status' => DocumentStatus::Posted,
            'currency' => 'TND',
            'total' => $amount,
            'balance_due' => $amount,
        ]);
    }

    /** @return array{Payment, PaymentInstrument} */
    private function pendingPayment(
        PaymentMethod $method,
        string $suffix,
        InstrumentStatus $status = InstrumentStatus::Received,
    ): array {
        $payment = Payment::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $this->bank->id,
            'amount' => '30.000',
            'currency' => 'TND',
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_method_id' => $method->id,
            'payment_id' => $payment->id,
            'partner_id' => $this->partner->id,
            'reference' => 'PENDING-'.Str::upper($suffix),
            'amount' => '30.000',
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => $status,
            'kind' => InstrumentKind::Cheque,
            'direction' => 'inbound',
            'origin' => 'web',
            'repository_id' => $this->bank->id,
        ]);
        $payment->update(['instrument_id' => $instrument->id]);

        return [$payment, $instrument];
    }
}
