<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\CorrectingEntryRefusalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException;
use App\Modules\Accounting\Domain\Exceptions\UnpostableCorrectingEntryException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
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

    /**
     * The two pre-flights are deliberately different verdicts.
     *
     * The STRUCTURAL one accepts a correction that does not (yet) rebalance the
     * target — that is what makes a DRAFT meaningful, and the balance can move
     * between drafting and posting. It still refuses what can never become true,
     * such as an unsupported target type.
     */
    public function test_the_structural_preflight_accepts_an_unbalanced_draft_but_still_refuses_the_impossible(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $unbalanced = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '9.000', '0', 'Not enough yet'),
        ]);

        // Structural: fine. Postable: not yet.
        $this->corrections->assertCorrectingEntryIsWellFormed($unbalanced);

        try {
            $this->corrections->assertCorrectingEntryIsPostable($unbalanced);
            self::fail('The postable verdict must still refuse an unbalancing correction');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::LeavesTargetUnbalanced, $exception->refusalCode);
        }

        // Structural refusals still fire on the structural verdict.
        $orphan = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::CorrectingEntry,
            'document_number' => 'CE-ORPHAN2-'.uniqid(),
            'document_date' => now(),
            'status' => DocumentStatus::Draft,
            'currency' => 'TND',
            'source_document_id' => null,
            'payload' => (new CorrectingEntryPayload('orphan', [
                CorrectingEntryLegData::of($this->accountId('411'), '1.000', '0', null),
            ]))->toDocumentPayload(),
        ]);

        $this->expectException(UnpostableCorrectingEntryException::class);
        $this->corrections->assertCorrectingEntryIsWellFormed($orphan);
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

    // =====================================================================
    // Gate fix round — treasury + fiscal P1/P2 findings
    // =====================================================================

    /**
     * P1-1 (both gates). A WITHDRAWN target may not be corrected.
     *
     * The probe that found this posted a 50.000 reclass onto a CANCELLED
     * invoice's AR and VAT. `reverseDocumentGl()` had already mirrored the
     * original out and is idempotent, so the correction's legs stood in the
     * ledger with nothing left to unwind them — permanently unreachable money.
     */
    public function test_a_correction_onto_a_cancelled_target_is_refused(): void
    {
        $invoice = $this->postedInvoiceWithBalancedGl(Carbon::parse('2026-01-15'));

        $this->accountingService->reverseDocumentGl($this->reloadDocument($invoice));
        $invoice->update(['status' => DocumentStatus::Cancelled]);

        $correction = $this->correctingDocumentFor($this->reloadDocument($invoice), [
            CorrectingEntryLegData::of($this->accountId('706'), '50.000', '0', 'Reclass'),
            CorrectingEntryLegData::of($this->accountId('707'), '0', '50.000', 'Reclass'),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A cancelled document must not accept a correction');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::TargetAlreadyWithdrawn, $exception->refusalCode);
        }

        self::assertSame(0, JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE)
            ->where('source_id', $correction->id)
            ->count());
    }

    /**
     * The LEDGER's answer, not the document's: a reversal exists even though the
     * status was left behind. The two halves of the guard are independent and
     * both are load-bearing.
     */
    public function test_a_correction_onto_an_already_reversed_target_is_refused_even_if_the_status_lags(): void
    {
        $invoice = $this->postedInvoiceWithBalancedGl(Carbon::parse('2026-01-15'));

        $this->accountingService->reverseDocumentGl($this->reloadDocument($invoice));
        // Status deliberately NOT moved to Cancelled.
        self::assertSame(DocumentStatus::Posted, $this->reloadDocument($invoice)->status);

        $correction = $this->correctingDocumentFor($this->reloadDocument($invoice), [
            CorrectingEntryLegData::of($this->accountId('706'), '50.000', '0', null),
            CorrectingEntryLegData::of($this->accountId('707'), '0', '50.000', null),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('An already-reversed ledger footprint must not accept a correction');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::TargetAlreadyWithdrawn, $exception->refusalCode);
        }
    }

    /**
     * P1-2 (both gates). A control-account leg carries `partner_id`, and the
     * subledger still reconciles afterwards.
     *
     * The probe: this lane's own canonical example — a 19.000 debit to 411 —
     * moved the control account to 129.000 while the partner subledger stayed at
     * 119.000. `reconcileSubledger()` is the house's own arbiter, so it is what
     * this asserts; a `partner_id IS NOT NULL` check alone would pass on a leg
     * stamped with the WRONG partner.
     */
    public function test_a_control_account_leg_carries_the_partner_and_the_subledger_reconciles(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', 'Missing AR leg'),
        ]);

        $entryId = $this->corrections->postCorrectingEntryGl($correction);

        $arLine = JournalLine::query()
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $this->accountId('411'))
            ->firstOrFail();

        self::assertSame(
            $this->customer->id,
            $arLine->partner_id,
            'A 411 leg must name the partner whose balance it moves',
        );

        $reconciliation = app(PartnerBalanceService::class)
            ->reconcileSubledger($this->company->id, SystemAccountPurpose::CustomerReceivable);

        self::assertTrue(
            $reconciliation['is_balanced'],
            sprintf(
                'Subledger must reconcile after a control-account correction: control=%s subledger=%s',
                $reconciliation['control_account_balance'],
                $reconciliation['subledger_total'],
            ),
        );
        self::assertSame(0, $reconciliation['entries_without_partner']);
    }

    /**
     * The leg's OWN partner wins over the target's — an accountant reclassifying
     * between two customers must be able to say which balance each leg moves.
     */
    public function test_an_explicit_leg_partner_overrides_the_targets(): void
    {
        $otherCustomer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Autre Client',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', 'To the other client', $otherCustomer->id),
        ]);

        $entryId = $this->corrections->postCorrectingEntryGl($correction);

        self::assertSame(
            $otherCustomer->id,
            JournalLine::query()
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $this->accountId('411'))
                ->firstOrFail()
                ->partner_id,
        );
    }

    /**
     * Refused, never guessed: a control leg whose partner cannot be resolved
     * would move the control account with no partner statement behind it, and
     * the journal is immutable, so there is no later repair.
     */
    public function test_a_control_account_leg_with_no_resolvable_partner_is_refused(): void
    {
        // Partnerless from birth. It cannot be nulled AFTERWARDS: the invoice is
        // sealed, and `trg_document_immutability` refuses the update — which is
        // itself a useful reminder that a leg written without a partner can
        // never be repaired later either.
        $invoice = $this->partnerlessInvoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A control-account leg with no partner must be refused');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(
                CorrectingEntryRefusalCode::ControlAccountLegWithoutPartner,
                $exception->refusalCode,
            );
        }
    }

    /**
     * P1-3 (fiscal). A leg finer than the CURRENCY admits is refused.
     *
     * The FormRequest's ceiling is the COLUMN scale (3). The balance verdict and
     * the ledger run at the CURRENCY scale — EUR is 2 — and `bcadd` TRUNCATES.
     * The probe posted `dr 0.005 / cr 0.001` on a EUR document: both truncated
     * to `0.00`, the aggregate declared it balanced, and the company ledger was
     * permanently out by 0.004 at column scale.
     */
    public function test_a_leg_finer_than_the_currency_scale_is_refused(): void
    {
        $invoice = $this->eurInvoiceWithBalancedGl(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('706'), '0.005', '0', null),
            CorrectingEntryLegData::of($this->accountId('707'), '0', '0.001', null),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A sub-currency-scale leg must be refused, not truncated into a false balance');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(
                CorrectingEntryRefusalCode::LegAmountBeyondCurrencyScale,
                $exception->refusalCode,
            );
        }

        self::assertSame(0, JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE)
            ->where('source_id', $correction->id)
            ->count());
    }

    /** A leg AT the currency scale still posts — the guard refuses excess, not precision. */
    public function test_a_leg_at_the_currency_scale_still_posts_on_a_eur_document(): void
    {
        $invoice = $this->eurInvoiceWithBalancedGl(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('706'), '0.01', '0', null),
            CorrectingEntryLegData::of($this->accountId('707'), '0', '0.01', null),
        ]);

        self::assertNotSame('', $this->corrections->postCorrectingEntryGl($correction));
    }

    /**
     * P1-4 (treasury). The post takes the SAME per-company advisory lock the GL
     * chokepoint holds (`GeneralLedgerService::sealAndPersistEntry`), and takes
     * it BEFORE it reads `chain_sequence`.
     *
     * Asserted on the statement log rather than on `pg_locks`, because the
     * property that matters is ORDERING: an advisory lock acquired after the
     * max() read would serialise nothing.
     */
    public function test_the_post_takes_the_company_advisory_lock_before_reading_the_chain(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            // The lock statement itself is pgsql-guarded in postCorrectingEntryGl(),
            // so on SQLite there is nothing to observe — the ordering property this
            // test pins only exists on the production driver (the laned Accounting
            // CI job runs PG; local by-path sqlite runs must skip, not fail).
            self::markTestSkipped('pg_advisory_xact_lock ordering is observable on PostgreSQL only.');
        }

        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null),
        ]);

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->corrections->postCorrectingEntryGl($correction);

        $lockIndex = null;
        $chainReadIndex = null;
        foreach ($statements as $index => $sql) {
            if ($lockIndex === null && str_contains($sql, 'pg_advisory_xact_lock')) {
                $lockIndex = $index;
            }
            if ($chainReadIndex === null && str_contains($sql, 'max("chain_sequence")')) {
                $chainReadIndex = $index;
            }
        }

        self::assertNotNull($lockIndex, 'The correcting post must take the company advisory lock');
        self::assertNotNull($chainReadIndex, 'The correcting post must read the chain sequence');
        self::assertLessThan(
            $chainReadIndex,
            $lockIndex,
            'The advisory lock must be taken BEFORE the chain-sequence read it serialises',
        );
    }

    /**
     * P2-3 (treasury). Ordinary period control: a `fiscal_periods` row covering
     * the CORRECTION's own date that is Closed refuses the post, on exactly the
     * terms the GL chokepoint applies.
     *
     * Distinct from the target's VAT period, which may be closed or filed — that
     * is the whole escape hatch and is pinned separately above.
     */
    public function test_posting_into_a_closed_fiscal_period_is_refused(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null),
        ]);

        $this->closeFiscalPeriodCovering(now());

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A closed fiscal period must refuse the correcting post');
        } catch (ClosedFiscalPeriodException) {
            // The chokepoint's own refusal type, deliberately.
        }

        self::assertSame(0, JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE)
            ->where('source_id', $correction->id)
            ->count());
    }

    /**
     * P2-6 (fiscal). A VAT control leg is refused while the TARGET's VAT period
     * is FILED — the declaration is lodged, and moving 4457 inside it diverges
     * the ledger from the return with no reconciliation path.
     *
     * The non-VAT legs of the same correction remain permitted; that is what the
     * companion test below pins, and together they are the whole ruling: the
     * escape hatch stays open, the declaration stays honest.
     */
    public function test_a_vat_leg_is_refused_when_the_targets_period_is_filed(): void
    {
        $documentDate = Carbon::parse('2026-01-15');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Filed);

        $invoice = $this->invoiceWithStrandedVatLeg($documentDate);

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('4457'), '19.000', '0', 'Move the VAT'),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A VAT leg in a FILED period must be refused');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::VatLegInFiledPeriod, $exception->refusalCode);
        }
    }

    /** A VAT leg is fine while the period is merely CLOSED — closed is reopenable, filed is not. */
    public function test_a_vat_leg_is_permitted_when_the_targets_period_is_only_closed(): void
    {
        $documentDate = Carbon::parse('2026-02-15');
        $this->vatPeriodFor($documentDate, VatPeriodStatus::Closed);

        $invoice = $this->invoiceWithStrandedVatLeg($documentDate);

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('4457'), '19.000', '0', 'Move the VAT'),
        ]);

        self::assertNotSame('', $this->corrections->postCorrectingEntryGl($correction));
    }

    // ================================================================
    // Re-gate round — treasury P2-1 (partner axis) + P3-1 (fallback)
    // ================================================================

    /**
     * PROBE C. A SIBLING-COMPANY partner must not reach a control leg.
     *
     * `legs.*.partner_id` was format-only (`uuid`) while the ACCOUNT on the same
     * leg was company-scoped three statements earlier. Nothing downstream closes
     * the gap: `PartnerBalanceService::getSubledgerTotal()` does not scope
     * partners by company, so the reconciler cannot see the foreign row at all,
     * and the balance refresh dies inside its own try/catch as a swallowed
     * `ModelNotFoundException`. The divergence would be silent and — the journal
     * being immutable — permanent.
     */
    public function test_a_sibling_company_partner_on_a_leg_is_refused(): void
    {
        $siblingCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sibling Company',
            'legal_name' => 'Sibling Company SARL',
            'tax_id' => 'TAX-SIBLING-'.uniqid(),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $foreignPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $siblingCompany->id,
            'name' => 'Client de la societe soeur',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null, $foreignPartner->id),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A partner from another company must be refused');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::UnknownPartner, $exception->refusalCode);
        }

        self::assertSame(0, JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE)
            ->where('source_id', $correction->id)
            ->count());
    }

    /**
     * PROBE D. A well-formed but NONEXISTENT partner uuid must be a typed 422,
     * not an FK violation surfacing as an untyped 500 from the INSERT.
     */
    public function test_a_nonexistent_partner_uuid_on_a_leg_is_a_typed_refusal_not_an_fk_violation(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of(
                $this->accountId('411'),
                '19.000',
                '0',
                null,
                Str::uuid()->toString(),
            ),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A nonexistent partner must be refused before the INSERT');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(CorrectingEntryRefusalCode::UnknownPartner, $exception->refusalCode);
        }
    }

    /** A partner of THIS company still posts — the guard scopes, it does not forbid. */
    public function test_an_own_company_partner_on_a_leg_still_posts(): void
    {
        $ownPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Autre client de la maison',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null, $ownPartner->id),
        ]);

        $entryId = $this->corrections->postCorrectingEntryGl($correction);

        self::assertSame(
            $ownPartner->id,
            JournalLine::query()
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $this->accountId('411'))
                ->firstOrFail()
                ->partner_id,
        );
    }

    /**
     * P3-1. A SUPPLIER control leg does NOT inherit the target's partner.
     *
     * A correcting entry's target is always a CUSTOMER document, so inheriting
     * would stamp a customer id into the supplier subledger — and it would
     * RECONCILE, because `reconcileSubledger(SupplierPayable)` only checks that
     * control equals Σ(partner statements). A balance that is clean and wrong is
     * worse than one that is visibly broken.
     */
    public function test_a_supplier_control_leg_does_not_inherit_the_targets_customer(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        // The target HAS a partner — the point is that 401 must not take it.
        self::assertNotNull($invoice->partner_id);

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('401'), '19.000', '0', 'Supplier side'),
        ]);

        try {
            $this->corrections->postCorrectingEntryGl($correction);
            self::fail('A supplier control leg must require an explicit partner');
        } catch (UnpostableCorrectingEntryException $exception) {
            self::assertSame(
                CorrectingEntryRefusalCode::ControlAccountLegWithoutPartner,
                $exception->refusalCode,
            );
            self::assertStringContainsString('SUPPLIER control account', $exception->getMessage());
        }
    }

    /** Named explicitly, the supplier leg posts — the rule is "explicit", not "never". */
    public function test_a_supplier_control_leg_with_an_explicit_partner_posts(): void
    {
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Fournisseur SARL',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('401'), '19.000', '0', null, $supplier->id),
        ]);

        $entryId = $this->corrections->postCorrectingEntryGl($correction);

        self::assertSame(
            $supplier->id,
            JournalLine::query()
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $this->accountId('401'))
                ->firstOrFail()
                ->partner_id,
        );
    }

    /** The CUSTOMER side still inherits — the restriction is targeted, not blanket. */
    public function test_a_customer_control_leg_still_inherits_the_targets_partner(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg(Carbon::parse('2026-01-15'));

        $correction = $this->correctingDocumentFor($invoice, [
            CorrectingEntryLegData::of($this->accountId('411'), '19.000', '0', null),
        ]);

        $entryId = $this->corrections->postCorrectingEntryGl($correction);

        self::assertSame(
            $this->customer->id,
            JournalLine::query()
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $this->accountId('411'))
                ->firstOrFail()
                ->partner_id,
        );
    }

    // ------------------------------- gate-fix-round helpers ---

    private function reloadDocument(Document $document): Document
    {
        /** @var Document */
        return Document::query()->with('lines')->findOrFail($document->id);
    }

    /**
     * A posted, balanced invoice denominated in EUR — a currency whose scale is
     * 2, against the 3-decimal storage column. That gap is the P1-3 hole.
     */
    private function eurInvoiceWithBalancedGl(Carbon $documentDate): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-EUR-'.uniqid(),
            'document_date' => $documentDate,
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'balance_due' => '100.00',
            'currency' => 'EUR',
        ]);

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'EUR-'.uniqid(),
            'entry_date' => $documentDate,
            'description' => 'Balanced EUR invoice',
            'status' => JournalEntryStatus::Posted,
            'source_type' => AccountingService::DOCUMENT_SOURCE_TYPE,
            'source_id' => $invoice->id,
            'chain_sequence' => JournalEntry::getNextChainSequence($this->company->id),
            'previous_hash' => JournalEntry::getLastChainHash($this->company->id),
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->accountId('411'),
            'partner_id' => $this->customer->id,
            'debit' => '100.00',
            'credit' => '0',
            'description' => 'AR',
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->accountId('707'),
            'debit' => '0',
            'credit' => '100.00',
            'description' => 'Revenue',
        ]);

        /** @var Document */
        return $invoice->fresh(['lines']);
    }

    private function closeFiscalPeriodCovering(Carbon $date): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY '.$date->year,
            'start_date' => $date->copy()->startOfYear()->toDateString(),
            'end_date' => $date->copy()->endOfYear()->toDateString(),
            'is_closed' => false,
        ]);

        FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->company->id,
            'name' => $date->format('F Y'),
            'period_number' => (int) $date->format('n'),
            'start_date' => $date->copy()->startOfMonth()->toDateString(),
            'end_date' => $date->copy()->endOfMonth()->toDateString(),
            'status' => PeriodStatus::Closed,
        ]);
    }

    /**
     * The stranded-VAT shape with NO partner on the invoice at all
     * (`documents.partner_id` has been nullable since
     * `2026_06_27_110000_make_documents_partner_id_nullable`), so a control-account
     * leg has nothing to inherit.
     */
    private function partnerlessInvoiceWithStrandedVatLeg(Carbon $documentDate): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => null,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-NOPARTNER-'.uniqid(),
            'document_date' => $documentDate,
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'balance_due' => '119.000',
            'currency' => 'TND',
        ]);

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'STRANDED-NP-'.uniqid(),
            'entry_date' => $documentDate,
            'description' => 'Partnerless invoice with a stranded VAT leg',
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
            'description' => 'Stranded TVA collectee',
        ]);

        /** @var Document */
        return $invoice->fresh(['lines']);
    }
}
