<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Treasury spine Task 9: journal_code (FEC-readiness) is stamped on every
 * JournalEntry from its source_type via JournalCode::fromSourceType(), at
 * every GeneralLedgerService::JournalEntry::create() call site.
 */
final class JournalCodeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private Account $cashAccount;

    private Account $receivableAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Journal Code Test Customer',
            'type' => PartnerType::Customer,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);
        $this->receivableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CustomerReceivable);
    }

    public function test_invoice_sourced_entry_gets_sales_journal_code(): void
    {
        $invoice = $this->createInvoice();

        $entry = app(GeneralLedgerService::class)->createFromInvoice($invoice, $this->user);

        $this->assertSame('invoice', $entry->source_type);
        $this->assertSame(JournalCode::Sales, $entry->journal_code);
        $this->assertSame('VT', $entry->journal_code->value);

        // Persisted, not just held in-memory on the returned model.
        $fresh = JournalEntry::findOrFail($entry->id);
        $this->assertSame(JournalCode::Sales, $fresh->journal_code);
    }

    public function test_payment_sourced_entry_gets_bank_journal_code(): void
    {
        // Seed a posted receivable so the payment has something to clear.
        $this->createPostedReceivable('300.000');

        $entry = app(GeneralLedgerService::class)->createPaymentReceivedJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentId: (string) Str::uuid(),
            amount: '120.000',
            paymentMethodAccountId: $this->cashAccount->id,
            date: now(),
            description: 'Customer payment received',
            user: $this->user,
            currencyCode: $this->company->currency,
        );

        $this->assertSame('customer_payment', $entry->source_type);
        $this->assertSame(JournalCode::Bank, $entry->journal_code);
        $this->assertSame('BQ', $entry->journal_code->value);

        $fresh = JournalEntry::findOrFail($entry->id);
        $this->assertSame(JournalCode::Bank, $fresh->journal_code);
    }

    public function test_from_source_type_unknown_falls_back_to_misc(): void
    {
        $this->assertSame(JournalCode::Misc, JournalCode::fromSourceType('unknown'));
        $this->assertSame('OD', JournalCode::fromSourceType('unknown')->value);
    }

    private function createInvoice(): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-JCODE-'.uniqid(),
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'currency' => 'TND',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Test product',
            'quantity' => '1.000',
            'unit_price' => '100.000',
            'tax_rate' => '0.00',
            'line_total' => '100.000',
        ]);

        $invoice->load('lines');

        return $invoice;
    }

    private function createPostedReceivable(string $amount): void
    {
        // Any partner-tagged receivable line; used only so the later payment
        // has an available balance to clear.
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-JCODE-OPEN-'.uniqid(),
            'entry_date' => now(),
            'description' => 'Opening receivable',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'test_receivable',
            'source_id' => (string) Str::uuid(),
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->receivableAccount->id,
            'partner_id' => $this->partner->id,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Opening receivable',
            'line_order' => 0,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cashAccount->id,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Opening balancing leg',
            'line_order' => 1,
        ]);
    }
}
