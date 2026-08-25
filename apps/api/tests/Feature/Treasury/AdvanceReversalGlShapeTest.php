<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\DTOs\PosRevenueVatSplit;
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
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\CancellationShape;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
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
 * DPA `DPA-REV2-A` / task A1 — the red tests that pin every wrong GL shape the
 * customer-advance reversal lane exists to fix.
 *
 * Every assertion in this file targets **posted journal LINES** — the account a
 * line hits (resolved by `SystemAccountPurpose`, never by `line_order`), the side
 * it hits it on, the amount, and the `partner_id` tag. Asserting that a call did
 * not throw proves nothing here: every one of these defects is *silently* wrong
 * money.
 *
 * **On "green for the wrong reason".** The plan expects A1c/A1d/A1f to be GREEN
 * today (masked by the D-6 `payment_type` gate) and to go red when A7 lifts it.
 * Written that way they would be green only because NOTHING is posted at all —
 * precisely the vacuum the plan warns about, and §12.1 rules that a test which
 * cannot be made red is not evidence. Each therefore asserts the REQUIRED end
 * state unconditionally and fails with a message naming which stage is blocking:
 * the D-6 gate now, the GL shape after A7, green only once A9 + A7 are both in.
 * Strictly stronger than the plan's construction; recorded in the task report.
 *
 * @see plan-advance-reversal.md §1.2 (C-2), §1.3 (shape X), §1.4 (shape Y),
 *      §1.5 (shape Z), A-D2 (the ledger partition is the sole shape selector)
 */
final class AdvanceReversalGlShapeTest extends TestCase
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
            'name' => 'Advance Reversal Tenant',
            'slug' => 'advrev-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // TN/TND deliberately: the Tunisia chart seeds `CustomerAdvance` (419)
        // with its `system_purpose`, so this file tests the GL SHAPE rather than
        // the France chart gap (§1.6), which A2/A3 own.
        $this->company = Company::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Advance Reversal Co',
            'legal_name' => 'Advance Reversal Co SARL',
            'tax_id' => 'TAX-ADVREV',
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
            'name' => 'Advance Reversal User',
            'email' => 'advrev-'.Str::random(6).'@example.com',
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
            'gl_account_id' => $this->accountId(SystemAccountPurpose::Bank),
            'account_id' => null,
            'is_active' => true,
        ]);

        $this->partner = Partner::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Advance Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    /**
     * A1a — **C-2, the live V4 mixed-payment defect** (§1.2).
     *
     * 1000 paid / 700 allocated to an invoice / 300 excess. `store()` posts TWO
     * entries against the payment — `Cr CustomerReceivable 700`
     * (`source_type='customer_payment'`) and `Cr CustomerAdvance 300`
     * (`source_type='advance'`) — and deliberately KEEPS the type as
     * `DocumentPayment` (`PaymentController.php:1186-1188`), so the D-6 gate
     * passes and the cash branch hands the FULL 1000 to the AR builder.
     *
     * Today: one entry, `Dr CustomerReceivable 1000` — AR overstated by 300 and a
     * 300 `CustomerAdvance` liability left standing.
     * Required: `Dr CustomerReceivable 700` + `Dr CustomerAdvance 300`, both
     * partner-tagged, against a single `Cr` of 1000 on the till's GL account.
     */
    public function test_a1a_a_mixed_payment_reversal_splits_ar_and_customer_advance(): void
    {
        $invoice = $this->postedInvoice('700.000');

        $payment = $this->payViaApi('1000.000', [
            ['document_id' => $invoice->id, 'amount' => '700.000'],
        ]);

        // Pin the ORIGINAL footprint first: without this the test could go green
        // on a fixture that never produced a mixed payment at all.
        self::assertSame(
            '700.000',
            $this->creditByPurpose($payment->id, 'customer_payment', SystemAccountPurpose::CustomerReceivable),
            'fixture: the original must credit AR for the allocated portion',
        );
        self::assertSame(
            '300.000',
            $this->creditByPurpose($payment->id, 'advance', SystemAccountPurpose::CustomerAdvance),
            'fixture: the original must credit CustomerAdvance for the excess',
        );

        $reversal = $this->refundService->reversePayment($payment, 'A1a mixed reversal', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $debits = $this->postedDebitsByPurpose($reversal->id);

        self::assertSame(
            '700.000',
            $debits[SystemAccountPurpose::CustomerReceivable->value] ?? '0.000',
            'the receivable may only be restored for the ALLOCATED portion — '
            .'debiting the full 1000 overstates AR by the 300 that was never a receivable',
        );
        self::assertSame(
            '300.000',
            $debits[SystemAccountPurpose::CustomerAdvance->value] ?? '0.000',
            'the excess portion must unwind the CustomerAdvance liability it created; '
            .'leaving it standing is the other half of C-2',
        );

        // The partner subledger must carry both debits — an untagged restoration
        // reconciles at the control account and silently breaks the subledger.
        foreach ([SystemAccountPurpose::CustomerReceivable, SystemAccountPurpose::CustomerAdvance] as $purpose) {
            self::assertSame(
                $this->partner->id,
                $this->soleDebitLine($reversal->id, $purpose)->partner_id,
                "the {$purpose->value} debit must be partner-tagged",
            );
        }

        // Exactly one cash credit, for the whole net — two entries, one movement.
        self::assertSame(
            '1000.000',
            $this->postedCreditsByPurpose($reversal->id)[SystemAccountPurpose::Bank->value] ?? '0.000',
            'the cash side stays a single credit for the full net unreversed amount',
        );
    }

    /**
     * A1b — **INVERTED BY A7, as planned.** This test used to pin the refusal
     * (`'customer-advance reversal is not implemented (ticket DPA-V4-ADV-1)'`).
     * A7 mapped `PaymentType::Advance` to `ReversalSupport::CashReversal`, so a
     * pure customer advance must now REVERSE — through
     * `reverseCustomerAdvanceJournalEntry()`, unwinding the liability it created
     * rather than restoring a receivable that never existed.
     *
     * The inversion is a deliberate, visible edit precisely because it was pinned
     * first: the diff shows a refusal becoming a success, which is what a
     * reviewer needs to see.
     */
    public function test_a1b_a_pure_customer_advance_now_reverses_against_the_advance_account(): void
    {
        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '400.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Advance,
            'reference' => 'ADV-'.Str::random(8),
            'created_by' => $this->user->id,
        ]);

        // The advance footprint the reversal must unwind.
        $entry = DB::transaction(fn () => app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: $payment->id,
            amount: '400.000',
            paymentMethodAccountId: $this->accountId(SystemAccountPurpose::Bank),
            date: now(),
            user: $this->user,
            description: 'Pure customer advance',
            currencyCode: 'TND',
            mode: PostingMode::SynchronousInTransaction,
        ));
        $payment->journal_entry_id = $entry->id;
        $payment->save();
        $this->fundRepository('400.000');

        $reversal = $this->refundService->reversePayment($payment, 'A1b pure advance', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $debits = $this->postedDebitsByPurpose($reversal->id);
        $actual = json_encode($debits, JSON_THROW_ON_ERROR);

        self::assertSame(
            '400.000',
            $debits[SystemAccountPurpose::CustomerAdvance->value] ?? '0.000',
            "a pure advance unwinds the LIABILITY it created. Posted debits: {$actual}",
        );
        self::assertArrayNotHasKey(
            SystemAccountPurpose::CustomerReceivable->value,
            $debits,
            'no receivable was ever credited, so none may be restored — this is the wrong shape the '
            ."old refusal existed to avoid, now avoided by posting the RIGHT one. Posted debits: {$actual}",
        );
        self::assertSame(PaymentStatus::Reversed, $payment->fresh()?->status);
    }

    /**
     * A1c — **a DEFERRED pure advance posts the wrong cancellation shape**
     * (A-D7). A cheque advance mints its instrument at the full payment amount
     * BEFORE the payment is typed (`PaymentController.php:846` vs `:888-890`),
     * the advance JE debits the PORTFOLIO (`:1163-1165`), and
     * `payments.journal_entry_id` is set to it (`:1192-1196`) — so
     * `performCancellation()`'s `journal_entry_id !== null` gate passes and it
     * posts `Dr CustomerReceivable / Cr portfolio` for money that credited
     * `CustomerAdvance`.
     *
     * The D-6 gate is the ONLY thing keeping this unreachable today, and this
     * lane removes it (A7).
     *
     * DEVIATION FROM THE PLAN, deliberate and recorded: the plan expects A1c to
     * be GREEN today and to go red when A7 lands. Written that way it would be
     * green only because nothing is posted at all — the "green for the wrong
     * reason" the plan itself warns about, and §12.1 says a test that cannot be
     * made red is not evidence. It therefore asserts the REQUIRED end state
     * unconditionally: red now for the gate, red after A7 for the shape, green
     * only once A9 + A7 are both in. Strictly stronger, never green-by-vacuum.
     */
    public function test_a1c_a_deferred_pure_advance_cancels_against_customer_advance(): void
    {
        $chequeMethod = $this->chequeMethod();
        $portfolioAccountId = $this->instrumentAccountId(InstrumentAccountPurpose::ChecksToCollect);

        $instrument = app(InstrumentLifecycleService::class)->receive(new ReceiveInstrumentData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            paymentMethodId: $chequeMethod->id,
            kind: InstrumentKind::Cheque,
            direction: InstrumentDirection::Inbound,
            origin: InstrumentOrigin::Web,
            reference: 'ADV-CH-1',
            amount: '500.000',
            currency: 'TND',
            repositoryId: $this->repository->id,
            partnerId: $this->partner->id,
            createdBy: $this->user->id,
        ));

        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $chequeMethod->id,
            'instrument_id' => $instrument->id,
            'repository_id' => $this->repository->id,
            'amount' => '500.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Advance,
            'origin' => PaymentOrigin::WebAdmin,
            'reference' => 'ADV-CH-'.Str::random(6),
            'created_by' => $this->user->id,
        ]);

        $entry = DB::transaction(fn () => app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: $payment->id,
            amount: '500.000',
            paymentMethodAccountId: $portfolioAccountId,
            date: now(),
            user: $this->user,
            description: 'Deferred customer advance on cheque',
            currencyCode: 'TND',
            mode: PostingMode::SynchronousInTransaction,
        ));

        $payment->journal_entry_id = $entry->id;
        $payment->save();
        $instrument->payment_id = $payment->id;
        $instrument->save();

        self::assertSame(
            '500.000',
            $this->creditByPurpose($payment->id, 'advance', SystemAccountPurpose::CustomerAdvance),
            'fixture: the deferred advance is advance-backed against the portfolio',
        );

        try {
            $this->refundService->reversePayment($payment, 'A1c deferred advance', $this->user->id);
        } catch (\DomainException $exception) {
            self::fail(
                'blocked by the D-6 gate, which A7 lifts; after A7 this must post the CustomerAdvance '
                .'cancellation shape rather than the AR one. Gate said: '.$exception->getMessage(),
            );
        }

        $debits = $this->postedDebitsByPurposeForSource($instrument->id, 'instrument');
        $actual = json_encode($debits, JSON_THROW_ON_ERROR);

        self::assertSame(
            '500.000',
            $debits[SystemAccountPurpose::CustomerAdvance->value] ?? '0.000',
            "cancelling a deferred ADVANCE must unwind the advance liability. Posted debits: {$actual}",
        );
        self::assertArrayNotHasKey(
            SystemAccountPurpose::CustomerReceivable->value,
            $debits,
            "no receivable was ever credited, so none may be restored. Posted debits: {$actual}",
        );
    }

    /**
     * A1d — **counter-shape X** (§1.3): `payment_type = Advance` carrying
     * **AR-backed** GL. `storeMultiple()` types a line purely on allocation
     * exhaustion (`PaymentController.php:1487-1490`), then the manual excess arm
     * posts `createPostedExcessAllocationJournalEntry()` (`:1696`) →
     * `createPaymentReceivedJournalEntry` (`Cr CustomerReceivable`,
     * `source_type='customer_payment'`) onto that same line.
     *
     * So `payment_type = Advance`, `arBacked > 0`, `advanceBacked = 0`. Under a
     * `payment_type`-driven selector the advance builder would fire against an
     * original that credited AR — the exact mirror of C-2, which is why A-D2
     * makes the LEDGER the sole selector. This test is what would have caught it.
     *
     * Same construction note as A1c: asserts the required end state rather than
     * relying on today's D-6 refusal to make it vacuously green.
     */
    public function test_a1d_shape_x_an_advance_typed_line_with_ar_backed_gl_reverses_to_ar(): void
    {
        $primary = $this->postedInvoice('1190.000');
        $secondary = $this->postedInvoice('200.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'document_id' => $primary->id,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'repository_id' => $this->repository->id, 'amount' => '1190.000'],
                ['payment_method_id' => $this->cashMethod->id, 'repository_id' => $this->repository->id, 'amount' => '200.000'],
            ],
            'excess_allocation_method' => 'manual',
            'excess_allocations' => [
                ['document_id' => $secondary->id, 'amount' => '200.000'],
            ],
        ]);
        $response->assertCreated();

        /** @var Payment $advanceTyped */
        $advanceTyped = Payment::query()
            ->where('company_id', $this->company->id)
            ->where('payment_type', PaymentType::Advance->value)
            ->firstOrFail();

        // Fixture pins: this is the counter-shape, not an ordinary advance.
        self::assertSame(
            '200.000',
            $this->creditByPurpose($advanceTyped->id, 'customer_payment', SystemAccountPurpose::CustomerReceivable),
            'fixture: shape X is an Advance-typed line whose GL credits AR',
        );
        self::assertSame(
            '0.000',
            $this->creditByPurpose($advanceTyped->id, 'advance', SystemAccountPurpose::CustomerAdvance),
            'fixture: shape X has NO advance-backed GL',
        );

        try {
            $this->refundService->reversePayment($advanceTyped, 'A1d shape X', $this->user->id);
        } catch (\DomainException $exception) {
            self::fail(
                'blocked by the D-6 gate, which A7 lifts; after A7 this must debit AR (the ledger, '
                .'not payment_type, decides the shape). Gate said: '.$exception->getMessage(),
            );
        }

        $reversal = Payment::query()
            ->where('original_payment_id', $advanceTyped->id)
            ->where('payment_type', PaymentType::Reversal->value)
            ->firstOrFail();

        $debits = $this->postedDebitsByPurpose($reversal->id);
        $actual = json_encode($debits, JSON_THROW_ON_ERROR);

        self::assertSame(
            '200.000',
            $debits[SystemAccountPurpose::CustomerReceivable->value] ?? '0.000',
            "an Advance-TYPED line whose ledger credited AR must reverse to AR. Posted debits: {$actual}",
        );
        self::assertArrayNotHasKey(
            SystemAccountPurpose::CustomerAdvance->value,
            $debits,
            'no CustomerAdvance liability was ever created, so none may be unwound — selecting the '
            ."shape from payment_type would post exactly this wrong debit. Posted debits: {$actual}",
        );
    }

    /**
     * A1e — **counter-shape Y** (§1.4): `payment_type = DocumentPayment` carrying
     * **advance-only** GL. **RED today for the real reason — C-2 at 100%.**
     *
     * `PaymentAllocationService` writes allocation rows for `SalesOrder`
     * documents, books the order portion through
     * `createCustomerAdvanceJournalEntry(advanceId: $payment->id, …)` (`:315-327`),
     * and flips the type to `Advance` only when `$totalAllocated` is zero
     * (`:381-384`) — which order allocations defeat. So `arBacked = 0`,
     * `advanceBacked = full`, `payment_type = DocumentPayment` → today's cash
     * branch hands the whole net to the AR builder.
     *
     * **No belt catches this** (A-D4b / gate finding N-2): the coverage assert
     * sees `0 + full == full` and passes, and the non-emptiness assert never
     * fires because the partition is non-empty. A-D2's selector is the entire
     * defence, and it works by making Y CORRECT rather than by refusing it.
     *
     * The payment over-pays the order (1200 against 1000) so the type flip is
     * DEFEATED rather than merely unreached: `$totalAllocated = 1000 ≠ 0`.
     * `cashAccountOverrideId` is supplied to force `SynchronousInTransaction`
     * posting (`:328-330`) — under `RefreshDatabase` an `AfterCommit` entry would
     * still be Draft, and the partition counts POSTED only (A-D4).
     */
    public function test_a1e_shape_y_an_order_allocated_payment_reverses_to_customer_advance(): void
    {
        $order = Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'document_number' => 'SO-'.Str::random(8),
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '1000.000',
            'tax_amount' => '0.000',
            'total' => '1000.000',
            'balance_due' => '1000.000',
            'currency' => 'TND',
        ]);

        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '1200.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'SO-PAY-'.Str::random(6),
            'created_by' => $this->user->id,
        ]);

        // The payment row is built directly (the allocation service is the unit
        // under test, not `store()`), so no cash movement IN ever happened. Fund
        // the till through the port so the reversal's movement OUT is legitimate
        // — `balance` is port-managed and not fillable.
        $this->fundRepository('1200.000');

        app(PaymentAllocationService::class)->applyAllocationFromCommand(new ApplyPaymentAllocationCommand(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            paymentId: $payment->id,
            allocationMethod: AllocationMethod::MANUAL,
            actorUserId: $this->user->id,
            source: 'test:dpa-advrev-shape-y',
            manualAllocations: [['document_id' => $order->id, 'amount' => '1000.000']],
            cashAccountOverrideId: $this->repository->gl_account_id,
        ));

        $payment->refresh();

        // Fixture pins: the type flip really is defeated, and the GL really is
        // advance-only. Without these the red below could be a broken fixture.
        self::assertSame(
            PaymentType::DocumentPayment,
            $payment->payment_type,
            'fixture: an order allocation defeats the flip to Advance (PAS:381-384)',
        );
        self::assertSame(
            '1200.000',
            $this->creditByPurpose($payment->id, 'advance', SystemAccountPurpose::CustomerAdvance),
            'fixture: order portion + excess both credit CustomerAdvance',
        );
        self::assertSame(
            '0.000',
            $this->creditByPurpose($payment->id, 'customer_payment', SystemAccountPurpose::CustomerReceivable),
            'fixture: shape Y has ZERO AR-backed GL',
        );

        $reversal = $this->refundService->reversePayment($payment, 'A1e shape Y', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $debits = $this->postedDebitsByPurpose($reversal->id);
        $actual = json_encode($debits, JSON_THROW_ON_ERROR);

        self::assertSame(
            '1200.000',
            $debits[SystemAccountPurpose::CustomerAdvance->value] ?? '0.000',
            "the whole net is advance-backed, so the whole net unwinds the advance. Posted debits: {$actual}",
        );
        self::assertArrayNotHasKey(
            SystemAccountPurpose::CustomerReceivable->value,
            $debits,
            'no receivable was ever credited — debiting AR here is C-2 at 100% rather than 30%. '
            ."Posted debits: {$actual}",
        );
    }

    /**
     * A1f — **a MIXED deferred tender** (A-D7): one cheque, two GL shapes. The
     * AR leg (`PaymentController.php:1082-1084`, `:1114-1124`) and the advance leg
     * (`:1163-1165`, `:1170-1181`) both debit the SAME portfolio account, and
     * `:1186-1189` keeps the type `DocumentPayment`. One instrument, minted at the
     * FULL payment amount, carrying a footprint no single `CancellationShape`
     * enum case could represent — which is why A-D7 makes the cancellation ENTRY
     * partition-driven instead of adding a third case.
     *
     * The fixture shape is the one already pinned green by
     * `DeferredTenderPaymentTest::test_cheque_excess_posts_both_payment_and_advance_debits_to_portfolio`.
     *
     * Same construction note as A1c/A1d.
     */
    public function test_a1f_a_mixed_deferred_tender_cancels_with_two_partition_driven_debits(): void
    {
        $invoice = $this->postedInvoice('100.000');
        $chequeMethod = $this->chequeMethod();

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $chequeMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '110.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [['document_id' => $invoice->id, 'amount' => '100.000']],
            'instrument' => ['reference' => 'CH-MIXED-110'],
        ]);
        $response->assertCreated();

        /** @var string $paymentId */
        $paymentId = $response->json('data.id');
        $payment = Payment::query()->findOrFail($paymentId);

        self::assertSame(PaymentType::DocumentPayment, $payment->payment_type, 'fixture: type stays DocumentPayment');
        self::assertSame(
            '100.000',
            $this->creditByPurpose($payment->id, 'customer_payment', SystemAccountPurpose::CustomerReceivable),
            'fixture: the allocated portion credits AR against the portfolio',
        );
        self::assertSame(
            '10.000',
            $this->creditByPurpose($payment->id, 'advance', SystemAccountPurpose::CustomerAdvance),
            'fixture: the excess credits CustomerAdvance against the SAME portfolio',
        );

        /** @var string $instrumentId */
        $instrumentId = $payment->instrument_id;
        self::assertNotNull($instrumentId, 'fixture: the cheque minted an instrument');

        $this->refundService->reversePayment($payment, 'A1f mixed deferred tender', $this->user->id);

        $debits = $this->postedDebitsByPurposeForSource($instrumentId, 'instrument');
        $actual = json_encode($debits, JSON_THROW_ON_ERROR);

        self::assertSame(
            '100.000',
            $debits[SystemAccountPurpose::CustomerReceivable->value] ?? '0.000',
            "the cancellation entry must restore only the AR-backed portion. Posted debits: {$actual}",
        );
        self::assertSame(
            '10.000',
            $debits[SystemAccountPurpose::CustomerAdvance->value] ?? '0.000',
            "and unwind the advance-backed portion separately. Posted debits: {$actual}",
        );
        self::assertCount(
            2,
            $debits,
            "exactly two debit lines, summing to the instrument nominal of 110.000. Posted debits: {$actual}",
        );
    }

    /**
     * A1g — **counter-shape Z** (§1.5, gate finding N-1). **RED ON `dev` TODAY.**
     *
     * The POS *account-payment* bridge mints a payment that is simultaneously
     * `origin = Pos`, `payment_type = DocumentPayment` and instrument-linked
     * (`TreasuryAccountPaymentBridge.php:98`, `:154-158`, `:161-182`, `:185-197`),
     * settled by a real inbound `Received` cheque instrument
     * (`HandlesMaturityTenderLeg.php:76-93`). Its GL footprint is **AR-backed**:
     * the allocation posts `createPaymentReceivedJournalEntry` debiting the
     * PORTFOLIO account (`PaymentAllocationService.php:284-303`).
     *
     * Reversing it takes `DocumentPayment` → `CashReversal` → the D-6 gate passes
     * → `resolveInstrumentForReversal()` → `Received` →
     * `cancelForPaymentReversal()`, which selects the cancellation shape from
     * `$payment->origin` (`InstrumentLifecycleService.php:744-746`) and therefore
     * posts **`Dr ProductRevenue` / `Cr` portfolio with `partner_id` null**
     * (`GeneralLedgerService.php:3131-3136`, `:3151`).
     *
     * Revenue is debited for money never booked to revenue, the receivable is
     * left standing, and the partner tag is lost. This needs no enum change to
     * mis-post, so it is a LIVE pre-existing defect, closed by A9 — which is why
     * the §9 promotion freeze covers it.
     *
     * The instrument amount equals the payment amount by construction
     * (`TreasuryAccountPaymentBridge.php:283` vs `:174`), so D-5b's belt
     * (`PaymentRefundService.php:1380-1387`) ADMITS this shape rather than
     * blocking it — the fixture asserts that too.
     */
    public function test_a1g_a_pos_account_payment_on_a_cheque_reverses_to_ar_not_revenue(): void
    {
        $invoice = $this->postedInvoice('250.000');
        $chequeMethod = $this->chequeMethod();
        $portfolioAccountId = $this->instrumentAccountId(InstrumentAccountPurpose::ChecksToCollect);

        $instrument = app(InstrumentLifecycleService::class)->receive(new ReceiveInstrumentData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            paymentMethodId: $chequeMethod->id,
            kind: InstrumentKind::Cheque,
            direction: InstrumentDirection::Inbound,
            origin: InstrumentOrigin::Pos,
            reference: 'POS-ACCTPAY-CH-1',
            amount: '250.000',
            currency: 'TND',
            repositoryId: $this->repository->id,
            partnerId: $this->partner->id,
            createdBy: $this->user->id,
        ));

        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $chequeMethod->id,
            'instrument_id' => $instrument->id,
            'repository_id' => $this->repository->id,
            'amount' => '250.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::Pos,
            'reference' => 'POS Account Payment '.Str::uuid()->toString(),
            'created_by' => $this->user->id,
        ]);

        PaymentAllocation::query()->create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '250.000',
        ]);

        // The AR-backed footprint the allocation service posts for a maturity-leg
        // account payment: Dr portfolio (cashAccountOverrideId) / Cr AR.
        $entry = DB::transaction(fn () => app(GeneralLedgerService::class)->createPaymentReceivedJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentId: $payment->id,
            amount: '250.000',
            paymentMethodAccountId: $portfolioAccountId,
            date: now(),
            description: 'POS account payment on cheque',
            user: $this->user,
            currencyCode: 'TND',
            mode: PostingMode::SynchronousInTransaction,
        ));

        $payment->journal_entry_id = $entry->id;
        $payment->save();
        $instrument->payment_id = $payment->id;
        $instrument->save();

        // Fixture pins, so a green/red here can only be about the reversal shape.
        self::assertSame(
            '250.000',
            $this->creditByPurpose($payment->id, 'customer_payment', SystemAccountPurpose::CustomerReceivable),
            'fixture: shape Z is AR-backed — the partition is NOT empty',
        );
        self::assertSame(InstrumentStatus::Received, $instrument->fresh()?->status);

        $reversal = $this->refundService->reversePayment($payment, 'A1g shape Z', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        // The cancellation entry is keyed on the INSTRUMENT, not the payment.
        $debits = $this->postedDebitsByPurposeForSource($instrument->id, 'instrument');

        $actual = json_encode($debits, JSON_THROW_ON_ERROR);

        self::assertSame(
            '250.000',
            $debits[SystemAccountPurpose::CustomerReceivable->value] ?? '0.000',
            'a POS ACCOUNT PAYMENT is by definition on account: cancelling its instrument must '
            ."restore the RECEIVABLE, not debit revenue that was never credited. Posted debits: {$actual}",
        );
        self::assertArrayNotHasKey(
            SystemAccountPurpose::ProductRevenue->value,
            $debits,
            'ProductRevenue must not be debited — no revenue was ever booked for an account payment. '
            ."Posted debits: {$actual}",
        );
        self::assertSame(
            $this->partner->id,
            $this->soleDebitLineForSource($instrument->id, 'instrument', SystemAccountPurpose::CustomerReceivable)->partner_id,
            'the restored receivable must keep its partner tag; PosRevenue drops it (GLS:3151)',
        );
    }

    /**
     * A1g, NEGATIVE HALF — the empty-partition fallback must keep every case it
     * legitimately owns: a pure POS **sale receipt** books revenue directly
     * (`createPOSPaymentEntry` → `source_type='pos_receipt'`), so its partition
     * reads empty and its cancellation stays `Dr ProductRevenue`, `partner_id`
     * null. Without this half, A9 could over-narrow `PosRevenue` out of existence
     * undetected.
     *
     * DEVIATION FROM THE PLAN, recorded deliberately (see task report):
     * the plan assumes the sale receipt reaches `PosRevenue` through
     * `cancelForPaymentReversal()`'s `origin` fallback. It cannot — a sale-receipt
     * payment is `PaymentType::POS` (`TreasuryReceiptBridge.php:1336`), which the
     * D-6 gate refuses BEFORE any instrument is resolved. The sale receipt reaches
     * `PosRevenue` through the POS void lane, which calls
     * `InstrumentLifecycleService::cancel(..., CancellationShape::PosRevenue)`
     * explicitly (`TreasuryReceiptBridge.php:1502-1509`). That is the path pinned
     * here, because it is the one that exists.
     */
    public function test_a1g_negative_a_pure_pos_sale_receipt_still_cancels_to_product_revenue(): void
    {
        $chequeMethod = $this->chequeMethod();
        $portfolioAccountId = $this->instrumentAccountId(InstrumentAccountPurpose::ChecksToCollect);

        $instrument = app(InstrumentLifecycleService::class)->receive(new ReceiveInstrumentData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            paymentMethodId: $chequeMethod->id,
            kind: InstrumentKind::Cheque,
            direction: InstrumentDirection::Inbound,
            origin: InstrumentOrigin::Pos,
            reference: 'POS-SALE-CH-1',
            amount: '80.000',
            currency: 'TND',
            repositoryId: $this->repository->id,
            partnerId: null,
            createdBy: $this->user->id,
        ));

        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => null,
            'payment_method_id' => $chequeMethod->id,
            'instrument_id' => $instrument->id,
            'repository_id' => $this->repository->id,
            'amount' => '80.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::POS,
            'origin' => PaymentOrigin::Pos,
            'reference' => 'POS Sale '.Str::uuid()->toString(),
            'created_by' => $this->user->id,
        ]);

        // The direct-to-revenue footprint of a POS sale settled by cheque:
        // Dr portfolio / Cr ProductRevenue, keyed on the RECEIPT — never on the
        // payment — which is exactly why the partition reads empty.
        $receiptId = Str::uuid()->toString();
        $entryId = DB::transaction(function () use ($receiptId, $portfolioAccountId): string {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'entry_number' => 'POS-'.Str::random(8),
                'entry_date' => now(),
                'description' => 'POS Receipt sale settled by cheque',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'pos_receipt',
                'journal_code' => JournalCode::fromSourceType('pos_receipt'),
                'source_id' => $receiptId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $portfolioAccountId,
                'partner_id' => null,
                'debit' => '80.000',
                'credit' => '0',
                'description' => 'POS payment via cheque portfolio',
                'line_order' => 0,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $this->accountId(SystemAccountPurpose::ProductRevenue),
                'partner_id' => null,
                'debit' => '0',
                'credit' => '80.000',
                'description' => 'POS sales revenue',
                'line_order' => 1,
            ]);
            app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');

            return $entry->id;
        });

        $payment->journal_entry_id = $entryId;
        $payment->save();
        $instrument->payment_id = $payment->id;
        $instrument->save();

        // W4-9 gate r1 (F-1): the PosRevenue shape now REQUIRES the sale's
        // revenue/VAT decomposition, because a POS sale recognises revenue net
        // with its output VAT on 4457. The synthetic sale above is VAT-free
        // (Cr ProductRevenue 80.000, no 4457 line), so the VAT-free split is the
        // faithful one — and the shape assertion below is unchanged by it: a
        // VAT-free sale still reverses one Dr ProductRevenue at the full amount.
        app(InstrumentLifecycleService::class)->cancel(
            $instrument->id,
            $this->user->id,
            'POS refund/void',
            CancellationShape::PosRevenue,
            PosRevenueVatSplit::vatFree('80.000', 3),
        );

        $debits = $this->postedDebitsByPurposeForSource($instrument->id, 'instrument');

        self::assertSame(
            '80.000',
            $debits[SystemAccountPurpose::ProductRevenue->value] ?? '0.000',
            'a pure POS sale receipt has an EMPTY partition and must keep the PosRevenue shape',
        );
        self::assertArrayNotHasKey(
            SystemAccountPurpose::CustomerReceivable->value,
            $debits,
            'a POS sale that was never on account must not restore a receivable',
        );
        self::assertNull(
            $this->soleDebitLineForSource($instrument->id, 'instrument', SystemAccountPurpose::ProductRevenue)->partner_id,
            'the PosRevenue debit carries no partner tag (GLS:3151)',
        );
    }

    /**
     * **C-A — the code gate's Critical (fix round 1).** The reversing GL must
     * equal the amount ACTUALLY BEING UNWOUND, and the document, the cash
     * movement and the GL entry must all carry THE SAME value (the V3
     * one-value-three-artifacts lesson).
     *
     * The gate proved on real PostgreSQL, both directions, that fix round 0
     * regressed this: the partition buckets are pinned to the GROSS original
     * amount by belt 1 itself, so a reversal after a PARTIAL REFUND posted
     * `Dr AR 1000` against a document of `-600` and a movement of `600`.
     * A-D6 did not cover it — it keys on `advanceBacked > 0`, and the exposed
     * window is the plain document-payment reversal (`advanceBacked == 0`).
     *
     * Consequences were all silent: AR over-stated by the refunded amount
     * (1400 debited against 1000 credited), a permanent Treasury↔GL divergence
     * surfacing at Wave-F reconcile, and a cash-movements report over-stating
     * cash out.
     *
     * **The plan prescribed this** (A-D2 says `amount: $arBacked`; A-D6's "with
     * no prior refunds" premise is stated but never enforced on the pure-AR
     * arm), so an ERRATUM is recorded against A-D2/A-D6 in the plan.
     */
    public function test_ca_a_reversal_after_a_partial_refund_posts_the_net_everywhere(): void
    {
        $invoice = $this->postedInvoice('1000.000');
        $payment = $this->payViaApi('1000.000', [
            ['document_id' => $invoice->id, 'amount' => '1000.000'],
        ]);

        self::assertSame(
            '1000.000',
            $this->creditByPurpose($payment->id, 'customer_payment', SystemAccountPurpose::CustomerReceivable),
            'fixture: the original credits AR for the full amount',
        );

        // The partial refund already debits AR 400 through postRefundGlAndMovement().
        $this->refundService->partialRefund($payment, '400.000', 'partial refund', $this->user->id);

        $reversal = $this->refundService->reversePayment($payment, 'C-A reversal after refund', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $debits = $this->postedDebitsByPurpose($reversal->id);
        $actual = json_encode($debits, JSON_THROW_ON_ERROR);

        // ARTIFACT 1 — the GL entry.
        self::assertSame(
            '600.000',
            $debits[SystemAccountPurpose::CustomerReceivable->value] ?? '0.000',
            'the reversing GL must unwind only what is LEFT (1000 paid - 400 already refunded). '
            ."Posting the gross 1000 debits AR 1400 against 1000 credited. Posted debits: {$actual}",
        );

        // ARTIFACT 2 — the reversing document.
        self::assertSame(
            0,
            bccomp('-600.000', (string) $reversal->amount, 3),
            'the reversing document is sized on the net',
        );

        // ARTIFACT 3 — the cash movement.
        $movement = DB::table('repository_movements')
            ->where('idempotency_key', "refund:{$payment->id}:reversal:{$reversal->id}")
            ->first();
        self::assertNotNull($movement, 'exactly one reversal movement');
        self::assertSame(
            0,
            bccomp('600.000', (string) $movement->amount, 3),
            'the cash movement is sized on the net',
        );

        // And the three agree — the invariant the gate asked for by name.
        self::assertSame(
            0,
            bccomp(
                (string) $debits[SystemAccountPurpose::CustomerReceivable->value],
                (string) $movement->amount,
                3,
            ),
            'ONE VALUE, THREE ARTIFACTS: document, movement and GL must not disagree about how much '
            .'was unwound',
        );

        // The whole AR lineage nets to zero: 1000 credited, 400 + 600 debited.
        self::assertSame(
            '0.000',
            app(PartnerBalanceService::class)
                ->getCustomerReceivableBalance($this->company->id, $this->partner->id),
            'AR must not be over-restored — this is the arithmetic C-A broke',
        );
    }

    /**
     * C-A, the inverse direction the gate also proved: with NO prior refund the
     * gross and the net coincide, so the pure-AR path is provably unchanged from
     * base. Without this, a fix could satisfy the case above by always posting
     * the net while silently breaking the ordinary reversal.
     */
    public function test_ca_a_reversal_with_no_prior_refund_still_posts_the_full_amount(): void
    {
        $invoice = $this->postedInvoice('1000.000');
        $payment = $this->payViaApi('1000.000', [
            ['document_id' => $invoice->id, 'amount' => '1000.000'],
        ]);

        $reversal = $this->refundService->reversePayment($payment, 'C-A no refund', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $debits = $this->postedDebitsByPurpose($reversal->id);

        self::assertSame(
            '1000.000',
            $debits[SystemAccountPurpose::CustomerReceivable->value] ?? '0.000',
            'with no refund lineage the net IS the gross',
        );
        self::assertSame(0, bccomp('-1000.000', (string) $reversal->amount, 3));
    }

    /**
     * A9 / **ORCHESTRATOR RULING 2026-08-10, condition 3(a)** — an `origin = Pos`
     * payment with an **EMPTY** partition must take `B2b`, not `PosRevenue`.
     *
     * This is the exact case the old selector mis-shaped: `cancelForPaymentReversal()`
     * chose the shape from `$payment->origin`
     * (`InstrumentLifecycleService.php:744-746`), so ANY `origin = Pos` payment
     * that survived the D-6 gate got `Dr ProductRevenue`. Under the ruling
     * `origin` is consulted NOWHERE in that method — the empty-partition arm
     * selects `B2b` unconditionally, which preserves the legacy single
     * partner-tagged AR debit at the nominal.
     *
     * Fail-closed relative to the plan's "origin as fallback" wording: it removes
     * the last path by which a non-POS-void reversal could mint `PosRevenue`.
     */
    public function test_a9_ruling_an_origin_pos_payment_with_an_empty_partition_takes_b2b(): void
    {
        $chequeMethod = $this->chequeMethod();
        $portfolioAccountId = $this->instrumentAccountId(InstrumentAccountPurpose::ChecksToCollect);

        $instrument = app(InstrumentLifecycleService::class)->receive(new ReceiveInstrumentData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            paymentMethodId: $chequeMethod->id,
            kind: InstrumentKind::Cheque,
            direction: InstrumentDirection::Inbound,
            origin: InstrumentOrigin::Pos,
            reference: 'POS-EMPTY-PARTITION-1',
            amount: '90.000',
            currency: 'TND',
            repositoryId: $this->repository->id,
            partnerId: $this->partner->id,
            createdBy: $this->user->id,
        ));

        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $chequeMethod->id,
            'instrument_id' => $instrument->id,
            'repository_id' => $this->repository->id,
            'amount' => '90.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            // Reversible type (so the D-6 gate lets it through) + POS origin.
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::Pos,
            'reference' => 'POS-EMPTY-'.Str::random(6),
            'created_by' => $this->user->id,
        ]);

        // A journal entry that is NOT ('customer_payment'|'advance', source_id =
        // payment.id) — so D-17's gate passes but the partition reads EMPTY.
        $entryId = $this->postRevenueBackedEntry($portfolioAccountId, '90.000');
        $payment->journal_entry_id = $entryId;
        $payment->save();
        $instrument->payment_id = $payment->id;
        $instrument->save();

        self::assertSame(
            '0.000',
            $this->creditByPurpose($payment->id, 'customer_payment', SystemAccountPurpose::CustomerReceivable),
            'fixture: the partition really is empty',
        );

        $this->refundService->reversePayment($payment, 'A9 ruling 3(a)', $this->user->id);

        $debits = $this->postedDebitsByPurposeForSource($instrument->id, 'instrument');
        $actual = json_encode($debits, JSON_THROW_ON_ERROR);

        self::assertSame(
            '90.000',
            $debits[SystemAccountPurpose::CustomerReceivable->value] ?? '0.000',
            'ruling 3(a): an empty partition selects B2b unconditionally — origin is consulted '
            ."NOWHERE in cancelForPaymentReversal(). Posted debits: {$actual}",
        );
        self::assertArrayNotHasKey(
            SystemAccountPurpose::ProductRevenue->value,
            $debits,
            "no reversal path may mint PosRevenue. Posted debits: {$actual}",
        );
    }

    /**
     * A9 / **ORCHESTRATOR RULING 2026-08-10, condition 3(b)** — the negative pin:
     * `CancellationShape::PosRevenue` is producible **only** by an explicit
     * caller-supplied shape through `cancel()`. No `reversePayment()` path can
     * reach it.
     *
     * Structural, deliberately: the claim is about REACHABILITY, and no runtime
     * fixture can prove the absence of a path. `cancelForPaymentReversal()` must
     * not mention `PaymentOrigin` at all, and the only `PosRevenue` producer
     * outside the enum and the `match` arms must be the POS void lane's explicit
     * argument (`TreasuryReceiptBridge.php:1502-1509`).
     */
    public function test_a9_ruling_pos_revenue_is_producible_only_via_an_explicit_caller_shape(): void
    {
        $lifecycle = (string) file_get_contents(
            app_path('Modules/Treasury/Application/Services/InstrumentLifecycleService.php')
        );

        // Comments are STRIPPED first: the method carries a long explanatory note
        // about the selector that was removed, and the claim under test is about
        // executable code, not prose.
        $method = $this->stripComments(
            $this->extractMethodBody($lifecycle, 'public function cancelForPaymentReversal(')
        );

        self::assertStringNotContainsString(
            'PaymentOrigin',
            $method,
            'ruling 3(b): origin must be consulted NOWHERE in cancelForPaymentReversal() — '
            .'its only possible traffic was the wrong case (shape Z)',
        );
        self::assertStringNotContainsString(
            '->origin',
            $method,
            'ruling 3(b): no property read of the payment origin either',
        );
        self::assertStringNotContainsString(
            'CancellationShape::PosRevenue',
            $method,
            'cancelForPaymentReversal() must never select PosRevenue',
        );
        self::assertStringContainsString(
            'CancellationShape::B2b',
            $method,
            'it must select B2b unconditionally',
        );

        // The POS void lane keeps its explicit pass — plan §8 puts it out of scope.
        $bridge = (string) file_get_contents(
            app_path('Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php')
        );
        self::assertStringContainsString(
            'CancellationShape::PosRevenue',
            $bridge,
            'the POS void lane must keep producing PosRevenue through its explicit cancel() argument',
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A posted entry keyed on something OTHER than this payment — the shape a POS
     * sale receipt produces (`createPOSPaymentEntry` → `source_type='pos_receipt'`,
     * `source_id` = the RECEIPT). Yields an EMPTY partition.
     */
    private function postRevenueBackedEntry(string $portfolioAccountId, string $amount): string
    {
        return DB::transaction(function () use ($portfolioAccountId, $amount): string {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'entry_number' => 'POS-'.Str::random(8),
                'entry_date' => now(),
                'description' => 'POS receipt settled by cheque',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'pos_receipt',
                'journal_code' => JournalCode::fromSourceType('pos_receipt'),
                'source_id' => Str::uuid()->toString(),
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $portfolioAccountId,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'POS payment via cheque portfolio',
                'line_order' => 0,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $this->accountId(SystemAccountPurpose::ProductRevenue),
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'POS sales revenue',
                'line_order' => 1,
            ]);
            app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');

            return $entry->id;
        });
    }

    /**
     * Remove `//`, `#` and block comments so a structural assertion tests CODE
     * rather than the prose explaining it.
     */
    private function stripComments(string $php): string
    {
        $out = '';
        foreach (token_get_all('<?php '.$php) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * Crude but sufficient brace-matched extraction of one method body, so the
     * structural assertions above cannot be satisfied by an unrelated part of the
     * file.
     */
    private function extractMethodBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        self::assertNotFalse($start, "method not found: {$signature}");

        $open = strpos($source, '{', $start);
        self::assertNotFalse($open);

        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail("unbalanced braces while extracting {$signature}");
    }

    private function accountId(SystemAccountPurpose $purpose): string
    {
        return Account::findByPurposeOrFail($this->company->id, $purpose)->id;
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

    /**
     * Total DEBIT per `SystemAccountPurpose` across every POSTED journal entry
     * whose `source_id` is the given payment — the shape assertion of record.
     *
     * @return array<string, string>
     */
    private function postedDebitsByPurpose(string $paymentId): array
    {
        return $this->postedSidesByPurpose($paymentId, 'debit');
    }

    /**
     * @return array<string, string>
     */
    private function postedCreditsByPurpose(string $paymentId): array
    {
        return $this->postedSidesByPurpose($paymentId, 'credit');
    }

    /**
     * @return array<string, string>
     */
    private function postedSidesByPurpose(string $paymentId, string $side): array
    {
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_entries.source_id', $paymentId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            // Only lines that actually USE this side — otherwise the opposite
            // leg shows up as a `0.000` entry and an assertArrayNotHasKey()
            // reads as a hit.
            ->where("journal_lines.{$side}", '>', 0)
            ->whereNotNull('accounts.system_purpose')
            ->groupBy('accounts.system_purpose')
            ->selectRaw("accounts.system_purpose as purpose, CAST(SUM(journal_lines.{$side}) AS TEXT) as total")
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            /** @var string $purpose */
            $purpose = $row->getAttribute('purpose');
            /** @var string $total */
            $total = (string) $row->getAttribute('total');
            $totals[$purpose] = self::normaliseDecimal($total);
        }

        return $totals;
    }

    private function creditByPurpose(string $paymentId, string $sourceType, SystemAccountPurpose $purpose): string
    {
        /** @var object{total: string|null}|null $row */
        $row = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_entries.source_id', $paymentId)
            ->where('journal_entries.source_type', $sourceType)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->where('journal_lines.account_id', $this->accountId($purpose))
            ->selectRaw('CAST(COALESCE(SUM(journal_lines.credit), 0) AS TEXT) as total')
            ->first();

        return self::normaliseDecimal((string) ($row->total ?? '0'));
    }

    /**
     * Fund the till through the movement PORT (`balance` is port-managed and not
     * fillable, and W-5b forbids taking a cash register below zero). Needed only
     * by fixtures that build the Payment row directly and therefore never
     * recorded the payment's own movement IN.
     *
     * @param  numeric-string  $amount
     */
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
            idempotencyLeg: 'opening',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: 'DPA-REV2-A fixture opening balance',
            allowWhileFrozen: false,
        )));
        $this->repository->refresh();
    }

    private function chequeMethod(): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cheque',
            'code' => 'CHQ-'.Str::random(4),
            'is_active' => true,
            'is_physical' => true,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
            'requires_third_party' => false,
            'is_push' => false,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);
    }

    private function instrumentAccountId(InstrumentAccountPurpose $purpose): string
    {
        return app(InstrumentAccountResolver::class)->resolveOrFail($purpose, $this->company->id);
    }

    /**
     * @return array<string, string>
     */
    private function postedDebitsByPurposeForSource(string $sourceId, string $sourceType): array
    {
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_entries.source_id', $sourceId)
            ->where('journal_entries.source_type', $sourceType)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->where('journal_lines.debit', '>', 0)
            ->whereNotNull('accounts.system_purpose')
            ->groupBy('accounts.system_purpose')
            ->selectRaw('accounts.system_purpose as purpose, CAST(SUM(journal_lines.debit) AS TEXT) as total')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            /** @var string $purpose */
            $purpose = $row->getAttribute('purpose');
            $totals[$purpose] = self::normaliseDecimal((string) $row->getAttribute('total'));
        }

        return $totals;
    }

    /**
     * Normalise a driver-formatted aggregate into a fixed-scale decimal STRING.
     *
     * The SUM(...) aggregates in this class are read back with `CAST(... AS TEXT)`,
     * and the two drivers disagree on the text they produce: PostgreSQL keeps the
     * `numeric(N,3)` scale ('90.000'), while SQLite — which has no DECIMAL type and
     * stores these columns with NUMERIC affinity — collapses the trailing zeros
     * ('90'). String-equality on a driver-formatted decimal therefore made this
     * whole class SQLite-red / PG-green with identical production behaviour.
     *
     * Round (half-up) at $scale with bcmath, then emit the canonical fixed-scale
     * representation so both drivers land on the same string. Never uses floats.
     */
    private static function normaliseDecimal(string $value, int $scale = 3): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            $trimmed = '0';
        }

        $negative = str_starts_with($trimmed, '-');
        $magnitude = ltrim($trimmed, '+-');
        if ($magnitude === '' || ! preg_match('/^\d+(\.\d+)?$/', $magnitude)) {
            // Not a plain decimal (unexpected driver formatting) — surface it as-is
            // so the failure message shows the real value instead of a silent '0.000'.
            return $trimmed;
        }

        // Half-up rounding at $scale: add 5 at the (scale+1)-th place, then truncate.
        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($magnitude, $half, $scale + 1);
        $result = bcadd($rounded, '0', $scale);

        return $negative && bccomp($result, '0', $scale) !== 0 ? '-'.$result : $result;
    }

    private function soleDebitLineForSource(
        string $sourceId,
        string $sourceType,
        SystemAccountPurpose $purpose,
    ): JournalLine {
        /** @var list<JournalLine> $lines */
        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_entries.source_id', $sourceId)
            ->where('journal_entries.source_type', $sourceType)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->where('journal_lines.account_id', $this->accountId($purpose))
            ->where('journal_lines.debit', '>', 0)
            ->select('journal_lines.*')
            ->get()
            ->all();

        self::assertCount(1, $lines, "expected exactly one {$purpose->value} debit line on {$sourceType}");

        return $lines[0];
    }

    private function soleDebitLine(string $paymentId, SystemAccountPurpose $purpose): JournalLine
    {
        /** @var list<JournalLine> $lines */
        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_entries.source_id', $paymentId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->where('journal_lines.account_id', $this->accountId($purpose))
            ->where('journal_lines.debit', '>', 0)
            ->select('journal_lines.*')
            ->get()
            ->all();

        self::assertCount(1, $lines, "expected exactly one {$purpose->value} debit line");

        return $lines[0];
    }
}
