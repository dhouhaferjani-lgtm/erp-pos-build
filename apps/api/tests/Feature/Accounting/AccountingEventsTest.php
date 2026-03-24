<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\JournalEntryCreated;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for Accounting Module Events
 *
 * Verifies that all critical accounting operations emit proper domain events
 * for audit trail and compliance purposes.
 */
final class AccountingEventsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private AccountingService $accountingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create([
            'fiscal_chain_seed' => bin2hex(random_bytes(32)),
        ]);
        $this->customer = Partner::factory()->for($this->tenant)->for($this->company)->create();

        // Create required system accounts
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411000',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707000',
            'name' => 'Product Sales Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '445710',
            'name' => 'VAT Collected',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::VatCollected,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '706000',
            'name' => 'Service Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ServiceRevenue,
            'is_active' => true,
        ]);

        $this->accountingService = app(AccountingService::class);
    }

    /**
     * Test that JournalEntryCreated event is dispatched when creating invoice GL entries
     */
    public function test_journal_entry_created_event_dispatched_on_invoice_posting(): void
    {
        Event::fake([JournalEntryCreated::class]);

        $invoice = $this->createInvoice();

        $this->accountingService->createInvoiceGLEntries($invoice);

        Event::assertDispatched(JournalEntryCreated::class, function (JournalEntryCreated $event) use ($invoice): bool {
            return $event->companyId === $invoice->company_id
                && $event->entryType === 'invoice'
                && $event->sourceId === $invoice->id
                && bccomp($event->totalDebit, $invoice->total, 2) === 0
                && bccomp($event->totalCredit, $invoice->total, 2) === 0;
        });
    }

    /**
     * Test that JournalEntryCreated event contains correct data structure
     */
    public function test_journal_entry_created_event_has_correct_structure(): void
    {
        Event::fake([JournalEntryCreated::class]);

        $invoice = $this->createInvoice();

        $this->accountingService->createInvoiceGLEntries($invoice);

        Event::assertDispatched(JournalEntryCreated::class, function (JournalEntryCreated $event): bool {
            // Verify all required properties exist
            return isset($event->journalEntryId)
                && isset($event->tenantId)
                && isset($event->companyId)
                && isset($event->entryNumber)
                && isset($event->entryDate)
                && isset($event->entryType)
                && isset($event->sourceType)
                && isset($event->sourceId)
                && isset($event->totalDebit)
                && isset($event->totalCredit)
                && isset($event->fiscalHash)
                && isset($event->chainSequence)
                && isset($event->createdAt);
        });
    }

    /**
     * Test that JournalEntryCreated event includes fiscal hash
     */
    public function test_journal_entry_created_event_includes_fiscal_hash(): void
    {
        Event::fake([JournalEntryCreated::class]);

        $invoice = $this->createInvoice();

        $this->accountingService->createInvoiceGLEntries($invoice);

        Event::assertDispatched(JournalEntryCreated::class, function (JournalEntryCreated $event): bool {
            return strlen($event->fiscalHash) === 64
                && preg_match('/^[a-f0-9]{64}$/', $event->fiscalHash) === 1;
        });
    }

    /**
     * Test that JournalEntryCreated event is dispatched for credit note GL entries
     */
    public function test_journal_entry_created_event_dispatched_on_credit_note_posting(): void
    {
        Event::fake([JournalEntryCreated::class]);

        $creditNote = $this->createCreditNote();

        $this->accountingService->createCreditNoteGLEntries($creditNote);

        Event::assertDispatched(JournalEntryCreated::class, function (JournalEntryCreated $event) use ($creditNote): bool {
            return $event->companyId === $creditNote->company_id
                && $event->entryType === 'credit_note'
                && $event->sourceId === $creditNote->id;
        });
    }

    /**
     * Test that multiple journal entry creations dispatch multiple events
     */
    public function test_multiple_journal_entries_dispatch_multiple_events(): void
    {
        Event::fake([JournalEntryCreated::class]);

        // Create first invoice
        $invoice1 = $this->createInvoice();
        $this->accountingService->createInvoiceGLEntries($invoice1);

        // Create second invoice
        $invoice2 = $this->createInvoice();
        $this->accountingService->createInvoiceGLEntries($invoice2);

        Event::assertDispatched(JournalEntryCreated::class, 2);
    }

    /**
     * Test that events include balancing verification (debits = credits)
     */
    public function test_journal_entry_created_event_verifies_balance(): void
    {
        Event::fake([JournalEntryCreated::class]);

        $invoice = $this->createInvoice();

        $this->accountingService->createInvoiceGLEntries($invoice);

        Event::assertDispatched(JournalEntryCreated::class, function (JournalEntryCreated $event): bool {
            // Verify debits equal credits (double-entry accounting principle)
            return bccomp($event->totalDebit, $event->totalCredit, 2) === 0;
        });
    }

    /**
     * Test that JournalEntryCreated event implements getEventName()
     */
    public function test_journal_entry_created_event_has_event_name(): void
    {
        Event::fake([JournalEntryCreated::class]);

        $invoice = $this->createInvoice();

        $this->accountingService->createInvoiceGLEntries($invoice);

        Event::assertDispatched(JournalEntryCreated::class, function (JournalEntryCreated $event): bool {
            return method_exists($event, 'getEventName')
                && $event->getEventName() === 'journal_entry.created';
        });
    }

    /**
     * Helper: Create a posted invoice with lines
     */
    private function createInvoice(): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-TEST-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
            'balance_due' => '120.00',
            'currency' => 'EUR',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Test product',
            'quantity' => '2',
            'unit_price' => '50.00',
            'tax_rate' => '20',
            'line_total' => '100.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    /**
     * Helper: Create a posted credit note with lines
     */
    private function createCreditNote(): Document
    {
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-TEST-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '50.00',
            'tax_amount' => '10.00',
            'total' => '60.00',
            'balance_due' => '60.00',
            'currency' => 'EUR',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $creditNote->id,
            'line_number' => 1,
            'description' => 'Return product',
            'quantity' => '1',
            'unit_price' => '50.00',
            'tax_rate' => '20',
            'line_total' => '50.00',
        ]);

        return $creditNote->fresh(['lines']);
    }
}
