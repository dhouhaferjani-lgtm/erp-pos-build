<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA `DPA-REV2-A` fix round 1 — the refusal and ceiling coverage the code gate
 * found missing (findings I-B and I-C), plus A10(i) (ruling 5.3).
 *
 * Plan A8's test list mandates five refusals that fix round 0 shipped with ZERO
 * coverage: the A-D3 pool ceiling (the plan's own "riskiest three" — the guard
 * that stops the `CustomerAdvance` liability being driven negative), A-D6's
 * prior-refund refusal, A-D4b belt 2, the D-4 zero-net case and the Draft-only
 * footprint. Plan A6's three ceiling tests were likewise undelivered.
 *
 * **Every refusal here carries §12.5's mandated "fail-closed writes nothing"
 * assertion set** — payment count unchanged, allocations untouched, original
 * still `Completed`, no reversing document, no journal entry, no movement.
 * A refusal that quietly wrote half its effects would be worse than no refusal.
 */
final class AdvanceReversalRefusalsAndCeilingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentMethod $cashMethod;

    private PaymentRepository $repository;

    private PaymentRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'name' => 'AdvRev Refusals Tenant',
            'slug' => 'advrev-ref-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AdvRev Refusals Co',
            'legal_name' => 'AdvRev Refusals Co SARL',
            'tax_id' => 'TAX-ADVREF',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AdvRev Refusals User',
            'email' => 'advrev-ref-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.create', 'payments.view', 'payments.reverse']);
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashMethod = PaymentMethod::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH-'.Str::random(4),
            'is_active' => true,
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => false,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $this->repository = PaymentRepository::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'TILL-'.Str::random(6),
            'name' => 'Main Till',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'account_id' => null,
            'is_active' => true,
        ]);

        $this->partner = Partner::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Refusal Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    // =================================================== I-B: A-D3 the ceiling

    /**
     * **A-D3 — the pool ceiling. The plan's own "riskiest three", and it shipped
     * with no test at all (code-gate I-B).**
     *
     * Reversing an advance that has already been APPLIED to an invoice would
     * drive the `CustomerAdvance` liability negative and re-credit cash the
     * customer consumed as goods. Consumption is pool-level with no back-link to
     * the funding payment, so the only defensible ceiling is the partner's
     * unconsumed pool — and over it the answer is REFUSE, never partially
     * reverse.
     *
     * Fixture: advance 1000, then 700 applied to an invoice through
     * `clearCustomerAdvanceToReceivable()`. Available = 300; the reversal wants
     * 1000; it must refuse, name the available figure, and write nothing.
     */
    public function test_ad3_refuses_when_the_advance_pool_is_already_consumed(): void
    {
        $payment = $this->pureAdvancePayment('1000.000');
        $invoice = $this->postedInvoice('700.000');

        // Consume 700 of the pool — the order->invoice conversion path.
        DB::transaction(fn () => app(GeneralLedgerService::class)->clearCustomerAdvanceToReceivable(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            invoiceId: $invoice->id,
            amount: '700.000',
            date: now(),
            description: 'Prepayment applied',
            postedByUserId: $this->user->id,
            currencyCode: 'TND',
        ));
        $this->postPendingEntries();

        self::assertSame(
            '300.000',
            app(GeneralLedgerService::class)->availableCustomerAdvance(
                $this->company->id,
                $this->partner->id,
                'TND',
            ),
            'fixture: 1000 received, 700 consumed, 300 unconsumed',
        );

        $before = $this->snapshot();

        try {
            $this->refundService->reversePayment($payment, 'over the ceiling', $this->user->id);
            self::fail('A-D3 must refuse a reversal beyond the unconsumed advance pool');
        } catch (\DomainException $exception) {
            $message = $exception->getMessage();
            self::assertStringContainsString('300.000', $message, 'the refusal must NAME the available figure');
            self::assertStringContainsString(
                'credit-note',
                strtolower($message),
                'and the remedy: credit-note the invoice that consumed the prepayment first',
            );
        }

        $this->assertWroteNothing($before, $payment);

        // The liability was never driven negative — the whole point of A-D3.
        self::assertSame(
            0,
            bccomp(
                '-300.000',
                app(PartnerBalanceService::class)
                    ->getCustomerAdvanceBalance($this->company->id, $this->partner->id),
                3,
            ),
            'the CustomerAdvance liability stays at its consumed level, never negative',
        );
    }

    /** The ceiling ALLOWS a reversal that fits inside the unconsumed pool. */
    public function test_ad3_allows_a_reversal_within_the_unconsumed_pool(): void
    {
        $payment = $this->pureAdvancePayment('300.000');
        $extra = $this->pureAdvancePayment('700.000');
        self::assertNotNull($extra);

        $reversal = $this->refundService->reversePayment($payment, 'within the pool', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal);
        self::assertSame(PaymentStatus::Reversed, $payment->fresh()?->status);
    }

    // =================================================== I-B: A-D6 prior refund

    /**
     * **A-D6 — any advance-backed component plus any prior refund refuses.**
     *
     * Prior refunds posted the AR-ONLY shape, so which bucket they consumed is
     * undefined and a pro-rata split would silently mis-state two accounts.
     * Keyed on `advanceBacked > 0`, NOT on "mixed" — shape Y is advance-backed
     * without being mixed and would otherwise slip past.
     */
    public function test_ad6_refuses_an_advance_backed_payment_that_has_a_prior_refund(): void
    {
        $invoice = $this->postedInvoice('700.000');
        $payment = $this->payViaApi('1000.000', [['document_id' => $invoice->id, 'amount' => '700.000']]);

        $this->refundService->partialRefund($payment, '100.000', 'prior refund', $this->user->id);

        $before = $this->snapshot();

        try {
            $this->refundService->reversePayment($payment, 'mixed with prior refund', $this->user->id);
            self::fail('A-D6 must refuse an advance-backed payment with refund history');
        } catch (\DomainException $exception) {
            $message = strtolower($exception->getMessage());
            self::assertStringContainsString('already refunded', $message);
            self::assertStringContainsString('guess', $message, 'the refusal explains WHY it will not split');
        }

        $this->assertWroteNothing($before, $payment);
    }

    // =================================================== I-B: belts 1, 2, D-4

    /**
     * **A-D4b belt 2 — non-emptiness under D-17.** The original carries a journal
     * entry, so it owes a reversing entry; an empty partition says we cannot
     * classify what to reverse.
     */
    public function test_belt2_refuses_a_payment_whose_partition_is_empty(): void
    {
        $payment = $this->paymentRow('500.000', PaymentType::DocumentPayment);

        // A POSTED entry keyed on something else entirely — D-17's gate passes,
        // the partition reads empty, and belt 1's coverage assert fires first.
        $entry = $this->rawPostedEntry('pos_receipt', Str::uuid()->toString(), '500.000');
        $payment->journal_entry_id = $entry;
        $payment->save();

        $before = $this->snapshot();

        try {
            $this->refundService->reversePayment($payment, 'empty partition', $this->user->id);
            self::fail('an unclassifiable footprint must refuse');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'classifiable',
                strtolower($exception->getMessage()),
                'belt 1 or belt 2 — both name the classification failure',
            );
        }

        $this->assertWroteNothing($before, $payment);
    }

    /**
     * **A-D4 ruling 3 / belt 1 — a DRAFT footprint does not count.** A failed
     * `AfterCommit` post leaves a Draft; the partition then UNDER-counts and the
     * coverage belt refuses. Fail-closed is right: a Draft is money we cannot
     * prove was recognised.
     */
    public function test_belt1_refuses_a_draft_only_footprint(): void
    {
        $payment = $this->paymentRow('500.000', PaymentType::DocumentPayment);

        $draft = $this->rawEntry('customer_payment', $payment->id, '500.000', JournalEntryStatus::Draft);
        $payment->journal_entry_id = $draft;
        $payment->save();

        $before = $this->snapshot();

        try {
            $this->refundService->reversePayment($payment, 'draft footprint', $this->user->id);
            self::fail('a Draft-only footprint must refuse');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'classifiable ledger footprint sums to',
                $exception->getMessage(),
                'belt 1 names the shortfall',
            );
        }

        $this->assertWroteNothing($before, $payment);
    }

    /**
     * **D-4 — a zero-net reversal writes its document but posts nothing.** The
     * payment was fully refunded already, so there is nothing left to unwind.
     * This one is NOT a refusal: the document must exist.
     */
    public function test_d4_a_zero_net_reversal_writes_the_document_and_posts_nothing(): void
    {
        $invoice = $this->postedInvoice('500.000');
        $payment = $this->payViaApi('500.000', [['document_id' => $invoice->id, 'amount' => '500.000']]);

        $this->refundService->partialRefund($payment, '500.000', 'full refund', $this->user->id);

        $entriesBefore = JournalEntry::query()->where('company_id', $this->company->id)->count();

        $reversal = $this->refundService->reversePayment($payment, 'zero net', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal, 'D-4: the reversing DOCUMENT is still written');
        self::assertSame(0, bccomp('0', (string) $reversal->amount, 3), 'sized at zero');
        self::assertSame(
            $entriesBefore,
            JournalEntry::query()->where('company_id', $this->company->id)->count(),
            'D-4: nothing left to unwind, so no reversing entry is posted',
        );
        self::assertSame(
            0,
            DB::table('repository_movements')
                ->where('idempotency_key', "refund:{$payment->id}:reversal:{$reversal->id}")
                ->count(),
            'and no cash moves',
        );
    }

    /**
     * **D-17 case A — an original that posted NO GL posts nothing and still
     * writes its reversing document.** Debiting AR for a receivable the original
     * never credited would create a phantom receivable.
     */
    public function test_d17_case_a_an_original_with_no_journal_entry_posts_nothing(): void
    {
        $payment = $this->paymentRow('500.000', PaymentType::DocumentPayment);
        self::assertNull($payment->journal_entry_id, 'fixture: the original posted no GL');

        $entriesBefore = JournalEntry::query()->where('company_id', $this->company->id)->count();

        $reversal = $this->refundService->reversePayment($payment, 'no gl', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal, 'the reversing document still exists');
        self::assertSame(
            $entriesBefore,
            JournalEntry::query()->where('company_id', $this->company->id)->count(),
            'D-17 case A: post nothing, silently',
        );
    }

    // ============================================ I-C: A6 availableCustomerAdvance

    /** Plan A6 test 1: advance 1000 -> apply 400 -> available 600. */
    public function test_a6_available_advance_is_net_of_applied_prepayments(): void
    {
        $this->pureAdvancePayment('1000.000');
        $invoice = $this->postedInvoice('400.000');

        DB::transaction(fn () => app(GeneralLedgerService::class)->clearCustomerAdvanceToReceivable(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            invoiceId: $invoice->id,
            amount: '400.000',
            date: now(),
            description: 'Prepayment applied',
            postedByUserId: $this->user->id,
            currencyCode: 'TND',
        ));
        $this->postPendingEntries();

        self::assertSame(
            '600.000',
            app(GeneralLedgerService::class)
                ->availableCustomerAdvance($this->company->id, $this->partner->id, 'TND'),
        );
    }

    /**
     * Plan A6 test 2: a PENDING `prepayment_application` DRAFT of 200 further
     * reduces the available figure to 400. Without this an in-flight
     * order→invoice conversion could be double-spent.
     */
    public function test_a6_available_advance_is_net_of_pending_draft_clearings(): void
    {
        $this->pureAdvancePayment('1000.000');
        $invoice = $this->postedInvoice('400.000');

        DB::transaction(fn () => app(GeneralLedgerService::class)->clearCustomerAdvanceToReceivable(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            invoiceId: $invoice->id,
            amount: '400.000',
            date: now(),
            description: 'Prepayment applied',
            postedByUserId: $this->user->id,
            currencyCode: 'TND',
        ));
        $this->postPendingEntries();

        // An in-flight conversion: a DRAFT prepayment_application for 200.
        $this->rawEntryWithAdvanceDebit('200.000', JournalEntryStatus::Draft);

        self::assertSame(
            '400.000',
            app(GeneralLedgerService::class)
                ->availableCustomerAdvance($this->company->id, $this->partner->id, 'TND'),
            'a pending draft clearing is reserved against the pool',
        );
    }

    /**
     * Plan A6 test 3 — **the whole point of gate finding I-2.** Called from a
     * queued/console context with NO `CompanyContext` bound it must not throw:
     * the scale comes from the entity currency passed in, never from a bare
     * no-arg `getScale()`.
     */
    public function test_a6_available_advance_does_not_throw_without_a_company_context(): void
    {
        $this->pureAdvancePayment('1000.000');

        app(CompanyContext::class)->clear();

        self::assertSame(
            '1000.000',
            app(GeneralLedgerService::class)
                ->availableCustomerAdvance($this->company->id, $this->partner->id, 'TND'),
            'a bare no-arg getScale() would throw here — that was I-2',
        );
    }

    // ------------------------------------------------------------- helpers

    /** @return array{payments: int, allocations: int, entries: int, movements: int} */
    private function snapshot(): array
    {
        return [
            'payments' => Payment::query()->count(),
            'allocations' => PaymentAllocation::query()->count(),
            'entries' => JournalEntry::query()->where('company_id', $this->company->id)->count(),
            'movements' => DB::table('repository_movements')->count(),
        ];
    }

    /**
     * §12.5's mandated fail-closed assertion set. A refusal that wrote half its
     * effects would be worse than no refusal at all.
     *
     * @param  array{payments: int, allocations: int, entries: int, movements: int}  $before
     */
    private function assertWroteNothing(array $before, Payment $original): void
    {
        $after = $this->snapshot();

        self::assertSame($before['payments'], $after['payments'], 'no payment row written');
        self::assertSame($before['allocations'], $after['allocations'], 'no allocation written');
        self::assertSame($before['entries'], $after['entries'], 'no journal entry posted');
        self::assertSame($before['movements'], $after['movements'], 'no cash movement recorded');
        self::assertSame(
            PaymentStatus::Completed,
            $original->fresh()?->status,
            'the original stays Completed',
        );
        self::assertSame(
            0,
            Payment::query()
                ->where('original_payment_id', $original->id)
                ->where('payment_type', PaymentType::Reversal->value)
                ->count(),
            'no reversing document was written',
        );
    }

    private function postedInvoice(string $total): Document
    {
        return Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.Str::random(8),
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'TND',
        ]);
    }

    private function paymentRow(string $amount, PaymentType $type): Payment
    {
        return Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => $type,
            'reference' => 'PMT-'.Str::random(8),
            'created_by' => $this->user->id,
        ]);
    }

    private function pureAdvancePayment(string $amount): Payment
    {
        $payment = $this->paymentRow($amount, PaymentType::Advance);

        $entry = DB::transaction(fn () => app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: $payment->id,
            amount: $amount,
            paymentMethodAccountId: (string) $this->repository->gl_account_id,
            date: now(),
            user: $this->user,
            description: 'Advance received',
            currencyCode: 'TND',
            mode: PostingMode::SynchronousInTransaction,
        ));

        $payment->journal_entry_id = $entry->id;
        $payment->save();
        $this->fundRepository($amount);

        return $payment;
    }

    /**
     * @param  list<array{document_id: string, amount: string}>  $allocations
     */
    private function payViaApi(string $amount, array $allocations): Payment
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-'.Str::random(8),
            'allocations' => $allocations,
        ]);
        $response->assertCreated();

        /** @var string $paymentId */
        $paymentId = $response->json('data.id');

        return Payment::query()->findOrFail($paymentId);
    }

    private function fundRepository(string $amount): void
    {
        DB::transaction(fn () => app(TreasuryMovementServiceInterface::class)->record(new MovementIntent(
            repositoryId: $this->repository->id,
            tenantId: $this->repository->tenant_id,
            companyId: $this->repository->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $this->repository->currency,
            sourceType: MovementSourceType::OpeningBalance,
            sourceId: $this->repository->id,
            idempotencyLeg: 'opening-'.Str::random(6),
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: 'refusal fixture opening balance',
            allowWhileFrozen: false,
        )));
        $this->repository->refresh();
    }

    /**
     * `clearCustomerAdvanceToReceivable()` posts `AfterCommit`, which never fires
     * under `RefreshDatabase`. Seal the resulting Drafts so the partner balance
     * (which counts POSTED only) reflects the consumption.
     */
    private function postPendingEntries(): void
    {
        $drafts = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('status', JournalEntryStatus::Draft->value)
            ->where('source_type', 'prepayment_application')
            ->get();

        DB::transaction(function () use ($drafts): void {
            foreach ($drafts as $draft) {
                app(GeneralLedgerService::class)->postEntryNow($draft, $this->user, 'TND');
            }
        });
    }

    private function rawPostedEntry(string $sourceType, string $sourceId, string $amount): string
    {
        return $this->rawEntry($sourceType, $sourceId, $amount, JournalEntryStatus::Posted);
    }

    private function rawEntry(
        string $sourceType,
        string $sourceId,
        string $amount,
        JournalEntryStatus $status,
    ): string {
        return DB::transaction(function () use ($sourceType, $sourceId, $amount, $status): string {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'entry_number' => 'RAW-'.Str::random(8),
                'entry_date' => now(),
                'description' => 'fixture '.$sourceType,
                'status' => JournalEntryStatus::Draft,
                'source_type' => $sourceType,
                'journal_code' => JournalCode::fromSourceType($sourceType),
                'source_id' => $sourceId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => (string) $this->repository->gl_account_id,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'fixture debit',
                'line_order' => 0,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => Account::findByPurposeOrFail(
                    $this->company->id,
                    $sourceType === 'advance'
                        ? SystemAccountPurpose::CustomerAdvance
                        : SystemAccountPurpose::CustomerReceivable,
                )->id,
                'partner_id' => $this->partner->id,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'fixture credit',
                'line_order' => 1,
            ]);

            if ($status === JournalEntryStatus::Posted) {
                app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');
            }

            return $entry->id;
        });
    }

    /** A `prepayment_application` DEBIT against the advance account. */
    private function rawEntryWithAdvanceDebit(string $amount, JournalEntryStatus $status): void
    {
        DB::transaction(function () use ($amount, $status): void {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'entry_number' => 'PREP-'.Str::random(8),
                'entry_date' => now(),
                'description' => 'in-flight prepayment application',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'prepayment_application',
                'journal_code' => JournalCode::fromSourceType('prepayment_application'),
                'source_id' => Str::uuid()->toString(),
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => Account::findByPurposeOrFail(
                    $this->company->id,
                    SystemAccountPurpose::CustomerAdvance,
                )->id,
                'partner_id' => $this->partner->id,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'clear customer advance',
                'line_order' => 0,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => Account::findByPurposeOrFail(
                    $this->company->id,
                    SystemAccountPurpose::CustomerReceivable,
                )->id,
                'partner_id' => $this->partner->id,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'prepayment applied',
                'line_order' => 1,
            ]);

            if ($status === JournalEntryStatus::Posted) {
                app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');
            }
        });
    }
}
