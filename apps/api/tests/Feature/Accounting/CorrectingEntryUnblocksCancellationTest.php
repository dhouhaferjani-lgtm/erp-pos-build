<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Exceptions\UnreversibleDocumentGlException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\CorrectingEntryLegData;
use App\Modules\Document\Application\DTOs\CorrectingEntryPayload;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Accounting\DocumentGlCorrectionInterface;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R2-F4 — the ESCAPE HATCH itself.
 *
 * `docs/superpowers/tickets/2026-08-06-l2-correcting-entry-escape-hatch.md`:
 * `AccountingService::reverseDocumentGl()` refuses to cancel a document whose
 * sealed journal entry is already out of balance — correctly, because mirroring
 * an unbalanced original would seal a SECOND unbalanced entry into the chain.
 * But the refusal was a PERMANENT dead end: the balance count matched only
 * `source_type = 'Document' AND source_id = $document->id`, while the sole
 * manual-entry writer hard-codes `source_type = 'manual'`, so no correction
 * reachable through the product could ever enter the predicate.
 *
 * Owner ruling c4 chose branch (a) and strengthened it into a DOCUMENT. This
 * file proves the loop actually closes: refused -> post a correcting document ->
 * the very same cancel now succeeds, and its reversal mirrors the correction as
 * well as the original.
 */
final class CorrectingEntryUnblocksCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private Product $product;

    private DocumentGlCorrectionInterface $corrections;

    private AccountingService $accountingService;

    private DocumentPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Escape Hatch Tenant',
            'slug' => 'escape-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Escape Hatch Company',
            'legal_name' => 'Escape Hatch Company SARL',
            'tax_id' => 'TAX-ESCAPE',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        (new TunisiaChartOfAccountsSeeder)->run($this->company->id, $this->tenant->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Clinique Al Amal',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-ESC-'.uniqid(),
            'name' => 'Doliprane 1000mg',
            'type' => ProductType::Part,
            'cost_price' => '5.000',
            'sale_price' => '10.000',
            'is_active' => true,
        ]);

        $this->corrections = app(DocumentGlCorrectionInterface::class);
        $this->accountingService = app(AccountingService::class);
        $this->postingService = app(DocumentPostingService::class);
    }

    /**
     * The precondition the ticket describes — reproduced here so the fix below
     * cannot be a false positive on a document that was never actually stuck.
     */
    public function test_an_unbalanced_original_is_still_refused_before_any_correction(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();

        $this->expectException(UnreversibleDocumentGlException::class);

        $this->accountingService->reverseDocumentGl($invoice);
    }

    /**
     * THE ESCAPE HATCH. After a correcting document rebalances the invoice, the
     * cancellation the ledger was refusing goes through.
     */
    public function test_a_correcting_document_unblocks_the_refused_cancellation(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();

        // Still stuck.
        try {
            $this->accountingService->reverseDocumentGl($invoice);
            self::fail('Precondition: the unbalanced original must be refused');
        } catch (UnreversibleDocumentGlException) {
            // expected
        }

        $this->postCorrection($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', 'Missing AR leg'),
        ]);

        $reversalId = $this->accountingService->reverseDocumentGl($this->reload($invoice));

        self::assertNotNull($reversalId, 'The cancellation reversal must now be written');
    }

    /**
     * The reversal must mirror the CORRECTION as well as the original. Reversing
     * only the original would leave the correcting entry's legs standing alone in
     * the ledger after the invoice has been withdrawn — a correction of a
     * document that no longer exists.
     */
    public function test_the_reversal_mirrors_the_correction_as_well_as_the_original(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();

        $this->postCorrection($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', 'Missing AR leg'),
        ]);

        $reversalId = $this->accountingService->reverseDocumentGl($this->reload($invoice));
        self::assertNotNull($reversalId);

        $reversal = JournalEntry::query()->with('lines')->findOrFail($reversalId);

        self::assertCount(
            2,
            $reversal->lines,
            'One mirrored leg for the original 4457 credit and one for the correction 411 debit',
        );

        $debits = '0';
        $credits = '0';
        foreach ($reversal->lines as $line) {
            $debits = bcadd($debits, (string) $line->debit, 3);
            $credits = bcadd($credits, (string) $line->credit, 3);
        }

        self::assertSame(0, bccomp($debits, $credits, 3), 'The reversal itself must balance');
        self::assertSame(0, bccomp($debits, '19.000', 3));

        $mirroredAccounts = $reversal->lines
            ->map(static fn (JournalLine $line): string => (string) $line->account_id)
            ->sort()
            ->values()
            ->all();

        $expected = [$this->accountId('411'), $this->accountId('4457')];
        sort($expected);

        self::assertSame($expected, $mirroredAccounts);
    }

    /**
     * End to end through the product surface: `DocumentPostingService::cancel()`
     * is the path an operator actually takes, and it must reach the same result.
     */
    public function test_the_cancel_flow_itself_succeeds_once_the_correction_is_posted(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();

        try {
            $this->postingService->cancel($invoice, 'before the correction', null);
            self::fail('Precondition: the cancel must be refused while the ledger is unbalanced');
        } catch (UnreversibleDocumentGlException) {
            // expected
        }

        self::assertSame(DocumentStatus::Posted, $this->reload($invoice)->status);

        $this->postCorrection($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', 'Missing AR leg'),
        ]);

        $this->postingService->cancel($this->reload($invoice), 'after the correction', null);

        self::assertSame(DocumentStatus::Cancelled, $this->reload($invoice)->status);
        self::assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
                ->where('source_id', $invoice->id)
                ->count(),
        );
    }

    /**
     * A DIFFERENT invoice's correction must never enter this invoice's aggregate:
     * the footprint is resolved through `documents.source_document_id`, which is
     * per-document by construction, and a leak there would silently "fix" an
     * unrelated imbalance.
     */
    public function test_another_documents_correction_does_not_rebalance_this_one(): void
    {
        $stuck = $this->invoiceWithStrandedVatLeg();
        $other = $this->invoiceWithStrandedVatLeg();

        $this->postCorrection($other, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', "Other invoice's fix"),
        ]);

        $this->expectException(UnreversibleDocumentGlException::class);

        $this->accountingService->reverseDocumentGl($this->reload($stuck));
    }

    /**
     * A balanced invoice with NO corrections must reverse exactly as it did
     * before this lane — the footprint query must not change existing behaviour.
     */
    public function test_an_uncorrected_balanced_invoice_reverses_exactly_as_before(): void
    {
        $invoice = $this->baseInvoice();
        $this->accountingService->createInvoiceGLEntries($this->reload($invoice));

        $reversalId = $this->accountingService->reverseDocumentGl($this->reload($invoice));

        self::assertNotNull($reversalId);

        $original = JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_SOURCE_TYPE)
            ->where('source_id', $invoice->id)
            ->with('lines')
            ->firstOrFail();

        self::assertCount(
            $original->lines->count(),
            JournalEntry::query()->with('lines')->findOrFail($reversalId)->lines,
        );
    }

    // ----------------------------------------------------------- helpers ---

    /**
     * @param  list<CorrectingEntryLegData>  $legs
     */
    private function postCorrection(Document $target, array $legs): Document
    {
        $correction = Document::create([
            'tenant_id' => $target->tenant_id,
            'company_id' => $target->company_id,
            'partner_id' => $target->partner_id,
            'type' => DocumentType::CorrectingEntry,
            'document_number' => 'CE-'.uniqid(),
            'document_date' => now(),
            'status' => DocumentStatus::Confirmed,
            'currency' => $target->currency,
            'source_document_id' => $target->id,
            'payload' => (new CorrectingEntryPayload('Repairing '.$target->document_number, $legs))
                ->toDocumentPayload(),
        ]);

        $this->corrections->postCorrectingEntryGl($correction);
        $correction->update(['status' => DocumentStatus::Posted]);

        /** @var Document */
        return $correction->fresh();
    }

    private function reload(Document $document): Document
    {
        /** @var Document */
        return Document::query()->with('lines')->findOrFail($document->id);
    }

    private function accountId(string $code): string
    {
        return Account::query()
            ->where('company_id', $this->company->id)
            ->where('code', $code)
            ->firstOrFail()
            ->id;
    }

    private function invoiceWithStrandedVatLeg(): Document
    {
        $invoice = $this->baseInvoice();

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'STRANDED-'.uniqid(),
            'entry_date' => $invoice->document_date,
            'description' => 'Invoice with a stranded VAT leg (W-6 D1b shape)',
            'status' => JournalEntryStatus::Posted,
            'source_type' => AccountingService::DOCUMENT_SOURCE_TYPE,
            'source_id' => $invoice->id,
            'chain_sequence' => JournalEntry::getNextChainSequence($this->company->id),
            'previous_hash' => JournalEntry::getLastChainHash($this->company->id),
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->accountId('4457'),
            'debit' => '0',
            'credit' => '19.000',
            'description' => 'Stranded TVA collectée',
        ]);

        return $this->reload($invoice);
    }

    private function baseInvoice(): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-ESC-'.uniqid(),
            'document_date' => Carbon::parse('2026-01-15'),
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'balance_due' => '119.000',
            'currency' => 'TND',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Doliprane 1000mg',
            'quantity' => '10',
            'unit_price' => '10.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);

        return $this->reload($invoice);
    }
}
