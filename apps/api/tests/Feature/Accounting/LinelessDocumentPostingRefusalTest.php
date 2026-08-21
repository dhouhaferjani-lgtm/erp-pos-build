<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\GlResidualRefusal;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\UnpostableDocumentGlException;
use App\Modules\Accounting\Domain\JournalEntry;
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
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;

/**
 * O-26 — a document with NO lines is refused at POSTING, and the pre-O-26
 * legacy shape stays cancellable.
 *
 * Owner ruling 2026-08-21 (repo LEDGER row O-26): PHASE OUT lineless-document
 * posting. A zero-line invoice or credit note has no revenue side at all, so its
 * GL entry is either one-legged (any chart without a `SalesStampDutyPayable`
 * account) or sweeps an entire invoice total into collected stamp duty (Tunisia).
 * `AccountingService::reverseDocumentGl()` therefore carried a carve-out that
 * knowingly seals a one-legged reversal so such a document could still be
 * cancelled — the last genuine class-(c) chained imbalance in the P3-M1 census
 * (`docs/handoff/reviews/enforcement-p3/M1-census.md` R-4).
 *
 * The ruling closes it UPSTREAM rather than by forbidding cancellation: nothing
 * lineless can be posted any more, so the carve-out is dead code for every
 * document authored from now on, while documents posted BEFORE the refusal keep
 * their cancellability.
 *
 * These tests pin both halves:
 *   1. a new lineless invoice / credit note is REFUSED before anything is sealed,
 *      and the refusal is recoverable (add a line, post again);
 *   2. a PRE-EXISTING posted lineless document — seeded directly, as legacy data
 *      would be, never through the API — still cancels.
 *
 * The legacy half deliberately bypasses `DocumentPostingService::post()`: after
 * this lane that path can no longer produce the fixture at all, which is exactly
 * the point.
 */
#[UsesFrozenSeederFixture]
final class LinelessDocumentPostingRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private User $user;

    private DocumentPostingService $postingService;

    private AccountingService $accountingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Lineless Tenant',
            'slug' => 'lineless-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Lineless Co',
            'legal_name' => 'Lineless Co SARL',
            'tax_id' => 'TAX-LL-'.uniqid(),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        Location::factory()->create(['company_id' => $this->company->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Lineless User',
            'email' => 'lineless-'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        (new FranceChartOfAccountsSeeder)->run($this->company->id, $this->tenant->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Lineless Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->postingService = app(DocumentPostingService::class);
        $this->accountingService = app(AccountingService::class);
    }

    /**
     * THE O-26 REFUSAL. A confirmed invoice with zero lines is refused inside
     * `post()`'s transaction, BEFORE `postWithFiscalChain()`: nothing sealed, no
     * chain sequence consumed, no journal entry, and the document is left
     * Confirmed — i.e. still re-postable once corrected.
     */
    public function test_a_lineless_invoice_is_refused_at_posting_with_nothing_sealed(): void
    {
        $entriesBefore = JournalEntry::query()->count();

        $invoice = $this->linelessDocument(DocumentType::Invoice, 'INV-LL-');

        try {
            $this->postingService->post($invoice);
            $this->fail('post() must refuse a document with no lines.');
        } catch (UnpostableDocumentGlException $e) {
            self::assertSame(GlResidualRefusal::LinelessDocument, $e->refusal);
            self::assertStringContainsString('lineless_document_unpostable', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        self::assertNotNull($fresh);
        self::assertSame(
            DocumentStatus::Confirmed,
            $fresh->status,
            'the refusal must roll the posting transaction back — the document stays Confirmed'
        );
        self::assertNull($fresh->fiscal_hash, 'nothing may be sealed into the fiscal chain');
        self::assertNull($fresh->chain_sequence, 'no chain sequence may be consumed');
        self::assertNotSame(FiscalStatus::Sealed, $fresh->fiscal_status);

        self::assertSame($entriesBefore, JournalEntry::query()->count(), 'and no journal entry exists');
        self::assertSame(0, JournalEntry::query()->where('source_id', $invoice->id)->count());
    }

    /**
     * The credit-note arm of the same refusal — `assertDocumentGlIsPostable()`
     * covers both fiscal document types, and a lineless avoir is exactly as
     * unpostable as a lineless invoice.
     */
    public function test_a_lineless_credit_note_is_refused_at_posting(): void
    {
        $creditNote = $this->linelessDocument(DocumentType::CreditNote, 'CN-LL-');

        try {
            $this->postingService->post($creditNote);
            $this->fail('post() must refuse a credit note with no lines.');
        } catch (UnpostableDocumentGlException $e) {
            self::assertSame(GlResidualRefusal::LinelessDocument, $e->refusal);
        }

        $fresh = $creditNote->fresh();
        self::assertNotNull($fresh);
        self::assertSame(DocumentStatus::Confirmed, $fresh->status);
        self::assertNull($fresh->fiscal_hash);
    }

    /**
     * The refusal is a correction prompt, not a dead end: adding the missing line
     * makes the SAME document postable. This is what lets the guard be a plain
     * 422 with no repair command behind it.
     */
    public function test_the_refused_lineless_document_posts_once_a_line_is_added(): void
    {
        $invoice = $this->linelessDocument(DocumentType::Invoice, 'INV-LL-FIX-');

        try {
            $this->postingService->post($invoice);
            $this->fail('expected the first attempt to be refused');
        } catch (UnpostableDocumentGlException) {
            // expected
        }

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'The line the document was missing',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        $posted = $this->postingService->post($invoice->fresh(['lines']));

        self::assertSame(DocumentStatus::Posted, $posted->status);
        self::assertNotNull($posted->fiscal_hash);
        self::assertNotNull(
            JournalEntry::query()->where('source_id', $invoice->id)->first(),
            'the corrected document must get its GL entry'
        );
    }

    /**
     * THE CANCELLABILITY HALF OF THE RULING. A document posted BEFORE this lane —
     * seeded directly at `Posted` with its legacy one-legged GL entry, because
     * `post()` can no longer author one — must still cancel, and the carve-out in
     * `reverseDocumentGl()` is what allows it.
     *
     * The French chart has no `SalesStampDutyPayable` account, so the legacy
     * posting really is one-legged: the lone AR debit with no credit side.
     */
    public function test_a_pre_existing_posted_lineless_document_still_cancels(): void
    {
        self::assertNull(
            Account::findByPurpose($this->company->id, SystemAccountPurpose::SalesStampDutyPayable),
            'precondition: the French chart defines no timbre account, so the legacy entry is one-legged'
        );

        $invoice = $this->linelessDocument(DocumentType::Invoice, 'INV-LL-LEGACY-');
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'posted_at' => now(),
        ]);

        // Legacy data, authored the way the pre-O-26 posting path authored it.
        $this->accountingService->createInvoiceGLEntries($invoice->fresh(['lines']));

        $original = JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_SOURCE_TYPE)
            ->where('source_id', $invoice->id)
            ->with('lines')
            ->firstOrFail();
        self::assertCount(1, $original->lines, 'precondition: the legacy shape is a one-legged entry');

        $this->postingService->cancel($invoice->fresh(['lines']), 'legacy lineless', $this->user->id);

        $reversal = JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
            ->where('source_id', $invoice->id)
            ->with('lines')
            ->first();

        self::assertNotNull($reversal, 'a pre-O-26 posted lineless document must still be cancellable');
        self::assertCount(1, $reversal->lines, 'its lone leg is mirrored');
        self::assertSame(
            0,
            bccomp((string) $original->lines->first()->debit, (string) $reversal->lines->first()->credit, 3),
            'the mirror credits exactly what the original debited'
        );
        self::assertSame(DocumentStatus::Cancelled, $invoice->fresh()->status);
    }

    private function linelessDocument(DocumentType $type, string $numberPrefix): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => $type,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $numberPrefix.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
            'balance_due' => '120.00',
        ]);

        $fresh = $document->fresh(['lines']);
        self::assertNotNull($fresh);
        self::assertCount(0, $fresh->lines, 'precondition: the fixture really has no lines');

        return $fresh;
    }
}
