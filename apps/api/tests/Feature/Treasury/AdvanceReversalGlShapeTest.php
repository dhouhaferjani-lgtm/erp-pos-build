<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
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
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\CancellationShape;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
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
     * A1b — a PURE customer advance is refused today, and the refusal is a dead
     * end rather than a redirect (`PaymentRefundService.php:1161-1163`).
     *
     * GREEN today. **A7 inverts this test**: once `PaymentType::Advance` maps to
     * `ReversalSupport::CashReversal`, a pure advance must reverse successfully
     * through `reverseCustomerAdvanceJournalEntry()`. Pinned here so the
     * inversion is a deliberate, visible edit rather than a silent behaviour
     * change discovered later.
     */
    public function test_a1b_a_pure_customer_advance_is_refused_today(): void
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

        try {
            $this->refundService->reversePayment($payment, 'A1b pure advance', $this->user->id);
            self::fail('a pure advance must refuse reversal today');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('DPA-V4-ADV-1', $exception->getMessage());
            self::assertStringContainsString('not implemented', strtolower($exception->getMessage()));
        }

        self::assertSame(PaymentStatus::Completed, $payment->fresh()?->status);
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

        app(InstrumentLifecycleService::class)->cancel(
            $instrument->id,
            $this->user->id,
            'POS refund/void',
            CancellationShape::PosRevenue,
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

    // ---------------------------------------------------------------- helpers

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
            $totals[$purpose] = $total;
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

        return (string) ($row->total ?? '0');
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
            $totals[$purpose] = (string) $row->getAttribute('total');
        }

        return $totals;
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
