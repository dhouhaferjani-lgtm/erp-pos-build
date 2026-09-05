<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\CreditNoteAllocation;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * F-STG-4 (owner-confirmed paid-invoice scope) — a fully-PAID invoice is a
 * posted invoice that has since been settled (its `DocumentStatus` flips from
 * `Posted` to `Paid`). Returning goods against it is a real-world need: the
 * credit note becomes a customer credit. Previously the source picker excluded
 * paid invoices AND `CreditNoteService` hard-rejected any invoice whose status
 * was not exactly `Posted`, so even a hand-crafted request 422'd.
 *
 * Fix round 1 (gate r1) widened this file from "the request is accepted" to the
 * CONSEQUENCE the relaxation opens:
 *   1. `createCreditNote` / `createLineBasedCreditNote` accept a Paid source,
 *      and the created draft is asserted by its DATA (total, source, partner,
 *      lines), not by a bare 201 (MAJOR-5).
 *   2. A non-INVOICE source document is refused (MAJOR-6).
 *   3. `GET /invoices?creditable=1` surfaces Posted AND Paid invoices, is
 *      validated as a boolean, and is company-scoped (IMPORTANT-1, rule 22).
 *   4. The whole seam — create → confirm → post — against a settled invoice
 *      whose `balance_due` CACHE is blind: the allocation must clamp on the
 *      COMPUTED outstanding and land at 0, while the GL still books the credit
 *      in full (MAJOR-1 + MAJOR-5).
 */
class CreditNotePaidInvoiceSourceTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    /** Second-of-everything (CLAUDE.md rule 22) — proves the new filter is company-scoped. */
    private Company $otherCompany;

    private User $user;

    private Partner $customer;

    private Partner $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Company',
            'legal_name' => 'Second Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        foreach ([$this->company, $this->otherCompany] as $company) {
            UserCompanyMembership::create([
                'user_id' => $this->user->id,
                'company_id' => $company->id,
                'role' => 'admin',
            ]);
            $this->seedAccounts($company);
        }

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);

        $this->otherCustomer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->otherCompany->id,
            'name' => 'Jane Roe',
            'type' => PartnerType::Customer,
        ]);
    }

    /**
     * The system accounts `AccountingService::createCreditNoteGLEntries()` looks
     * up by purpose. Seeded per company so the second-company leg is a real
     * company, not a shell.
     */
    private function seedAccounts(Company $company): void
    {
        $accounts = [
            ['411', 'Customers', 'asset', SystemAccountPurpose::CustomerReceivable],
            ['701', 'Product Sales', 'revenue', SystemAccountPurpose::ProductRevenue],
            ['706', 'Service Revenue', 'revenue', SystemAccountPurpose::ServiceRevenue],
            ['4457', 'VAT Collected', 'liability', SystemAccountPurpose::VatCollected],
            ['709', 'Sales Returns', 'revenue', SystemAccountPurpose::SalesReturn],
        ];

        foreach ($accounts as [$code, $name, $type, $purpose]) {
            Account::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'system_purpose' => $purpose,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  numeric-string|null  $balanceDue  NULL models the documented blind
     *                                           cache (see Document::outstandingBalance()).
     */
    private function makeInvoice(
        DocumentStatus $status,
        string $number,
        ?string $balanceDue,
        ?Company $company = null,
        ?Partner $partner = null,
        ?bool $fullyCredited = null,
    ): Document {
        $company ??= $this->company;
        $partner ??= $this->customer;

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'status' => $status,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '1000.000',
            'tax_amount' => '200.000',
            'total' => '1200.000',
            'balance_due' => $balanceDue,
            'currency' => 'EUR',
            // NULL leaves `payload` absent entirely — the shape of every invoice
            // that has never been credited, and the one a `NOT (flag = true)`
            // predicate would silently drop.
            'payload' => $fullyCredited === null ? null : ['fully_credited' => $fullyCredited],
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Product A',
            'quantity' => '10',
            'unit_price' => '100.000',
            'tax_rate' => '20',
            'line_total' => '1000.000',
        ]);

        return $invoice->load('lines');
    }

    public function test_amount_based_credit_note_can_be_created_from_a_paid_invoice(): void
    {
        // Fully paid → status Paid, zero balance.
        $invoice = $this->makeInvoice(DocumentStatus::Paid, 'INV-PAID-1', '0.000');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '1200.000',
                'reason' => 'return',
                'notes' => 'Return after full payment',
            ]);

        $response->assertCreated();

        // MAJOR-5: assert the DATA, not just the status code.
        $creditNote = Document::findOrFail($response->json('data.id'));
        $this->assertSame(DocumentType::CreditNote, $creditNote->type);
        $this->assertSame(DocumentStatus::Draft, $creditNote->status);
        $this->assertSame($invoice->id, $creditNote->source_document_id);
        $this->assertSame($this->customer->id, $creditNote->partner_id);
        $this->assertSame(0, bccomp((string) $creditNote->total, '1200.000', 3), 'the whole settled invoice is credited');
    }

    public function test_line_based_credit_note_can_be_created_from_a_paid_invoice(): void
    {
        $invoice = $this->makeInvoice(DocumentStatus::Paid, 'INV-PAID-2', '0.000');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'lines' => [
                    ['line_id' => $invoice->lines->first()->id, 'quantity' => '10'],
                ],
                'reason' => 'return',
            ]);

        $response->assertCreated();

        $creditNote = Document::with('lines')->findOrFail($response->json('data.id'));
        $this->assertSame($invoice->id, $creditNote->source_document_id);
        $this->assertCount(1, $creditNote->lines);
        $this->assertSame(0, bccomp((string) $creditNote->lines->first()->quantity, '10', 4));
        $this->assertSame(0, bccomp((string) $creditNote->total, '1200.000', 3));
    }

    public function test_credit_note_still_rejects_a_draft_source_invoice(): void
    {
        $invoice = $this->makeInvoice(DocumentStatus::Draft, 'INV-DRAFT-1', '1200.000');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '1200.000',
                'reason' => 'return',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.message', 'Credit notes can only be created for posted or paid invoices');

        $this->assertSame(0, Document::where('type', DocumentType::CreditNote)->count());
    }

    /**
     * Gate r1 MAJOR-6 — `documents` holds EVERY document type, and the source
     * validator was type-blind, so a posted delivery note (or supplier invoice)
     * minted a customer credit-note draft. The type is now part of the
     * validator AND of `Document::isCreditableInvoiceSource()`.
     */
    public function test_credit_note_rejects_a_non_invoice_source_document(): void
    {
        $deliveryNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::DeliveryNote,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::DeliveryNote),
            'status' => DocumentStatus::Posted,
            'document_number' => 'DN-1',
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '1000.000',
            'tax_amount' => '200.000',
            'total' => '1200.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $deliveryNote->id,
                'amount' => '1200.000',
                'reason' => 'return',
            ]);

        // The API wraps validation failures in `{ error: { code, errors } }`.
        $this->assertJsonValidationErrors($response, ['source_invoice_id']);

        $this->assertSame(0, Document::where('type', DocumentType::CreditNote)->count());

        // The domain guard refuses it too, independently of the HTTP validator.
        $this->assertFalse($deliveryNote->isCreditableInvoiceSource());
    }

    /**
     * Gate r2 NEW-5 — `Rule::exists` queries the raw table, and `Document` is
     * soft-deleting, so without `whereNull('deleted_at')` a soft-deleted invoice
     * validated and then missed the service's soft-delete-scoped `findOrFail()`:
     * a 404/500 where the honest answer is a 422 on `source_invoice_id`.
     */
    public function test_credit_note_rejects_a_soft_deleted_source_invoice(): void
    {
        $invoice = $this->makeInvoice(DocumentStatus::Posted, 'INV-DELETED', '1200.000');
        $invoice->delete();
        $this->assertSoftDeleted('documents', ['id' => $invoice->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '1200.000',
                'reason' => 'return',
            ]);

        $this->assertJsonValidationErrors($response, ['source_invoice_id']);
        $this->assertSame(0, Document::where('type', DocumentType::CreditNote)->count());
    }

    public function test_creditable_filter_returns_posted_and_paid_but_not_draft_invoices(): void
    {
        $posted = $this->makeInvoice(DocumentStatus::Posted, 'INV-POSTED', '1200.000');
        $paid = $this->makeInvoice(DocumentStatus::Paid, 'INV-PAID', '0.000');
        $draft = $this->makeInvoice(DocumentStatus::Draft, 'INV-DRAFT', '1200.000');
        // An invoice explicitly flagged NOT fully credited must still be listed:
        // the JSON predicate has to distinguish `false` from `true`, and
        // RefundService::getCreditNoteSummary() really does write `false`.
        $notCredited = $this->makeInvoice(DocumentStatus::Posted, 'INV-FLAG-FALSE', '1200.000', null, null, false);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/invoices?creditable=1&per_page=50');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($posted->id, $ids);
        $this->assertContains($paid->id, $ids);
        $this->assertContains($notCredited->id, $ids);
        $this->assertNotContains($draft->id, $ids);
    }

    /**
     * Gate r2 NEW-1 — the picker must never offer an invoice that
     * `GET /invoices/{id}/can-credit` calls non-creditable and whose create call
     * 422s on the headroom guard (`CreditNoteService::createCreditNote():845-850`).
     *
     * Falsifying: with the old status-only filter
     * (`whereIn('status', [Posted, Paid])`) the fully-credited invoice is listed
     * and this test fails on the first `assertNotContains`.
     *
     * The last two assertions are the point of the fix: the LIST and the CHECK
     * are now the same predicate (`Document::isCreditableSource()` and its SQL
     * twin `scopeCreditableSource()`), so they cannot disagree.
     */
    public function test_creditable_filter_excludes_a_fully_credited_invoice(): void
    {
        $open = $this->makeInvoice(DocumentStatus::Posted, 'INV-OPEN', '1200.000');
        $exhausted = $this->makeInvoice(DocumentStatus::Posted, 'INV-CREDITED', '1200.000', null, null, true);

        $ids = collect(
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/v1/invoices?creditable=1&per_page=50')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($open->id, $ids);
        $this->assertNotContains($exhausted->id, $ids, 'a fully-credited invoice must never be offered as a credit-note source');

        // The list agrees with the live /can-credit endpoint, both ways.
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$exhausted->id}/can-credit")
            ->assertOk()
            ->assertJsonPath('data.can_credit', false);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$open->id}/can-credit")
            ->assertOk()
            ->assertJsonPath('data.can_credit', true);
    }

    /**
     * Gate r1 IMPORTANT-1 — the flag used to be compared against the literal
     * 'true', so anything else fell through to the UNFILTERED list (drafts
     * included). A filter that hides drafts must fail CLOSED.
     */
    public function test_creditable_filter_rejects_a_non_boolean_value(): void
    {
        $this->makeInvoice(DocumentStatus::Draft, 'INV-DRAFT-2', '1200.000');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/invoices?creditable=yeah&per_page=50');

        $this->assertJsonValidationErrors($response, ['creditable']);
    }

    /**
     * CLAUDE.md rule 22, second-of-everything: the new filter must not become a
     * back door around company scoping.
     */
    public function test_creditable_filter_is_scoped_to_the_current_company(): void
    {
        $mine = $this->makeInvoice(DocumentStatus::Paid, 'INV-CO-A', '0.000');
        $theirs = $this->makeInvoice(
            DocumentStatus::Paid,
            'INV-CO-B',
            '0.000',
            $this->otherCompany,
            $this->otherCustomer,
        );

        $ids = collect(
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/v1/invoices?creditable=1&per_page=50')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, 'company B invoice must never appear in company A list');

        // Switch companies (the real mechanism: the X-Company-Id header, read by
        // CompanyContextMiddleware): B sees its own, and only its own.
        $otherIds = collect(
            $this->actingAs($this->user, 'sanctum')
                ->withHeader('X-Company-Id', $this->otherCompany->id)
                ->getJson('/api/v1/invoices?creditable=1&per_page=50')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($theirs->id, $otherIds);
        $this->assertNotContains($mine->id, $otherIds);
    }

    /**
     * THE SEAM (gate r1 MAJOR-1 + MAJOR-5). Falsifying by construction: with the
     * pre-fix clamp — `(string) ($invoice->balance_due ?? $invoice->total)` —
     * this test allocates 1200.000 instead of 0.000 and fails on the very first
     * allocation assertion.
     *
     * The fixture is the documented blind-cache shape: an invoice SETTLED
     * through real `payment_allocations` rows whose cached `balance_due` is
     * NULL. `Document::outstandingBalance()` recomputes from those rows (0.000);
     * the old fallback read the cache as "nothing paid" and would have absorbed
     * the customer's whole credit into an invoice that owed nothing, while the
     * GL kept the 411 credit — sub-ledger vs GL divergence.
     *
     * The GL half is asserted from the REAL posting path, not a fixture.
     */
    public function test_posting_against_a_settled_invoice_with_a_blind_cache_allocates_zero_and_still_books_the_gl(): void
    {
        $invoice = $this->makeInvoice(DocumentStatus::Paid, 'INV-SETTLED', '0.000');

        // The customer really did pay: a full allocation row exists.
        PaymentAllocation::create([
            'payment_id' => null,
            'document_id' => $invoice->id,
            'amount' => '1200.000',
        ]);

        // ...but the cache is blind. The trigger fires on ALLOCATION DML only,
        // so blanking the column here is exactly the state the demo tenant
        // carries on 165 documents (AgedReceivablesService:199-207).
        DB::table('documents')->where('id', $invoice->id)->update(['balance_due' => null]);
        $this->assertNull(Document::findOrFail($invoice->id)->balance_due);

        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'lines' => [
                    ['line_id' => $invoice->lines->first()->id, 'quantity' => '10'],
                ],
                'reason' => 'return',
            ]);
        $create->assertCreated();
        $creditNoteId = $create->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$creditNoteId}/confirm")
            ->assertOk();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$creditNoteId}/post")
            ->assertOk();

        // (a) The allocation clamps at the COMPUTED outstanding — zero.
        /** @var CreditNoteAllocation $allocation */
        $allocation = CreditNoteAllocation::where('credit_note_id', $creditNoteId)->firstOrFail();
        $this->assertSame(
            0,
            bccomp((string) $allocation->amount, '0', 3),
            'a settled invoice has no headroom: the credit must allocate 0, not the full total'
        );

        // (b) The invoice is still settled, never negative.
        $invoiceAfter = Document::with(['allocations', 'creditsAgainstDocument'])->findOrFail($invoice->id);
        $this->assertSame(
            0,
            bccomp($invoiceAfter->outstandingBalance(3), '0', 3),
            'the settled invoice must stay at zero outstanding'
        );

        // (c) The GL books the credit IN FULL — the customer is owed the money
        // even though no invoice absorbed it. Cr 411 / Dr revenue / Dr VAT.
        $entry = JournalEntry::query()
            ->where('source_type', 'Document')
            ->where('source_id', $creditNoteId)
            ->with('lines')
            ->firstOrFail();

        $arAccount = Account::where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::CustomerReceivable)
            ->firstOrFail();
        $revenueAccount = Account::where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::ProductRevenue)
            ->firstOrFail();
        $vatAccount = Account::where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::VatCollected)
            ->firstOrFail();

        /** @var JournalLine|null $arLine */
        $arLine = $entry->lines->firstWhere('account_id', $arAccount->id);
        $this->assertNotNull($arLine, 'the 411 credit leg must exist');
        $this->assertSame(0, bccomp((string) $arLine->credit, '1200.000', 3), '411 is CREDITED for the full ex-stamp total');
        $this->assertSame(0, bccomp((string) $arLine->debit, '0', 3));
        $this->assertSame($this->customer->id, $arLine->partner_id, 'the AR leg carries the partner so the party balance sees the credit');

        /** @var JournalLine|null $revenueLine */
        $revenueLine = $entry->lines->firstWhere('account_id', $revenueAccount->id);
        $this->assertNotNull($revenueLine, 'the revenue DEBIT leg must exist');
        $this->assertSame(0, bccomp((string) $revenueLine->debit, '1000.000', 3));

        /** @var JournalLine|null $vatLine */
        $vatLine = $entry->lines->firstWhere('account_id', $vatAccount->id);
        $this->assertNotNull($vatLine, 'the VAT DEBIT leg must exist');
        $this->assertSame(0, bccomp((string) $vatLine->debit, '200.000', 3));

        $debits = '0';
        $credits = '0';
        foreach ($entry->lines as $line) {
            $debits = bcadd($debits, (string) $line->debit, 3);
            $credits = bcadd($credits, (string) $line->credit, 3);
        }
        $this->assertSame($credits, $debits, 'the posted entry must balance');
    }
}
