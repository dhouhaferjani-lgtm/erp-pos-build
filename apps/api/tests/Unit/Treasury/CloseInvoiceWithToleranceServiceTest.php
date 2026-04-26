<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\CloseInvoiceWithToleranceService;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Events\InvoiceClosedWithTolerance;
use App\Modules\Treasury\Domain\Exceptions\InvoiceAlreadyPaidException;
use App\Modules\Treasury\Domain\Exceptions\ToleranceExceededException;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 / Task 11 — A2 B2B close-with-tolerance service.
 *
 * Uses real GeneralLedgerService (final class — cannot be mocked) with the
 * minimum chart of accounts (AR + 658) seeded inline, mirroring the Phase 2
 * ReceiptPaymentServiceToleranceTest pattern.
 */
final class CloseInvoiceWithToleranceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private string $closedBy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        Country::create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => '€',
        ]);
        // FR tolerance: max €0.50 absolute and 0.5% percentage.
        CountryPaymentSettings::create([
            'country_code' => 'FR',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.500',
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->closedBy = (string) Str::uuid();
    }

    public function test_closes_invoice_with_residual_within_threshold(): void
    {
        $this->seedToleranceGlAccounts();
        Event::fake([InvoiceClosedWithTolerance::class]);

        $invoice = $this->seedPostedInvoice(total: '100.300', balance: '0.300');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);
        $result = $service->close($invoice->id, $this->closedBy);

        $this->assertSame($invoice->id, $result->invoiceId);
        $this->assertSame('0.300', $result->amountWrittenOff);
        $this->assertNotEmpty($result->glEntryId);

        $invoice->refresh();
        $this->assertSame('0.000', (string) $invoice->balance_due);
        $this->assertSame(DocumentStatus::Paid, $invoice->status);

        $entry = JournalEntry::where('source_type', 'payment_tolerance')
            ->where('source_id', $invoice->id)
            ->first();
        $this->assertNotNull($entry, 'A journal entry must be posted for the write-off.');
        $this->assertSame($result->glEntryId, $entry->id);

        Event::assertDispatched(
            InvoiceClosedWithTolerance::class,
            function (InvoiceClosedWithTolerance $event) use ($invoice, $result): bool {
                return $event->invoiceId === $invoice->id
                    && $event->companyId === $this->company->id
                    && $event->partnerId === $this->partner->id
                    && $event->amountWrittenOff === '0.300'
                    && $event->currency === 'EUR'
                    && $event->glEntryId === $result->glEntryId
                    && $event->closedBy === $this->closedBy;
            },
        );
    }

    public function test_creates_tolerance_only_payment_allocation_with_null_payment_id(): void
    {
        $this->seedToleranceGlAccounts();
        $invoice = $this->seedPostedInvoice(total: '100.300', balance: '0.300');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);
        $service->close($invoice->id, $this->closedBy);

        $allocations = PaymentAllocation::where('document_id', $invoice->id)->get();
        $this->assertCount(1, $allocations, 'Exactly one tolerance-only allocation should exist.');

        $row = PaymentAllocation::where('document_id', $invoice->id)->firstOrFail();
        $this->assertNull($row->payment_id, 'Tolerance writeoff allocations have no payment behind them.');
        $this->assertSame('0.3000', (string) $row->amount, 'amount should equal the residual being cleared.');
        $this->assertSame('0.3000', (string) $row->tolerance_writeoff, 'tolerance_writeoff should equal amount for pure writeoff.');
    }

    public function test_subsequent_allocation_does_not_resurrect_outstanding_balance(): void
    {
        // Source-of-truth check (Document::getOutstandingAmount docblock):
        // outstanding = total − Σ(allocations.amount) − Σ(creditNoteAllocations.amount).
        // The bug guarded against is the trigger formula recomputing balance_due back
        // to the residual once any subsequent allocation event touches the invoice.
        // We model production state realistically: a prior payment allocation that
        // covered all but the residual exists before A2 runs.
        $this->seedToleranceGlAccounts();
        $invoice = $this->seedPostedInvoice(total: '100.300', balance: '0.300');

        // Seed the prior 100.000 payment allocation that left the 0.300 residual.
        // payment_id is null because we don't need a real Payment row for this scenario.
        PaymentAllocation::create([
            'payment_id' => null,
            'document_id' => $invoice->id,
            'amount' => '100.000',
            'tolerance_writeoff' => null,
        ]);

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);
        $service->close($invoice->id, $this->closedBy);

        $invoice->refresh();
        $this->assertSame('0.000', $invoice->getOutstandingAmount(3));

        // Add a follow-up zero-allocation event (e.g., what a future credit-note
        // listener might emit) — outstanding must still report zero, proving the
        // tolerance allocation is on the books permanently.
        PaymentAllocation::create([
            'payment_id' => null,
            'document_id' => $invoice->id,
            'amount' => '0.000',
            'tolerance_writeoff' => null,
        ]);

        $invoice->refresh();
        $this->assertSame(
            '0.000',
            $invoice->getOutstandingAmount(3),
            'Outstanding must stay 0 after a subsequent zero-amount allocation.',
        );
    }

    public function test_rejects_when_balance_exceeds_max_amount_threshold(): void
    {
        $this->seedToleranceGlAccounts();
        Event::fake([InvoiceClosedWithTolerance::class]);
        // €5.00 residual on a €105 invoice — exceeds both percentage (0.525) and max_amount (0.500).
        $invoice = $this->seedPostedInvoice(total: '105.000', balance: '5.000');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);

        $this->expectException(ToleranceExceededException::class);
        try {
            $service->close($invoice->id, $this->closedBy);
        } finally {
            $invoice->refresh();
            $this->assertSame('5.000', (string) $invoice->balance_due);
            $this->assertSame(DocumentStatus::Posted, $invoice->status);
            Event::assertNotDispatched(InvoiceClosedWithTolerance::class);
            $this->assertSame(
                0,
                JournalEntry::where('source_id', $invoice->id)->count(),
                'Over-threshold rejection must not leak journal entries.',
            );
        }
    }

    public function test_rejects_when_balance_equals_max_amount_strict_inequality(): void
    {
        $this->seedToleranceGlAccounts();
        Event::fake([InvoiceClosedWithTolerance::class]);
        // 0.5% of 1000 = 5.00 (passes); max_amount 0.50 is the binding gate at exactly 0.50.
        $invoice = $this->seedPostedInvoice(total: '1000.500', balance: '0.500');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);

        $this->expectException(ToleranceExceededException::class);
        try {
            $service->close($invoice->id, $this->closedBy);
        } finally {
            Event::assertNotDispatched(InvoiceClosedWithTolerance::class);
        }
    }

    public function test_rejects_when_balance_equals_percentage_threshold_strict_inequality(): void
    {
        $this->seedToleranceGlAccounts();
        Event::fake([InvoiceClosedWithTolerance::class]);
        // 0.5% of 60.000 = 0.300; balance == 0.300 must reject (strict <).
        $invoice = $this->seedPostedInvoice(total: '60.000', balance: '0.300');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);

        $this->expectException(ToleranceExceededException::class);
        try {
            $service->close($invoice->id, $this->closedBy);
        } finally {
            Event::assertNotDispatched(InvoiceClosedWithTolerance::class);
        }
    }

    public function test_rejects_already_paid_invoice(): void
    {
        $this->seedToleranceGlAccounts();
        Event::fake([InvoiceClosedWithTolerance::class]);
        $invoice = $this->seedPostedInvoice(
            total: '100.000',
            balance: '0.000',
            status: DocumentStatus::Paid,
        );

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);

        $this->expectException(InvoiceAlreadyPaidException::class);
        try {
            $service->close($invoice->id, $this->closedBy);
        } finally {
            Event::assertNotDispatched(InvoiceClosedWithTolerance::class);
        }
    }

    public function test_rejects_zero_balance_idempotency(): void
    {
        $this->seedToleranceGlAccounts();
        Event::fake([InvoiceClosedWithTolerance::class]);
        // Status still Posted, but balance hit zero — should still reject as a no-op.
        $invoice = $this->seedPostedInvoice(total: '100.000', balance: '0.000');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);

        $this->expectException(InvoiceAlreadyPaidException::class);
        try {
            $service->close($invoice->id, $this->closedBy);
        } finally {
            Event::assertNotDispatched(InvoiceClosedWithTolerance::class);
        }
    }

    public function test_atomic_rollback_when_gl_post_fails(): void
    {
        // Intentionally do NOT seed the AR account — getAccountByPurpose throws,
        // bubbling up through DB::transaction and rolling back invoice state.
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '658',
            'name' => 'Payment Tolerance Expense',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense,
            'is_active' => true,
        ]);

        Event::fake([InvoiceClosedWithTolerance::class]);
        $invoice = $this->seedPostedInvoice(total: '100.300', balance: '0.300');

        $service = $this->app->make(CloseInvoiceWithToleranceService::class);

        try {
            $service->close($invoice->id, $this->closedBy);
            $this->fail('Expected exception when AR account is missing.');
        } catch (\Throwable) {
            // expected — bubbling out of DB::transaction triggers rollback.
        }

        $invoice->refresh();
        $this->assertSame('0.300', (string) $invoice->balance_due, 'Balance must remain unchanged on GL failure.');
        $this->assertSame(DocumentStatus::Posted, $invoice->status, 'Status must remain Posted on GL failure.');
        Event::assertNotDispatched(InvoiceClosedWithTolerance::class);
        $this->assertSame(
            0,
            JournalEntry::where('source_id', $invoice->id)->count(),
            'Failed close must not leave a partial journal entry.',
        );
    }

    private function seedToleranceGlAccounts(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customer Receivable',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '658',
            'name' => 'Payment Tolerance Expense',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense,
            'is_active' => true,
        ]);
    }

    private function seedPostedInvoice(
        string $total,
        string $balance,
        DocumentStatus $status = DocumentStatus::Posted,
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => $status,
            'document_number' => 'INV-'.now()->format('Y').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $balance,
        ]);
    }
}
