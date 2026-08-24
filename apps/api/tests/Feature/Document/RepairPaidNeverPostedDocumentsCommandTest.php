<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * Reproduce the pre-N-6 dead end WITHOUT going through the (now fixed)
     * payment path: a paid, unsealed invoice whose payment credited 411.
     *
     * @return array{Document, PaymentAllocation}
     */
    private function legacyPaidNeverPostedInvoice(): array
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        /** @var numeric-string $total */
        $total = bcadd((string) $invoice->total, '0', 3);

        $payment = Payment::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'partner_id' => $this->dpPartner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => $total,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'LEGACY-PAY-1',
        ]);

        // The WRONG entry the pre-N-6 path wrote: Dr Bank / Cr 411 against an
        // invoice that has no receivable.
        app(GeneralLedgerService::class)->createPaymentReceivedJournalEntry(
            companyId: $this->dpCompany->id,
            partnerId: $this->dpPartner->id,
            paymentId: $payment->id,
            amount: $total,
            paymentMethodAccountId: (string) $this->cashRegister->gl_account_id,
            date: now(),
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
