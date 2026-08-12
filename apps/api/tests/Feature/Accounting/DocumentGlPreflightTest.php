<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\GlResidualRefusal;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\UnpostableDocumentGlException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * W-6 D1a, gate finding C-1 — the GL balance verdict must be reached BEFORE the
 * document is fiscally sealed, and a refusal must strand nothing.
 *
 * The first cut of the D1a guard threw from `AccountingService`, which runs in
 * `InvoicePostedListener` — dispatched by `DocumentPostingService` from
 * `DB::afterCommit(...)` so a listener failure cannot roll back the fiscal chain.
 * The consequence was that a refusal arrived when the document was ALREADY
 * `Posted`, `fiscal_hash`-sealed and `chain_sequence`-numbered, and `post()`
 * returns early on an already-posted document so `InvoicePosted` never re-fired:
 * the document was permanently sealed with NO GL at all — AR, revenue, VAT and
 * the partner balance all missing while the trial balance reported "balanced".
 *
 * `DocumentPostingService::post()` now asks `DocumentGlPreflightInterface` inside
 * its own transaction, before `postWithFiscalChain()`. These tests pin both halves
 * of the resulting contract:
 *   1. a refusal leaves the document COMPLETELY unsealed, and
 *   2. the same document posts normally once its totals are corrected — i.e. the
 *      refusal is recoverable, not a dead end.
 */
#[Group('historical-compat')]
final class DocumentGlPreflightTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private DocumentPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GL Preflight Tenant',
            'slug' => 'gl-preflight-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GL Preflight Co',
            'legal_name' => 'GL Preflight Co SARL',
            'tax_id' => 'TAX-PF-'.uniqid(),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        Location::factory()->create(['company_id' => $this->company->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Preflight User',
            'email' => 'preflight-'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        // The REAL French chart — including the 6581/7581 rounding-difference pair
        // and, correctly, no timbre account.
        (new FranceChartOfAccountsSeeder)->run($this->company->id, $this->tenant->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Preflight Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->postingService = app(DocumentPostingService::class);
    }

    /**
     * THE C-1 REGRESSION TEST. A document whose GL cannot balance is refused with
     * nothing sealed: status untouched, no fiscal hash, no chain sequence, no
     * journal entry. Before the pre-flight this document ended up `Posted` and
     * hash-chained with no GL behind it.
     */
    public function test_a_document_whose_gl_cannot_balance_is_refused_without_being_sealed(): void
    {
        $entriesBefore = JournalEntry::query()->count();

        // MTP-DOC-06 shape: header tax_amount 0.00 against a line at 20% ->
        // AR debit 100.00 vs credits 100.00 revenue + 20.00 VAT, residual -20.00.
        $invoice = $this->confirmedInvoice(lineTotal: '100.00', taxRate: '20.00', total: '100.00');

        try {
            $this->postingService->post($invoice);
            $this->fail('post() must refuse a document whose GL entry cannot balance.');
        } catch (UnpostableDocumentGlException $e) {
            $this->assertSame(GlResidualRefusal::NegativeResidual, $e->refusal);
        }

        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(
            DocumentStatus::Confirmed,
            $fresh->status,
            'the document must NOT be posted — the refusal rolled the posting transaction back'
        );
        $this->assertNull($fresh->fiscal_hash, 'nothing may be sealed into the fiscal chain');
        $this->assertNull($fresh->chain_sequence, 'no chain sequence may be consumed');
        $this->assertNotSame(FiscalStatus::Sealed, $fresh->fiscal_status);

        $this->assertSame($entriesBefore, JournalEntry::query()->count(), 'and no journal entry exists');
        $this->assertSame(0, JournalEntry::query()->where('source_id', $invoice->id)->count());
    }

    /**
     * The refusal must not be a dead end: correcting the totals and posting again
     * succeeds and produces the GL. This is what makes the pre-flight branch
     * acceptable without a `documents:repost-gl` backfill command — nothing is
     * ever stranded, so nothing needs re-driving.
     */
    public function test_the_refused_document_posts_normally_once_its_totals_are_corrected(): void
    {
        $invoice = $this->confirmedInvoice(lineTotal: '100.00', taxRate: '20.00', total: '100.00');

        try {
            $this->postingService->post($invoice);
            $this->fail('expected the first attempt to be refused');
        } catch (UnpostableDocumentGlException) {
            // expected
        }

        // The accountant fixes the header: 100.00 net + 20.00 VAT = 120.00.
        $invoice->update(['tax_amount' => '20.00', 'total' => '120.00', 'balance_due' => '120.00']);

        $posted = $this->postingService->post($invoice->fresh(['lines']));

        $this->assertSame(DocumentStatus::Posted, $posted->status);
        $this->assertNotNull($posted->fiscal_hash);

        $entry = JournalEntry::query()->where('source_id', $invoice->id)->first();
        $this->assertNotNull($entry, 'the corrected document must get its GL entry');

        $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->get();
        $debits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 2), '0');
        $credits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 2), '0');
        $this->assertSame(0, bccomp($debits, $credits, 2));
        $this->assertSame(0, bccomp($debits, '120.00', 2));
    }

    /**
     * The happy path through the real posting service, with the reviewer's
     * concrete rounding case: two lines of net 12.13 at 20% EUR leave a +0.01
     * truncation residual, which is absorbed by PCG 758 and the document seals.
     */
    public function test_a_french_invoice_with_a_tax_truncation_residual_seals_and_posts_its_gl(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-PF-ROUND-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '24.26',
            'tax_amount' => '4.85',
            'total' => '29.11',
            'balance_due' => '29.11',
        ]);
        foreach ([1, 2] as $lineNumber) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $invoice->id,
                'line_number' => $lineNumber,
                'description' => 'Truncating line '.$lineNumber,
                'quantity' => '1.00',
                'unit_price' => '12.13',
                'tax_rate' => '20.00',
                'line_total' => '12.13',
            ]);
        }

        $posted = $this->postingService->post($invoice->fresh(['lines']));
        $this->assertSame(DocumentStatus::Posted, $posted->status);

        $entry = JournalEntry::query()->where('source_id', $invoice->id)->first();
        $this->assertNotNull($entry);

        $roundingAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SalesRoundingDifferenceIncome);
        $roundingLine = JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $roundingAccount->id)
            ->first();

        $this->assertNotNull($roundingLine, 'the +0.01 truncation residual must land on PCG 758');
        $this->assertSame(0, bccomp((string) $roundingLine->credit, '0.01', 2));
    }

    /**
     * The credit-note mirror: a positive residual on a reversal is DEBITED to the
     * PCG 658 expense counterpart, and the reversal balances.
     */
    public function test_a_french_credit_note_debits_its_rounding_residual_to_the_expense_counterpart(): void
    {
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'CN-PF-ROUND-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '24.26',
            'tax_amount' => '4.85',
            'total' => '29.11',
            'balance_due' => '29.11',
        ]);
        foreach ([1, 2] as $lineNumber) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $creditNote->id,
                'line_number' => $lineNumber,
                'description' => 'Returned truncating line '.$lineNumber,
                'quantity' => '1.00',
                'unit_price' => '12.13',
                'tax_rate' => '20.00',
                'line_total' => '12.13',
            ]);
        }

        $posted = $this->postingService->post($creditNote->fresh(['lines']));
        $this->assertSame(DocumentStatus::Posted, $posted->status);

        $entry = JournalEntry::query()->where('source_id', $creditNote->id)->first();
        $this->assertNotNull($entry);

        $roundingAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SalesRoundingDifferenceExpense);
        $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->get();

        $roundingLine = $lines->firstWhere('account_id', $roundingAccount->id);
        $this->assertNotNull($roundingLine, 'a credit note absorbs its residual on the DEBIT side, via PCG 658');
        $this->assertSame(0, bccomp((string) $roundingLine->debit, '0.01', 2));

        $debits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 2), '0');
        $credits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 2), '0');
        $this->assertSame(0, bccomp($debits, $credits, 2), 'the reversal must balance');
    }

    private function confirmedInvoice(string $lineTotal, string $taxRate, string $total): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-PF-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => $lineTotal,
            'tax_amount' => bcsub($total, $lineTotal, 2),
            'total' => $total,
            'balance_due' => $total,
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Preflight probe line',
            'quantity' => '1.00',
            'unit_price' => $lineTotal,
            'tax_rate' => $taxRate,
            'line_total' => $lineTotal,
        ]);

        /** @var Document */
        return $invoice->fresh(['lines']);
    }
}
