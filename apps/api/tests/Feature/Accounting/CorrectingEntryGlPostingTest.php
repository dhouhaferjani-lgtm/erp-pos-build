<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\CorrectingEntryRefusalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Exceptions\UnpostableCorrectingEntryException;
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
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Accounting\DocumentGlCorrectionInterface;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R2-F4 — the GL half of the correcting-entry DOCUMENT.
 *
 * Owner ruling c4 (`docs/superpowers/tickets/2026-08-07-round2-rulings-record.md`)
 * chose branch (a) of `docs/superpowers/tickets/2026-08-06-l2-correcting-entry-escape-hatch.md`
 * and strengthened it: the correction is a DOCUMENT linked to the original, not
 * a manual journal entry.
 *
 * The invariant this file pins is the one that makes a correction WORTH being a
 * document: after it posts, the TARGET document's whole ledger footprint —
 * its own sealed entry plus every correction applied to it — balances.
 * A correction that leaves the target unbalanced is refused, and a correction is
 * the only supported way to repair a target that is already unbalanced.
 */
final class CorrectingEntryGlPostingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private Product $product;

    private AccountingService $accountingService;

    private DocumentGlCorrectionInterface $corrections;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Correcting Entry Tenant',
            'slug' => 'correcting-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Correcting Entry Company',
            'legal_name' => 'Correcting Entry Company SARL',
            'tax_id' => 'TAX-CORRECTING',
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
            'sku' => 'PROD-CORR-'.uniqid(),
            'name' => 'Doliprane 1000mg',
            'type' => ProductType::Part,
            'cost_price' => '5.000',
            'sale_price' => '10.000',
            'is_active' => true,
        ]);

        $this->accountingService = app(AccountingService::class);
        $this->corrections = app(DocumentGlCorrectionInterface::class);
    }

    // ------------------------------------------------------ happy path ---

    /**
     * The canonical case from the ticket: an invoice whose sealed entry is short
     * one leg (`W-6 D1b`, 19.000 stranded on `4457`). The correcting document
     * supplies the missing leg and the target's aggregate balances again.
     */
    public function test_it_posts_a_sealed_journal_entry_for_a_correcting_document(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', 'Missing AR leg'),
        ]);

        $entryId = $this->corrections->postCorrectingEntryGl($correction);

        $entry = JournalEntry::query()->with('lines')->findOrFail($entryId);

        self::assertSame(
            AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE,
            $entry->source_type,
            'A correction is keyed with its OWN source type, distinct from Document and DocumentCancellation',
        );
        self::assertSame(
            $correction->id,
            $entry->source_id,
            'source_id is the CORRECTING document — the link to the original lives on '
            .'documents.source_document_id, which is what ruling c4 mandates',
        );
        self::assertSame(JournalEntryStatus::Posted, $entry->status);
        self::assertNotNull($entry->fiscal_hash, 'The correction joins the journal hash chain like any other entry');
        self::assertNotNull($entry->chain_sequence);
        self::assertCount(1, $entry->lines);
        self::assertSame('19.000', (string) $entry->lines[0]->debit);
        self::assertSame($this->accountId('411'), $entry->lines[0]->account_id);
    }

    /**
     * The entry is dated when the CORRECTION happened, never back-dated onto the
     * target's period. Same doctrine as the cancellation reversal
     * (`reverseDocumentGl()`: "the original stands in its period and the
     * reversal lands in the period the withdrawal actually happened in").
     */
    public function test_the_correcting_entry_is_dated_now_not_on_the_targets_date(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null),
        ]);

        $entry = JournalEntry::query()->findOrFail(
            $this->corrections->postCorrectingEntryGl($correction),
        );

        self::assertNotNull($entry->entry_date);
        self::assertSame(
            now()->toDateString(),
            $entry->entry_date->toDateString(),
            'The correcting entry posts in the CURRENT period',
        );
        self::assertNotSame('2026-01-15', $entry->entry_date->toDateString());
    }

    /**
     * A balanced correction on a balanced target: the reclassification case (move
     * an amount from one account to another). The aggregate stays balanced, so
     * it posts.
     */
    public function test_a_self_balancing_reclassification_on_a_balanced_target_posts(): void
    {
        $invoice = $this->postedInvoiceWithBalancedGl(Carbon::parse('2026-01-20'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('706'), '100.000', '0', 'Out of 707'),
            CorrectingEntryLegData::of($this->accountId('707'), '0', '100.000', 'Into 706'),
        ]);

        $entryId = $this->corrections->postCorrectingEntryGl($correction);

        self::assertNotSame('', $entryId);
        self::assertCount(2, JournalEntry::query()->findOrFail($entryId)->lines);
    }

    /**
     * A document may accumulate SEVERAL corrections. The aggregate must see all
     * of them, or the second correction would be judged against a stale picture
     * of the target's ledger and could re-break what the first one fixed.
     *
     * This is also what pins the two-step resolution
     * (`documents.source_document_id` -> the corrections' journal entries) that
     * ruling c4's mandated link makes possible.
     */
    public function test_a_second_correction_is_judged_against_the_first_ones_legs(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $first = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', 'Missing AR leg'),
        ]);
        $this->corrections->postCorrectingEntryGl($first);
        // Deliberately NOT flipping $first to Posted: the LEDGER is the source of
        // truth for what has been corrected, not the document row's status.

        // The target now balances (0 + 19.000 debit vs 19.000 credit). A second
        // correction must therefore be self-balancing to be accepted...
        $unbalancedSecond = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '5.000', '0', 'Breaks it again'),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($unbalancedSecond);
            self::fail('A second correction that unbalances the now-balanced target must be refused');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(
                CorrectingEntryRefusalCode::LeavesTargetUnbalanced,
                $exception->refusalCode,
                "The first correction's legs must be visible in the aggregate",
            );
        }

        // ...and a self-balancing one is.
        $balancedSecond = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('706'), '5.000', '0', null),
            CorrectingEntryLegData::of($this->accountId('707'), '0', '5.000', null),
        ]);

        self::assertNotSame('', $this->corrections->postCorrectingEntryGl($balancedSecond));
    }

    /**
     * The pre-flight must reach the same verdict as the write, and must write
     * nothing on its way there.
     */
    public function test_the_preflight_agrees_with_the_write_and_persists_nothing(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));
        $entriesBefore = JournalEntry::query()->count();

        $good = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null),
        ]);
        $this->corrections->assertCorrectingEntryIsPostable($good);

        self::assertSame(
            $entriesBefore,
            JournalEntry::query()->count(),
            'The pre-flight is a verdict, not a write',
        );

        $bad = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '9.000', '0', null),
        ]);

        $this->expectException(UnpostableCorrectingEntryException::class);
        $this->corrections->assertCorrectingEntryIsPostable($bad);
    }

    // --------------------------------------------------------- refusals ---

    /**
     * THE invariant. A correction whose legs do not bring the target's whole
     * ledger footprint into balance is refused: it would leave the original
     * exactly as broken as it was while adding a second suspicious entry to the
     * chain.
     */
    public function test_a_correction_that_leaves_the_target_unbalanced_is_refused(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));
        $entriesBefore = JournalEntry::query()->count();

        // Only 9.000 of the 19.000 gap.
        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '9.000', '0', 'Half a fix'),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A correction that does not rebalance the target must be refused');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(
                CorrectingEntryRefusalCode::LeavesTargetUnbalanced,
                $exception->refusalCode,
            );
        }

        self::assertSame(
            $entriesBefore,
            JournalEntry::query()->count(),
            'A refused correction must persist nothing at all',
        );
    }

    /**
     * A self-balancing correction on an ALREADY unbalanced target is refused for
     * the same reason: it does not fix the imbalance, so the target stays
     * uncancellable and the escape hatch has not been used.
     */
    public function test_a_self_balancing_correction_on_an_unbalanced_target_is_refused(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('706'), '5.000', '0', null),
            CorrectingEntryLegData::of($this->accountId('707'), '0', '5.000', null),
        ]);

        $this->expectException(UnpostableCorrectingEntryException::class);

        $this->corrections->postCorrectingEntryGl($correction);
    }

    /**
     * Ruling c4's core requirement: the link is MANDATORY. A correcting document
     * with no `source_document_id` is exactly the "free-floating manual JE" the
     * ruling forbids, wearing a document's clothes.
     */
    public function test_a_correcting_document_with_no_source_document_is_refused(): void
    {
        $correction = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::CorrectingEntry,
            'document_number' => 'CE-ORPHAN-'.uniqid(),
            'document_date' => now(),
            'status' => DocumentStatus::Confirmed,
            'currency' => 'TND',
            'source_document_id' => null,
            'payload' => (new CorrectingEntryPayload('orphan', [
                CorrectingEntryLegData::of($this->accountId('411'), '1.000', '0', null),
            ]))->toDocumentPayload(),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A correcting document with no link to an original must be refused');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::MissingSourceDocument, $exception->refusalCode);
        }
    }

    public function test_a_leg_naming_an_account_from_another_company_is_refused(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company SARL',
            'tax_id' => 'TAX-OTHER-CORR',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        (new TunisiaChartOfAccountsSeeder)->run($otherCompany->id, $this->tenant->id);

        $foreignAccountId = Account::query()
            ->where('company_id', $otherCompany->id)
            ->where('code', '411')
            ->firstOrFail()
            ->id;

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($foreignAccountId, '19.000', '0', null),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail("Another company's account must never be postable here");
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::UnknownAccount, $exception->refusalCode);
        }
    }

    /**
     * The target population is EXACTLY `reverseDocumentGl()`'s population
     * (Invoice / CreditNote): those are the only types whose GL
     * `AccountingService` writes under `source_type = 'Document'`, which is the
     * keying the aggregate-balance invariant reads. Every other document family
     * keys its GL through `GeneralLedgerService` under snake_case source types
     * and would silently get a DIFFERENT (weaker) invariant — refusing is
     * honest; widening is a follow-on lane.
     */
    public function test_a_correction_targeting_a_type_outside_the_supported_population_is_refused(): void
    {
        $supplierInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::SupplierInvoice,
            'document_number' => 'SI-CORR-'.uniqid(),
            'document_date' => Carbon::parse('2026-01-15'),
            'status' => DocumentStatus::Posted,
            'total' => '238.000',
            'currency' => 'TND',
        ]);

        $correction = $this->correctingDocumentFor($supplierInvoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '1.000', '0', null),
            CorrectingEntryLegData::of($this->accountId('707'), '0', '1.000', null),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('An unsupported target type must be refused');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::UnsupportedTargetType, $exception->refusalCode);
        }
    }

    public function test_a_correction_targeting_a_document_that_never_reached_the_ledger_is_refused(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-NOGL-'.uniqid(),
            'document_date' => Carbon::parse('2026-01-15'),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'currency' => 'TND',
        ]);

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '1.000', '0', null),
            CorrectingEntryLegData::of($this->accountId('707'), '0', '1.000', null),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('Correcting a document with no journal entry corrects nothing');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::TargetHasNoLedgerEntry, $exception->refusalCode);
        }
    }

    /**
     * Idempotence, on the same explicit-check idiom `reverseDocumentGl()` uses
     * (`journal_entries(source_type, source_id)` carries no uniqueness
     * constraint for these types).
     */
    public function test_posting_the_same_correcting_document_twice_writes_one_entry(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null),
        ]);

        $first = $this->corrections->postCorrectingEntryGl($correction);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('The second posting must be refused, not silently duplicated');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::AlreadyPosted, $exception->refusalCode);
        }

        self::assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE)
                ->where('source_id', $correction->id)
                ->count(),
        );
        self::assertNotSame('', $first);
    }

    // --------------------------------------------- the period-lock ruling ---

    /**
     * R2-F4 PERIOD-LOCK DECISION — a correcting document MAY target an original
     * whose VAT period is CLOSED or FILED. That is the entire point of the
     * escape hatch: the document that must be repaired is, by definition, one
     * whose books were closed with a defect in them.
     *
     * Nothing in the locked period is rewritten — the correcting entry is dated
     * `now()` and lands in the CURRENT period, exactly as
     * `reverseDocumentGl()`'s reversal does. F1's
     * `VatPeriodCancellationGuard` therefore does not apply here: it guards
     * CANCELLATION (withdrawing a document from a closed period), not forward
     * correction.
     */
    public function test_a_correcting_entry_may_target_an_original_in_a_filed_period(): void
    {
        $documentDate = Carbon::parse('2026-01-15');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed);

        $invoice = $this->invoiceWithStrandedVatLeg($documentDate);

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null),
        ]);

        $entry = JournalEntry::query()->findOrFail(
            $this->corrections->postCorrectingEntryGl($correction),
        );

        self::assertNotNull($entry->entry_date);
        self::assertSame(
            now()->toDateString(),
            $entry->entry_date->toDateString(),
            'The correction posts in the CURRENT open period, never back into the filed one',
        );
    }

    public function test_a_correcting_entry_may_target_an_original_in_a_closed_period(): void
    {
        $documentDate = Carbon::parse('2026-02-15');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Closed);

        $invoice = $this->invoiceWithStrandedVatLeg($documentDate);

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null),
        ]);

        self::assertNotSame('', $this->corrections->postCorrectingEntryGl($correction));
    }

    // ----------------------------------------------------------- helpers ---

    private function accountId(string $code): string
    {
        return Account::query()
            ->where('company_id', $this->company->id)
            ->where('code', $code)
            ->firstOrFail()
            ->id;
    }

    private function vatPeriodFor(Carbon $date, VatPeriodStatus $status): VatPeriod
    {
        DB::table('countries')->insertOrIgnore([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'is_active' => true,
        ]);

        return VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => $date->format('F Y'),
            'period_start' => $date->copy()->startOfMonth()->toDateString(),
            'period_end' => $date->copy()->endOfMonth()->toDateString(),
            'status' => $status,
        ]);
    }

    /**
     * @param  list<CorrectingEntryLegData>  $legs
     */
    private function correctingDocumentFor(Document $target, array $legs): Document
    {
        /** @var Document */
        return Document::create([
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
    }

    private function postedInvoiceWithBalancedGl(Carbon $documentDate): Document
    {
        $invoice = $this->baseInvoice($documentDate);

        /** @var Document $fresh */
        $fresh = $invoice->fresh(['lines']);
        $this->accountingService->createInvoiceGLEntries($fresh);

        /** @var Document */
        return $fresh->fresh(['lines']);
    }

    /**
     * The W-6 D1b shape, fabricated directly: a POSTED, sealed invoice whose
     * document-sourced journal entry is short the 19.000 counterpart leg. This is
     * the state the escape-hatch ticket describes as "permanently uncancellable
     * through the product".
     */
    private function invoiceWithStrandedVatLeg(Carbon $documentDate): Document
    {
        $invoice = $this->baseInvoice($documentDate);

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'STRANDED-'.uniqid(),
            'entry_date' => $documentDate,
            'description' => 'Invoice with a stranded VAT leg (W-6 D1b shape)',
            'status' => JournalEntryStatus::Posted,
            'source_type' => AccountingService::DOCUMENT_SOURCE_TYPE,
            'source_id' => $invoice->id,
            'chain_sequence' => JournalEntry::getNextChainSequence($this->company->id),
            'previous_hash' => JournalEntry::getLastChainHash($this->company->id),
        ]);

        // ONE-SIDED on purpose: 19.000 credited to TVA collectée with no debit
        // anywhere. Σdebits = 0, Σcredits = 19.000.
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->accountId('4457'),
            'debit' => '0',
            'credit' => '19.000',
            'description' => 'Stranded TVA collectée',
        ]);

        /** @var Document */
        return $invoice->fresh(['lines']);
    }

    private function baseInvoice(Carbon $documentDate): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-CORR-'.uniqid(),
            'document_date' => $documentDate,
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

        /** @var Document */
        return $invoice->fresh(['lines']);
    }
}
