<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Exceptions\UnpostableDocumentGlException;
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
 * O-26 round 2 — close the lineless CREDIT NOTE authoring paths at the source.
 *
 * The posting refusal alone is not a complete remedy for credit notes. An
 * invoice refused at posting is genuinely recoverable: `PATCH /documents/{id}`
 * is open while the document `isEditable()`, so "add a line and post again" is
 * true advice. A credit note has NO update route at all
 * (`Document/Presentation/routes.php:209-231` exposes index / show / store /
 * confirm / post / cancel and nothing else), so the same advice is a lie: the
 * only way out is `POST /credit-notes/{id}/cancel`, which does void an UNPOSTED
 * credit note (`RefundService::cancelCreditNote():858-872` only delegates to the
 * fiscal cancel when the document is already Posted).
 *
 * So this round does two things, and this class pins both:
 *   1. the two authoring paths that could mint a lineless credit note are
 *      refused at CREATION, before the document exists — an `amount: "0"`
 *      credit note, and a full-credit of an already-lineless invoice;
 *   2. the posting refusal's REMEDY sentence is type-aware, so a credit note
 *      that somehow still reaches it is told what actually works.
 *
 * Both HTTP pins exist because a service-level test would not have caught the
 * dead end — the gap was in what the API exposes, not in what the service does.
 */
#[UsesFrozenSeederFixture]
final class LinelessCreditNoteAuthoringGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Lineless CN Tenant',
            'slug' => 'lineless-cn-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Lineless CN Co',
            'legal_name' => 'Lineless CN Co SARL',
            'tax_id' => 'TAX-LCN-'.uniqid(),
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
            'name' => 'Lineless CN User',
            'email' => 'lineless-cn-'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        $this->user->assignRole('admin');
        app(CompanyContext::class)->setCompanyId($this->company->id);

        (new FranceChartOfAccountsSeeder)->run($this->company->id, $this->tenant->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Lineless CN Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);
    }

    /**
     * (a) An amount-based credit note for ZERO is refused at creation.
     *
     * The request validator's money regex (`CreditNoteController:148`) accepts
     * `"0"`, and `CreditNoteService::createCreditNote()` had no positivity check
     * — only its converter sibling did
     * (`InvoiceToCreditNoteConverter::convertAmountBased():154-157`). A zero
     * credit note is meaningless on its own terms, and it was one of the ways to
     * reach a credit note the posting refusal would then strand.
     */
    public function test_an_amount_based_credit_note_for_zero_is_refused_at_creation_over_http(): void
    {
        $invoice = $this->postedInvoiceWithLine();

        $response = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '0',
            'reason' => 'price_adjustment',
        ]);

        $response->assertStatus(422);
        self::assertSame('VALIDATION_ERROR', $response->json('error.code'));
        self::assertStringContainsString('positive', (string) $response->json('error.message'));

        self::assertSame(
            0,
            Document::query()->where('type', DocumentType::CreditNote)->count(),
            'no credit note may be created at all'
        );
    }

    /**
     * A NON-zero amount-based credit note still works — the guard is a floor at
     * zero, not a new restriction on the feature.
     */
    public function test_a_positive_amount_based_credit_note_is_still_created(): void
    {
        $invoice = $this->postedInvoiceWithLine();

        $response = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '12.000',
            'reason' => 'price_adjustment',
        ]);

        self::assertSame(201, $response->status(), 'body: '.$response->getContent());
        self::assertSame(
            1,
            Document::query()->where('type', DocumentType::CreditNote)->count()
        );
    }

    /**
     * (b) A full credit of an ALREADY-LINELESS invoice is refused at creation.
     *
     * `RefundService::createFullCreditNote()` copies the source invoice's lines
     * one for one (`:936-948`). A lineless original therefore produced a lineless
     * credit note — a document that could never post and could only be cancelled.
     * After O-26 no NEW invoice can be posted lineless, so the reachable source is
     * legacy data; refusing here keeps a legacy invoice from spawning a fresh
     * unpostable document.
     */
    public function test_a_full_credit_of_a_lineless_invoice_is_refused_at_creation_over_http(): void
    {
        $invoice = $this->postedInvoiceWithLine();
        // Make it lineless the only way legacy data is: strip the line directly.
        DocumentLine::query()->where('document_id', $invoice->id)->delete();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/invoices/{$invoice->id}/credit-full",
            ['reason' => 'legacy invoice']
        );

        $response->assertStatus(422);
        self::assertStringContainsString('no lines', (string) $response->json('error'));

        self::assertSame(
            0,
            Document::query()->where('type', DocumentType::CreditNote)->count(),
            'no credit note may be created from a lineless invoice'
        );
    }

    /**
     * (c) The posting refusal names the remedy that EXISTS for the type it is
     * refusing. An invoice can be edited; a credit note cannot, so telling a
     * user to "add a line" to one would send them looking for a route that is
     * not there.
     */
    public function test_the_posting_refusal_remedy_is_type_aware(): void
    {
        $invoice = $this->confirmedLinelessDocument(DocumentType::Invoice, 'INV-LCN-');
        $creditNote = $this->confirmedLinelessDocument(DocumentType::CreditNote, 'CN-LCN-');

        $service = app(DocumentPostingService::class);

        try {
            $service->post($invoice);
            $this->fail('the lineless invoice must be refused');
        } catch (UnpostableDocumentGlException $e) {
            self::assertStringContainsString('Add at least one line', $e->getMessage());
            self::assertStringNotContainsString('cancel', strtolower($e->getMessage()));
        }

        try {
            $service->post($creditNote);
            $this->fail('the lineless credit note must be refused');
        } catch (UnpostableDocumentGlException $e) {
            self::assertStringContainsString('cannot be edited', $e->getMessage());
            self::assertStringContainsString('cancel', $e->getMessage());
            self::assertStringNotContainsString('Add at least one line', $e->getMessage());
        }
    }

    private function postedInvoiceWithLine(): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-LCN-SRC-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
            'balance_due' => '120.00',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Source invoice line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        /** @var Document $fresh */
        $fresh = $invoice->fresh(['lines']);

        return app(DocumentPostingService::class)->post($fresh);
    }

    private function confirmedLinelessDocument(DocumentType $type, string $prefix): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => $type,
            'status' => DocumentStatus::Confirmed,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => $prefix.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
            'balance_due' => '120.00',
        ]);

        /** @var Document $fresh */
        $fresh = $document->fresh(['lines']);

        return $fresh;
    }
}
