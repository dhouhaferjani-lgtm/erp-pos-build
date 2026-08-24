<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Accounting\PaymentLedgerPartitionReaderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * N-6 — `documents:repair-paid-never-posted`.
 *
 * The legacy state: `status = paid`, `fiscal_hash IS NULL`. The invoice was
 * never posted, so it has no GL entry at all, while its payment credited the
 * receivable that posting never created.
 */
final class RepairPaidNeverPostedDocumentsCommandTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    private PaymentRepository $cashRegister;

    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDeliveryPolicyFixtures('TN');

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->cashRegister = PaymentRepository::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'code' => 'CASH-01',
            'name' => 'Main Cash Register',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => Account::findByPurposeOrFail($this->dpCompany->id, SystemAccountPurpose::Bank)->id,
            'is_active' => true,
        ]);
    }

    public function test_dry_run_reports_the_candidate_and_writes_nothing(): void
    {
        [$invoice, $allocation] = $this->legacyPaidNeverPostedInvoice();

        $this->artisan('documents:repair-paid-never-posted', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain((string) $invoice->document_number)
            ->expectsOutputToContain('PAID-NEVER-POSTED REPAIR: candidates=1 repaired=1 skipped=0')
            ->assertSuccessful();

        $invoice->refresh();
        $allocation->refresh();

        $this->assertSame(DocumentStatus::Paid, $invoice->status, 'A dry run must not move the status.');
        $this->assertFalse($allocation->booked_as_advance, 'A dry run must not stamp the allocation.');
        $this->assertSame(0, $this->reclassEntryCount());
    }

    public function test_execute_moves_the_invoice_back_to_confirmed_and_rebooks_411_to_419(): void
    {
        [$invoice, $allocation] = $this->legacyPaidNeverPostedInvoice();

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])
            ->expectsOutputToContain('EXECUTE mode')
            ->assertSuccessful();

        $invoice->refresh();
        $allocation->refresh();

        $this->assertSame(
            DocumentStatus::Confirmed,
            $invoice->status,
            'Confirmed is where a never-posted invoice belongs — it can be posted from there.',
        );
        $this->assertNull($invoice->fiscal_hash);

        $this->assertTrue($allocation->booked_as_advance);
        $this->assertNotNull($allocation->advance_journal_entry_id);
        $this->assertNull($allocation->advance_cleared_at, 'The advance is OPEN until the invoice is posted.');

        $this->assertSame(1, $this->reclassEntryCount());
    }

    public function test_a_second_execute_is_a_no_op(): void
    {
        $this->legacyPaidNeverPostedInvoice();

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])->assertSuccessful();
        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])
            ->expectsOutputToContain('PAID-NEVER-POSTED REPAIR: candidates=0 repaired=0 skipped=0')
            ->assertSuccessful();

        $this->assertSame(1, $this->reclassEntryCount());
    }

    public function test_a_sealed_invoice_is_never_a_candidate(): void
    {
        [$invoice] = $this->legacyPaidNeverPostedInvoice();
        $invoice->forceFill(['fiscal_hash' => str_repeat('a', 64)])->save();

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])
            ->expectsOutputToContain('PAID-NEVER-POSTED REPAIR: candidates=0 repaired=0 skipped=0')
            ->assertSuccessful();

        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);
    }

    public function test_a_paid_invoice_with_no_allocation_is_skipped_for_a_human(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $invoice->forceFill(['status' => DocumentStatus::Paid, 'balance_due' => '0.000'])->save();

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])
            ->expectsOutputToContain('no payment allocation')
            ->expectsOutputToContain('PAID-NEVER-POSTED REPAIR: candidates=1 repaired=0 skipped=1')
            ->assertSuccessful();

        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);
    }

    /**
     * C-1 [CRITICAL] — the correcting entry is dated on the PAYMENT's entry, and
     * nothing else.
     *
     * The lookup used `source_type = 'payment'`, which no AR customer payment
     * ever carries (`createPaymentReceivedJournalEntry()` writes
     * `'customer_payment'`; the only writer of `'payment'` sets no `source_id`
     * at all). It therefore matched NOTHING for this population and fell back
     * silently to the INVOICE's `document_date` — so the correcting entry landed
     * in the wrong period, and the period-lock gate was evaluated against a date
     * that had nothing to do with the money being restated.
     */
    public function test_the_reclass_is_dated_on_the_payment_entry_not_the_invoice(): void
    {
        // The two dates must DIFFER and the payment date must be postable, so
        // both are anchored on today rather than on hard-coded months whose
        // fiscal periods the TN chart may have closed.
        $invoiceDate = Carbon::now()->subDays(45)->startOfDay();
        $paymentDate = Carbon::now()->startOfDay();

        [$invoice] = $this->legacyPaidNeverPostedInvoice(
            invoiceDate: $invoiceDate,
            paymentDate: $paymentDate,
        );

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])->assertSuccessful();

        $reclass = JournalEntry::query()
            ->where('company_id', $this->dpCompany->id)
            ->where('source_type', GeneralLedgerService::PAYMENT_ADVANCE_RECLASS_SOURCE_TYPE)
            ->sole();

        $this->assertSame(
            $paymentDate->toDateString(),
            Carbon::parse((string) $reclass->entry_date)->toDateString(),
            'the reclass restates what the PAYMENT entry booked, so it carries that entry\'s date',
        );
        $this->assertNotSame(
            $invoiceDate->toDateString(),
            Carbon::parse((string) $reclass->entry_date)->toDateString(),
            'the pre-fix code silently used the INVOICE date — that is the defect',
        );
        $this->assertSame($invoiceDate->toDateString(), Carbon::parse((string) $invoice->document_date)->toDateString());
    }

    /**
     * C-1, second half — the evidence gate now sees the right period.
     */
    public function test_a_payment_entry_in_a_closed_period_is_skipped(): void
    {
        // Build the legacy state while the period is still OPEN (the fixture
        // posts a real GL entry), THEN close the period covering the payment.
        $paymentDate = Carbon::now()->startOfDay();
        $this->legacyPaidNeverPostedInvoice(
            invoiceDate: Carbon::now()->subDays(45)->startOfDay(),
            paymentDate: $paymentDate,
        );
        $this->closeFiscalPeriodCovering($paymentDate);

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])
            ->expectsOutputToContain('CLOSED/LOCKED fiscal period')
            ->expectsOutputToContain('PAID-NEVER-POSTED REPAIR: candidates=1 repaired=0 skipped=1')
            ->assertSuccessful();

        $this->assertSame(0, $this->reclassEntryCount());
    }

    /**
     * I-2, first arm — the deposit population. A customer deposit applied
     * pre-N-6 posted NO allocation GL at all while the deposit's money already
     * sat in 419, and the old type-only writer still flipped the invoice to
     * `Paid`. Repairing that would invent a 411 debit that never existed and
     * DOUBLE the 419 credit. It is refused at the first gate that sees it —
     * there is no `customer_payment` entry to restate.
     */
    public function test_a_deposit_shaped_invoice_with_no_customer_payment_entry_is_skipped(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        /** @var numeric-string $total */
        $total = bcadd((string) $invoice->total, '0', 3);
        $payment = $this->legacyPayment($total, Carbon::now());

        // The deposit shape: the money is in 419, and NO 411 credit exists.
        app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->dpCompany->id,
            partnerId: $this->dpPartner->id,
            advanceId: $payment->id,
            amount: $total,
            paymentMethodAccountId: (string) $this->cashRegister->gl_account_id,
            date: now(),
            user: $this->dpUser,
            description: 'Legacy customer deposit',
            currencyCode: 'TND',
        );
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => $total,
        ]);
        $invoice->forceFill(['status' => DocumentStatus::Paid, 'balance_due' => '0.000'])->save();

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])
            ->expectsOutputToContain('no posted customer-payment journal entry')
            ->expectsOutputToContain('PAID-NEVER-POSTED REPAIR: candidates=1 repaired=0 skipped=1')
            ->assertSuccessful();

        $this->assertSame(0, $this->reclassEntryCount());
        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);
    }

    /**
     * I-2, second arm — the money is only PARTLY in 411.
     *
     * Here a `customer_payment` entry DOES exist, so the C-1 gate passes; what
     * refuses is the ledger evidence itself. Moving the full allocation out of
     * 411 when only half of it is there would drive the receivable negative and
     * over-credit the advance.
     */
    public function test_an_invoice_only_partly_backed_by_a_receivable_credit_is_skipped(): void
    {
        // The allocation says the full amount; the ledger evidence says half.
        // (Built at fixture time — journal lines are immutable by design, which
        // is itself the reason this evidence gate has to exist.)
        $probe = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $half = bcdiv(bcadd((string) $probe->total, '0', 3), '2', 3);
        $probe->forceDelete();

        [$invoice, $allocation] = $this->legacyPaidNeverPostedInvoice(arBackedAmount: $half);

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])
            ->expectsOutputToContain('backed by a posted receivable')
            ->expectsOutputToContain('PAID-NEVER-POSTED REPAIR: candidates=1 repaired=0 skipped=1')
            ->assertSuccessful();

        $this->assertSame(0, $this->reclassEntryCount());
        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);
        $this->assertFalse($allocation->fresh()->booked_as_advance);
    }

    /**
     * I-9 — the ledger arc, not just the entry count: 411 must return to zero
     * and 419 must carry the credit.
     */
    public function test_the_repair_moves_the_money_from_411_to_419(): void
    {
        [$invoice] = $this->legacyPaidNeverPostedInvoice();
        /** @var numeric-string $total */
        $total = bcadd((string) $invoice->total, '0', 3);

        $this->assertSame(
            '-'.$total,
            $this->accountBalance(SystemAccountPurpose::CustomerReceivable),
            'precondition: the pre-N-6 payment credited 411 against an invoice with no receivable',
        );

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])->assertSuccessful();

        $this->assertSame(
            '0.000',
            $this->accountBalance(SystemAccountPurpose::CustomerReceivable),
            '411 must be back to zero — the credit is undone by the reclass debit',
        );
        $this->assertSame(
            '-'.$total,
            $this->accountBalance(SystemAccountPurpose::CustomerAdvance),
            '419 must now carry the liability, which is where the money actually is',
        );
    }

    /**
     * I-3 — after a repair, a later REFUND must reverse 419, not 411.
     *
     * `PaymentLedgerPartitionReader` decides which account a reversal unwinds.
     * It keys `advanceBacked` on `source_type = 'advance'`, and the repair
     * writes its own `payment_advance_reclass` type while deliberately leaving
     * the original hash-chained `customer_payment` entry untouched. Read
     * literally, a repaired payment therefore looked FULLY AR-backed — so a
     * refund would have reversed a 411 that the repair had already discharged
     * and left the 419 it created standing forever.
     */
    public function test_a_repaired_payment_reads_as_advance_backed_to_the_reversal_partition(): void
    {
        [$invoice, $allocation] = $this->legacyPaidNeverPostedInvoice();
        /** @var numeric-string $total */
        $total = bcadd((string) $invoice->total, '0', 3);
        $paymentId = (string) $allocation->payment_id;

        $before = app(PaymentLedgerPartitionReaderInterface::class)
            ->read($this->dpCompany->id, $paymentId, 'TND');
        $this->assertSame($total, bcadd($before->arBacked, '0', 3), 'precondition: fully AR-backed');

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])->assertSuccessful();

        $after = app(PaymentLedgerPartitionReaderInterface::class)
            ->read($this->dpCompany->id, $paymentId, 'TND');

        $this->assertSame('0.000', bcadd($after->arBacked, '0', 3), 'nothing is left in 411 to reverse');
        $this->assertSame(
            $total,
            bcadd($after->advanceBacked, '0', 3),
            'a later refund must unwind the 419 the repair created',
        );
    }

    /**
     * I-9 — the exemption the handback calls the thing that stops this lane
     * "silently breaking every migrated tenant`s opening AR". Nothing pinned it.
     */
    public function test_a_historical_opening_balance_invoice_is_never_a_candidate(): void
    {
        [$invoice] = $this->legacyPaidNeverPostedInvoice();
        $invoice->forceFill(['is_historical' => true])->save();

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])
            ->expectsOutputToContain('PAID-NEVER-POSTED REPAIR: candidates=0 repaired=0 skipped=0')
            ->assertSuccessful();

        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(0, $this->reclassEntryCount());
    }

    /**
     * R2-I1 — a MULTI-PAYMENT invoice must leave EVERY payment reversible.
     *
     * r1 wrote one reclass for the whole invoice keyed on `paymentIds[0]` while
     * the amount summed every payment's allocations. The partition reader joins
     * on `journal_entries.source_id = payment.id`, so that attribution made
     * payment 0 read as 2x its own value — `PaymentRefundService`'s coverage
     * belt 1 then refuses it FOREVER — while payment 1 read as fully AR-backed
     * and would reverse a 411 the repair had already discharged.
     */
    public function test_a_two_payment_invoice_gets_one_reclass_per_payment_and_both_stay_reversible(): void
    {
        [$invoice, $paymentIds, $half] = $this->legacyPaidNeverPostedInvoiceWithTwoPayments();

        $this->artisan('documents:repair-paid-never-posted', ['--execute' => true])
            ->expectsOutputToContain('PAID-NEVER-POSTED REPAIR: candidates=1 repaired=1 skipped=0')
            ->assertSuccessful();

        $this->assertSame(2, $this->reclassEntryCount(), 'one reclass entry per payment, not one per invoice');

        foreach ($paymentIds as $paymentId) {
            $this->assertSame(
                1,
                JournalEntry::query()
                    ->where('company_id', $this->dpCompany->id)
                    ->where('source_type', GeneralLedgerService::PAYMENT_ADVANCE_RECLASS_SOURCE_TYPE)
                    ->where('source_id', $paymentId)
                    ->count(),
                "payment {$paymentId} must carry its OWN reclass entry",
            );

            $partition = app(PaymentLedgerPartitionReaderInterface::class)
                ->read($this->dpCompany->id, $paymentId, 'TND');

            $this->assertSame(
                '0.000',
                bcadd($partition->arBacked, '0', 3),
                "payment {$paymentId} must have nothing left in 411 to reverse",
            );
            $this->assertSame(
                $half,
                bcadd($partition->advanceBacked, '0', 3),
                "payment {$paymentId} must read as advance-backed for exactly ITS OWN amount",
            );
            $this->assertSame(
                $half,
                bcadd($partition->total(), '0', 3),
                'and its partition must total its own amount — the coverage belt compares against exactly this',
            );
        }

        $this->assertSame(DocumentStatus::Confirmed, $invoice->fresh()->status);
    }

    /**
     * Two payments of half the invoice each, both booked the pre-N-6 way.
     *
     * @return array{Document, list<string>, numeric-string}
     */
    private function legacyPaidNeverPostedInvoiceWithTwoPayments(): array
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        /** @var numeric-string $total */
        $total = bcadd((string) $invoice->total, '0', 3);
        /** @var numeric-string $half */
        $half = bcdiv($total, '2', 3);

        $paymentIds = [];

        foreach ([0, 1] as $index) {
            $payment = $this->legacyPayment($half, Carbon::now()->subDays($index));

            app(GeneralLedgerService::class)->createPaymentReceivedJournalEntry(
                companyId: $this->dpCompany->id,
                partnerId: $this->dpPartner->id,
                paymentId: $payment->id,
                amount: $half,
                paymentMethodAccountId: (string) $this->cashRegister->gl_account_id,
                date: Carbon::now()->subDays($index),
                description: 'Legacy customer payment '.$index,
                user: $this->dpUser,
                currencyCode: 'TND',
            );

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'document_id' => $invoice->id,
                'amount' => $half,
            ]);

            $paymentIds[] = $payment->id;
        }

        $invoice->forceFill(['status' => DocumentStatus::Paid, 'balance_due' => '0.000'])->save();

        return [$invoice->fresh(), $paymentIds, $half];
    }

    private function closeFiscalPeriodCovering(Carbon $date): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->dpCompany->id,
            'name' => 'FY '.$date->year,
            'start_date' => $date->copy()->startOfYear()->toDateString(),
            'end_date' => $date->copy()->endOfYear()->toDateString(),
            'is_closed' => false,
        ]);

        FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->dpCompany->id,
            'name' => $date->format('F Y'),
            'period_number' => (int) $date->format('n'),
            'start_date' => $date->copy()->startOfMonth()->toDateString(),
            'end_date' => $date->copy()->endOfMonth()->toDateString(),
            'status' => PeriodStatus::Closed,
        ]);
    }

    /**
     * The POSTED balance (debit - credit) of a purpose account, at money scale.
     */
    private function accountBalance(SystemAccountPurpose $purpose): string
    {
        $account = Account::findByPurposeOrFail($this->dpCompany->id, $purpose);

        $balance = '0.000';
        $lines = JournalLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($query): void {
                $query->where('company_id', $this->dpCompany->id)
                    ->where('status', JournalEntryStatus::Posted);
            })
            ->get();

        foreach ($lines as $line) {
            $balance = bcadd($balance, bcsub((string) $line->debit, (string) $line->credit, 3), 3);
        }

        return $balance;
    }

    private function legacyPayment(string $amount, Carbon $paymentDate): Payment
    {
        return Payment::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'partner_id' => $this->dpPartner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => $paymentDate,
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'LEGACY-PAY-'.bin2hex(random_bytes(3)),
        ]);
    }

    /**
     * Reproduce the pre-N-6 dead end WITHOUT going through the (now fixed)
     * payment path: a paid, unsealed invoice whose payment credited 411.
     *
     * @return array{Document, PaymentAllocation}
     */
    private function legacyPaidNeverPostedInvoice(
        ?Carbon $invoiceDate = null,
        ?Carbon $paymentDate = null,
        ?string $arBackedAmount = null,
    ): array {
        $invoiceDate ??= Carbon::now();
        $paymentDate ??= Carbon::now();

        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()], ['document_date' => $invoiceDate]);
        /** @var numeric-string $total */
        $total = bcadd((string) $invoice->total, '0', 3);

        $payment = $this->legacyPayment($total, $paymentDate);

        // The WRONG entry the pre-N-6 path wrote: Dr Bank / Cr 411 against an
        // invoice that has no receivable. Note the source type it really
        // carries — `customer_payment`, never `payment` (gate C-1).
        app(GeneralLedgerService::class)->createPaymentReceivedJournalEntry(
            companyId: $this->dpCompany->id,
            partnerId: $this->dpPartner->id,
            paymentId: $payment->id,
            // `$arBackedAmount` lets a test build the PARTLY-411-backed shape:
            // the allocation says one thing, the ledger evidence says less.
            amount: $arBackedAmount ?? $total,
            paymentMethodAccountId: (string) $this->cashRegister->gl_account_id,
            date: $paymentDate,
            description: 'Legacy customer payment',
            user: $this->dpUser,
            currencyCode: 'TND',
        );

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => $total,
        ]);

        $invoice->forceFill(['status' => DocumentStatus::Paid, 'balance_due' => '0.000'])->save();

        return [$invoice->fresh(), $allocation->fresh()];
    }

    private function reclassEntryCount(): int
    {
        return JournalEntry::query()
            ->where('company_id', $this->dpCompany->id)
            ->where('source_type', GeneralLedgerService::PAYMENT_ADVANCE_RECLASS_SOURCE_TYPE)
            ->count();
    }
}
