<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentSequence;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DraftPersistenceService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * P1 — `POST /api/v1/documents/auto-save` hardening.
 *
 * Ticket: docs/superpowers/tickets/2026-08-22-lineless-authoring-paths-hardening.md §1.
 *
 * The route shipped with, together: no permission gate (any authenticated user,
 * including a read-only `viewer`, could reach it), no status/type filter (it
 * REPLACED the line set of an already-`Confirmed` document), and no payload
 * validation at all. Because `DraftPersistenceService::createNewDraft()`
 * allocates a `document_sequences` number for every new draft, the ungated route
 * let a caller with zero document permissions burn numbers out of the same
 * sequence that later feeds the fiscal hash chain.
 *
 * What is deliberately NOT refused here: a DRAFT document with zero lines. That
 * is a legitimate intermediate editor state, and owner ruling O-26 already
 * refuses a lineless document at POSTING.
 */
final class AutoSaveRouteHardeningTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private Partner $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Autosave Tenant',
            'slug' => 'autosave-hardening',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Autosave Co',
            'legal_name' => 'Autosave Co LLC',
            'tax_id' => 'TAX-AUTOSAVE',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Autosave Customer',
            'type' => 'customer',
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Autosave Supplier',
            'type' => 'supplier',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Autosave Product',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // 1. Authorization — the route must carry a `can:` gate
    // ──────────────────────────────────────────────────────────────────

    public function test_auto_save_is_refused_for_a_user_without_document_write_permission(): void
    {
        $viewer = $this->userWithAbilities(['documents.view']);

        $this->actingAsUser($viewer)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100],
                ],
            ])
            ->assertStatus(403);

        $this->assertSame(
            0,
            Document::query()->count(),
            'An unauthorized auto-save must not author a document.'
        );
    }

    /**
     * Gate F4 / AUTHZ: pin the sequence VALUE, not the absence of a row.
     *
     * The first version asserted `count() === 0`, which held only because
     * nothing else in the test created an invoice — it never read
     * `last_number`, so it would have gone green for the wrong reason the
     * moment sequence rows are pre-seeded at company creation. The row is now
     * seeded at a known value and the assertion is that the value is untouched.
     */
    public function test_auto_save_without_permission_does_not_burn_a_document_number(): void
    {
        $viewer = $this->userWithAbilities(['documents.view']);

        $sequence = DocumentSequence::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice->value,
            'year' => (int) date('Y'),
            'last_number' => 7,
        ]);

        $this->actingAsUser($viewer)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100],
                ],
            ])
            ->assertStatus(403);

        $this->assertSame(
            7,
            (int) $sequence->refresh()->last_number,
            'An unauthorized auto-save must not advance last_number on the invoice sequence.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 1b. Authorization — the per-TYPE create ability (gate P1-2 / P2-3)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Gate P2-3. `documents.update` alone was a universal document-authoring
     * bypass around the entire per-type `*.create` catalogue: the gate probe
     * authored `PO-2026-0001` with a principal holding no `purchase-orders.*`
     * permission at all, burning a number out of the PO sequence.
     */
    public function test_auto_save_refuses_a_type_the_caller_cannot_create(): void
    {
        // The seeded `cashier` shape: document-write, invoices.create, but no
        // purchase-orders.* at all (RolesAndPermissionsSeeder.php:646-679).
        $cashier = $this->userWithAbilities([
            'documents.view', 'documents.update', 'quotes.create', 'invoices.create',
        ]);

        $sequence = DocumentSequence::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::PurchaseOrder->value,
            'year' => (int) date('Y'),
            'last_number' => 3,
        ]);

        $this->actingAsUser($cashier)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::PurchaseOrder->value,
                'partner_id' => $this->supplier->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100],
                ],
            ])
            ->assertStatus(403);

        $this->assertSame(0, Document::query()->count());
        $this->assertSame(
            3,
            (int) $sequence->refresh()->last_number,
            'A caller without purchase-orders.create must not burn a PO number.'
        );
    }

    public function test_auto_save_allows_a_type_the_caller_can_create(): void
    {
        $buyer = $this->userWithAbilities([
            'documents.view', 'documents.update', 'purchase-orders.create',
        ]);

        $this->actingAsUser($buyer)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::PurchaseOrder->value,
                'partner_id' => $this->supplier->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(1, Document::query()->count());
    }

    /**
     * Gate P2-3, update branch: the per-type ability is required for an
     * EXISTING draft too, not just for creation.
     */
    public function test_auto_save_refuses_an_existing_draft_of_a_type_the_caller_cannot_create(): void
    {
        $cashier = $this->userWithAbilities(['documents.view', 'documents.update', 'quotes.create']);
        $draft = $this->documentWithOneLine(DocumentStatus::Draft);

        $this->actingAsUser($cashier)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $draft->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ])
            ->assertStatus(403);

        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $draft->id)->count(),
            'A caller without invoices.create must not strip an invoice draft.'
        );
    }

    /**
     * Gate R2-1 [CRITICAL] — the SPOOF. The test above passes only because it
     * sends the honest `type`.
     *
     * `authorize()` decides against the client-supplied `type`, and on the update
     * branch the service then IGNORES it: `$data['type']` is read in exactly one
     * place, `createNewDraft()`. So the same cashier principal, against the same
     * invoice draft, with one string changed, was answered 200 and stripped every
     * line:
     *
     *     [HONEST type=invoice] 403, lines=1
     *     [SPOOF  type=quote  ] 200, lines=0
     *
     * Same failure shape as round 1's P1-1: a test that substitutes away the one
     * value that breaks it.
     */
    public function test_auto_save_refuses_a_spoofed_type_that_disagrees_with_the_persisted_draft(): void
    {
        $cashier = $this->userWithAbilities(['documents.view', 'documents.update', 'quotes.create']);
        $draft = $this->documentWithOneLine(DocumentStatus::Draft);

        $response = $this->actingAsUser($cashier)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $draft->id,
                // The caller HOLDS quotes.create — so the per-type gate lets this
                // through — but the target is an INVOICE draft.
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $response->assertStatus(422);
        $this->assertApiErrorCode($response, 'DOCUMENT_TYPE_MISMATCH');

        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $draft->id)->count(),
            'A spoofed type must not strip the lines off a draft of a different type.'
        );
        $this->assertSame(
            DocumentType::Invoice,
            $draft->refresh()->type,
            'The persisted type must never be rewritten by the request.'
        );
    }

    /**
     * Gate R2-1, second half — the spoof re-opened P1-2 on the update branch.
     *
     * Correcting entries are created `DocumentStatus::Draft` /
     * `FiscalStatus::Draft` (`CorrectingEntryService::create()`), so
     * `assertDraftEditable()` waves them through. Refusing `correcting_entry` at
     * the validator only stops AUTHORING one — a `quotes.create` holder could
     * still send `type: quote` at an existing CE draft and strip the lines off a
     * document whose every route, including the reads, is admin-tier by owner
     * ruling.
     */
    public function test_auto_save_refuses_a_spoofed_type_aimed_at_a_correcting_entry_draft(): void
    {
        $cashier = $this->userWithAbilities(['documents.view', 'documents.update', 'quotes.create']);
        $correction = $this->draftOfType(DocumentType::CorrectingEntry, FiscalCategory::NonFiscal);

        $response = $this->actingAsUser($cashier)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $correction->id,
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $response->assertStatus(422);
        $this->assertApiErrorCode($response, 'DOCUMENT_TYPE_MISMATCH');

        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $correction->id)->count(),
            'A correcting-entry draft must not be strippable through auto-save.'
        );
    }

    /**
     * The honest counterpart: a caller who holds the right ability AND sends the
     * matching type still works. Without this, the guard above could be
     * satisfied by refusing every update.
     */
    public function test_auto_save_accepts_a_matching_type_on_an_existing_draft(): void
    {
        $author = $this->authorizedUser();
        $draft = $this->documentWithOneLine(DocumentStatus::Draft);

        $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $draft->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ])
            ->assertStatus(200);

        $this->assertSame(0, DocumentLine::query()->where('document_id', $draft->id)->count());
    }

    /**
     * Gate P1-2. `correcting_entry` is the single most privileged document
     * type: every correcting-entry route, INCLUDING the reads, is gated on the
     * admin-tier `documents.correct` (the `correcting-entries.*` route block) because it "both
     * exposes and writes raw general-ledger accounts and amounts". A bare
     * `Rule::enum(DocumentType::class)` let a `documents.update` holder author
     * `CE-2026-0001` through auto-save and burn a CE number.
     *
     * It is refused by the CONTRACT (422), not by authorization (403):
     * correcting entries have a dedicated authoring route and no auto-save
     * flow to preserve, so the type is simply not in the accepted set.
     */
    public function test_auto_save_refuses_a_correcting_entry_type(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::CorrectingEntry->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $this->assertApiValidationErrors($response, ['type']);
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(
            0,
            DocumentSequence::query()->where('type', DocumentType::CorrectingEntry->value)->count(),
            'No CE sequence number may be burned through auto-save.'
        );
    }

    /**
     * The other five document types the editor never auto-saves are refused by
     * the same contract narrowing.
     */
    public function test_auto_save_refuses_document_types_the_editor_never_auto_saves(): void
    {
        foreach ([
            DocumentType::Expense,
            DocumentType::Income,
            DocumentType::SupplierInvoice,
            DocumentType::SupplierCreditNote,
            DocumentType::PurchaseQuoteRequest,
        ] as $type) {
            $response = $this->actingAsUser($this->authorizedUser())
                ->postJson('/api/v1/documents/auto-save', [
                    'type' => $type->value,
                    'partner_id' => $this->customer->id,
                    'lines' => [],
                ]);

            $this->assertApiValidationErrors($response, ['type']);
        }

        $this->assertSame(0, Document::query()->count());
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. Status guard — auto-save may only mutate a DRAFT
    // ──────────────────────────────────────────────────────────────────

    public function test_auto_save_refuses_to_strip_the_lines_off_a_confirmed_document(): void
    {
        $author = $this->authorizedUser();
        $invoice = $this->documentWithOneLine(DocumentStatus::Confirmed);

        $response = $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $invoice->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $response->assertStatus(422);
        $this->assertApiErrorCode($response, 'DOCUMENT_NOT_EDITABLE');

        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $invoice->id)->count(),
            'A confirmed document must keep its lines when auto-save refuses.'
        );
    }

    public function test_auto_save_refuses_to_mutate_a_posted_document(): void
    {
        $author = $this->authorizedUser();
        $invoice = $this->documentWithOneLine(DocumentStatus::Posted);

        $response = $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $invoice->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $response->assertStatus(422);

        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $invoice->id)->count(),
            'A posted document must keep its lines when auto-save refuses.'
        );
    }

    public function test_auto_save_refuses_to_mutate_a_cancelled_document(): void
    {
        $author = $this->authorizedUser();
        $invoice = $this->documentWithOneLine(DocumentStatus::Cancelled);

        $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $invoice->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ])
            ->assertStatus(422);

        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $invoice->id)->count(),
            'A cancelled document must keep its lines when auto-save refuses.'
        );
    }

    public function test_auto_save_refuses_to_mutate_a_fiscally_sealed_draft(): void
    {
        $author = $this->authorizedUser();
        $invoice = $this->documentWithOneLine(DocumentStatus::Draft, FiscalStatus::Sealed);

        $response = $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $invoice->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $response->assertStatus(422);
        $this->assertApiErrorCode($response, 'DOCUMENT_SEALED');

        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $invoice->id)->count(),
            'A sealed document must keep its lines when auto-save refuses.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. Payload validation (precision contract rule 19)
    // ──────────────────────────────────────────────────────────────────

    public function test_auto_save_rejects_a_quantity_beyond_four_decimals(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '1.00001', 'unit_price' => '10'],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.quantity']);
        $this->assertSame(0, Document::query()->count());
    }

    public function test_auto_save_rejects_a_unit_price_beyond_three_decimals(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10.0001'],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.unit_price']);
        $this->assertSame(0, Document::query()->count());
    }

    public function test_auto_save_rejects_a_non_numeric_quantity(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 'not-a-number', 'unit_price' => '10'],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.quantity']);
    }

    public function test_auto_save_rejects_an_unknown_document_type(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => 'not_a_document_type',
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $this->assertApiValidationErrors($response, ['type']);
    }

    public function test_auto_save_rejects_a_missing_document_type(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $this->assertApiValidationErrors($response, ['type']);
    }

    public function test_auto_save_rejects_a_non_uuid_draft_id(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => 'not-a-uuid',
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $this->assertApiValidationErrors($response, ['draft_id']);
    }

    public function test_auto_save_rejects_a_lines_payload_that_is_not_an_array(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => 'nope',
            ]);

        $this->assertApiValidationErrors($response, ['lines']);
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. Happy paths — the editor's keystroke auto-save must keep working
    // ──────────────────────────────────────────────────────────────────

    public function test_a_permitted_user_can_auto_save_a_new_draft(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '2', 'unit_price' => '10.500'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('line_count', 1);

        $draftId = $response->json('draft_id');
        $this->assertIsString($draftId);

        $document = Document::query()->findOrFail($draftId);
        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertSame(1, DocumentLine::query()->where('document_id', $draftId)->count());
    }

    public function test_a_permitted_user_can_auto_save_an_existing_draft_and_replace_its_lines(): void
    {
        $author = $this->authorizedUser();
        $draft = $this->documentWithOneLine(DocumentStatus::Draft);

        $response = $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $draft->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '3', 'unit_price' => '5'],
                    ['product_id' => $this->product->id, 'quantity' => '4', 'unit_price' => '6'],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertSame(
            2,
            DocumentLine::query()->where('document_id', $draft->id)->count(),
            'Auto-save must still replace the line set of a genuine draft.'
        );
    }

    /**
     * N-14. A form that is opened and abandoned must not spend a document
     * number. The CREATE branch used to author a numbered `Draft` for any
     * payload at all — the campaign found `PO-2026-0001 … PO-2026-0009` sitting
     * as orphan drafts ahead of the operator's real `PO-2026-0010`
     * (PLAYWRIGHT-first-tenant-campaign-wave2-imports-2026-08-24 §N-14).
     *
     * The allocation now waits for the first LINE. Nothing else about the
     * endpoint changes: an existing draft may still be emptied (the operator
     * clearing the grid, pinned by the sibling test below), and the number a
     * draft already holds is never taken back.
     */
    public function test_auto_save_without_a_line_does_not_author_a_document_or_burn_a_number(): void
    {
        $sequence = DocumentSequence::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Quote->value,
            'year' => (int) date('Y'),
            'last_number' => 4,
        ]);

        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('draft_id', null);
        $response->assertJsonPath('line_count', 0);

        $this->assertSame(
            0,
            Document::query()->where('type', DocumentType::Quote)->count(),
            'A lineless auto-save must author no document at all.'
        );
        $this->assertSame(
            4,
            (int) $sequence->refresh()->last_number,
            'and it must not advance the quote sequence.'
        );
    }

    /**
     * The other half of the same rule: the FIRST line is what allocates, and it
     * allocates the number the abandoned form would otherwise have spent.
     */
    public function test_the_first_line_allocates_the_number_the_empty_form_did_not_spend(): void
    {
        $author = $this->authorizedUser();

        $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ])
            ->assertStatus(200);

        $response = $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10'],
                ],
            ]);

        $response->assertStatus(200);
        $draftId = $response->json('draft_id');
        $this->assertIsString($draftId);

        $document = Document::query()->findOrFail($draftId);
        $this->assertStringEndsWith(
            '0001',
            (string) $document->document_number,
            'The abandoned empty form must not have consumed the first number.'
        );
    }

    /**
     * A payload that omits `lines` entirely is the same case as an empty array
     * on the CREATE branch — there is no line to justify a number.
     */
    public function test_auto_save_with_no_lines_key_does_not_author_a_document(): void
    {
        $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('draft_id', null);

        $this->assertSame(0, Document::query()->where('type', DocumentType::Quote)->count());
    }

    /**
     * O-26 note: a lineless DRAFT stays legal — the editor auto-saves one every
     * time the operator clears the grid mid-session. The posting refusal, not
     * this route, is what stops it becoming a lineless posted document.
     */
    public function test_an_empty_lines_array_is_still_accepted_on_a_draft(): void
    {
        $author = $this->authorizedUser();
        $draft = $this->documentWithOneLine(DocumentStatus::Draft);

        $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $draft->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ])
            ->assertStatus(200);

        $this->assertSame(
            0,
            DocumentLine::query()->where('document_id', $draft->id)->count(),
            'Clearing the grid on a draft must remain a legal auto-save.'
        );
    }

    /**
     * The real editor payload, field for field — with the ONE value the first
     * round substituted away.
     *
     * `DocumentForm.tsx:285-300` builds `draftData` and `buildLinePayload()`
     * (`DocumentForm.tsx:179-199`) builds each line; `useDraftAutoSave.ts:158`
     * merges `draft_id` and POSTs the result. This pins that exact shape so the
     * request contract cannot drift away from the only caller.
     *
     * Gate P1-1: `partner_id` is **null** here, because that is what the editor
     * sends until the operator picks a partner — `DocumentForm.tsx:263`
     * defaults it to null and `:286` emits `watchedPartnerId || null`, while the
     * debounce only requires one line (`useDraftAutoSave.ts:227`). The first
     * round sent `$this->customer->id` instead, which is exactly the value that
     * was NOT broken, so the test was an unearned green.
     *
     * Note `line_total` / `price_entry_mode` / `discount_percent` /
     * `discount_amount`: the editor sends them, the persistence service reads
     * none of them, and the request deliberately declares no rules for them —
     * they must be ACCEPTED and dropped, never rejected.
     */
    public function test_the_editors_exact_auto_save_payload_is_accepted(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => null,
                'type' => DocumentType::Quote->value,
                'partner_id' => null,
                'notes' => null,
                'document_date' => now()->format('Y-m-d'),
                'due_date' => null,
                'lines' => [
                    [
                        // Client-minted id — DocumentLineEditor.tsx:328.
                        'id' => 'line-1756000000000-a1b2c3d4e',
                        'product_id' => $this->product->id,
                        'quantity' => '2.0000',
                        'unit_price' => '10.500',
                        'line_total' => '21.000',
                        'price_entry_mode' => 'unit',
                        'discount_percent' => null,
                        'discount_amount' => null,
                        'tax_rate' => '19.00',
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('line_count', 1);
        $response->assertJsonMissingPath('error');

        $this->assertSame(
            1,
            Document::query()->count(),
            'The editor first-keystroke payload must actually author a draft.'
        );
    }

    /**
     * Gate P1-1, isolated. Before the fix this returned
     * `200 {"error":"silent_failure"}` and created NOTHING:
     * `DraftPersistenceService::createNewDraft()` did `$partner->name` on a null
     * relation, the ErrorException hit the blanket `catch (\Throwable) → 200`,
     * and the transaction rolled back. Every auto-save before the operator
     * picked a partner authored nothing while reporting success.
     *
     * `DraftDocumentCreated::$partnerName` is already `?string` (event
     * constructor `:26-27`), so the null-safe read is rule-8 clean.
     */
    public function test_a_null_partner_auto_save_authors_a_draft_instead_of_silently_failing(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => null,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonMissingPath('error');

        $document = Document::query()->firstOrFail();
        $this->assertNull($document->partner_id);
        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $document->id)->count(),
            'The lines typed before a partner was chosen must be persisted, not discarded.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. Fiscal correctness of the authored row (gate F1)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Gate F1. `createNewDraft()` never set `currency`, so the row silently took
     * the migration default `'EUR'`
     * (`2025_11_30_080000_create_documents_table.php:24`). `currency` is the 4th
     * field of the fiscal hash payload (`DocumentPostingService.php:484` →
     * `FiscalHashService::serializeForHashing()`) and posting does NOT re-derive
     * it — so a TND tenant that confirmed and posted an auto-saved draft sealed
     * `'EUR'` into the SHA-256 chain input.
     *
     * Every other creator in the module sets it explicitly
     * (`DraftPurchaseOrderService.php:59`, `QuoteController.php:257`,
     * `CorrectingEntryService.php:82`, `CreditNoteService.php:877`,
     * `ArApOpeningService.php:304`, `POSAccountChargeDraftService.php:60`);
     * `createNewDraft()` was the anomaly.
     */
    public function test_auto_save_persists_the_companys_currency_not_the_column_default(): void
    {
        [$user, $company, $partner, $product] = $this->tunisianCompanyFixture();

        $this->actingAsUser($user, $company)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $partner->id,
                'lines' => [
                    ['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '10'],
                ],
            ])
            ->assertStatus(200);

        $document = Document::query()->where('company_id', $company->id)->firstOrFail();

        $this->assertSame(
            'TND',
            $document->currency,
            'An auto-saved draft must carry the company currency, not the EUR column default.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 6. Concurrency (gate P2-4)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Gate P2-4. The editability guard read the row with a plain `find()` inside
     * `DB::transaction()`. `config/database.php` sets no isolation level, so
     * PostgreSQL's default READ COMMITTED applies and an unlocked SELECT takes
     * no row lock: auto-save could read `Draft`, a concurrent session commit
     * `confirm`, and auto-save then strip the lines off a now-Confirmed
     * document — the lane's own harm, narrowed to a race window.
     *
     * The confirm side already locks (`InvoiceController::confirm()`,
     * "Re-fetch with pessimistic lock inside transaction to prevent race
     * conditions") and the cited draft-service precedent locks
     * (`DraftPurchaseOrderService::appendLines()`), so auto-save was the only
     * participant that did not — the pair did not serialise.
     *
     * Asserted structurally rather than by racing two connections: the suite
     * runs on SQLite `:memory:` (`phpunit.xml:44-45`) whose grammar compiles
     * `lockForUpdate()` to nothing, so a query-log assertion would pass
     * vacuously on the gate DB. Structural source assertions are the in-repo
     * pattern for exactly this
     * (`RefundResidualTenantIsolationTest::test_*_query_includes_*_predicates`).
     */
    public function test_the_draft_fetch_takes_a_row_lock_inside_the_transaction(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(DraftPersistenceService::class))
                ->getFileName()
        );

        // Gate R2-4: bound the window structurally — from `saveDraft`'s
        // signature to the start of the next method declaration — instead of a
        // magic character count. A fixed-size window goes red as soon as a
        // comment above the query grows past it, which is a failure that says
        // nothing about the lock.
        $start = strpos($source, 'public function saveDraft');
        $this->assertNotFalse($start, 'saveDraft() must exist.');

        $rest = substr($source, $start + 1);
        $nextMethod = preg_match('/\n    (?:public|private|protected) function /', $rest, $m, PREG_OFFSET_CAPTURE) === 1
            ? (int) $m[0][1]
            : strlen($rest);
        $body = substr($rest, 0, $nextMethod);

        $this->assertMatchesRegularExpression(
            '/Document::query\(\)(?:\s|.)*?->lockForUpdate\(\)/',
            $body,
            'saveDraft() must fetch the target draft with lockForUpdate() so the guard serialises '
            .'against a concurrent confirm (which already locks — see InvoiceController::confirm()).'
        );
    }

    /**
     * Guard rail for the client-minted id rule: the id is not a uuid, and
     * tightening `lines.*.id` to `uuid` would 422 every auto-save of a line the
     * operator just added.
     *
     * ACCEPTED BY THE VALIDATOR ONLY — this payload is *processed* wrongly: see
     * `test_characterisation_client_minted_line_ids_empty_the_draft` below,
     * which proves the very same request destroys the draft's line set.
     */
    public function test_a_client_minted_non_uuid_line_id_is_accepted_by_the_validator(): void
    {
        $author = $this->authorizedUser();
        $draft = $this->documentWithOneLine(DocumentStatus::Draft);

        $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $draft->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    [
                        'id' => 'line-1756000000001-zz9yy8xx7',
                        'product_id' => $this->product->id,
                        'quantity' => '1',
                        'unit_price' => '10',
                    ],
                ],
            ])
            ->assertStatus(200);
    }

    /**
     * CHARACTERISATION — pre-existing defect, NOT fixed by this lane.
     *
     * This is the real mechanism by which `/documents/auto-save` authors a
     * LINELESS draft in production, and the blast-radius review of this ticket
     * is what surfaced it. It is recorded here so the behaviour is visible and
     * so the fix, when it comes, has a test that must flip.
     *
     * The editor mints CLIENT ids (`line-<epoch>-<rand>`) for unsaved lines and
     * never learns the server uuids back — the auto-save response carries only
     * `draft_id` / `saved_at` / `line_count`. So on the SECOND auto-save of a
     * new document, `DraftPersistenceService::updateDraftLines()`:
     *   - diffs the server uuids against the client ids → every existing line
     *     counts as removed, and is deleted;
     *   - then, for each incoming line, sees `isset($lineData['id'])`, fails to
     *     match it against any line, and adds NOTHING (the `else` arm that would
     *     call `addLine()` is only reached when `id` is absent).
     * Net: the draft is emptied and stays empty for the rest of the session.
     *
     * NOT repaired here: the repair is a contract decision between the editor
     * and this endpoint (echo the server line ids back, or treat an unmatched id
     * as a new line), and the second option churns a DraftLineRemoved +
     * DraftLineAdded pair per line per keystroke through the fraud-detection
     * event stream. That is a design call, not a clean guard.
     *
     * The fraud stream is ALREADY polluted by this today, not only under a
     * future fix: `DraftPersistenceService::removeLine()` fires `DraftLineRemoved` and
     * `DraftLineRemovedV2` BEFORE its own `$line->delete()`, so the deletion
     * burst emits real removal events for lines the operator never removed.
     *
     * See docs/superpowers/tickets/2026-08-23-autosave-residuals.md §R-1.
     */
    public function test_characterisation_client_minted_line_ids_empty_the_draft(): void
    {
        $author = $this->authorizedUser();
        $draft = $this->documentWithOneLine(DocumentStatus::Draft);

        $response = $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $draft->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    [
                        'id' => 'line-1756000000002-qq1ww2ee3',
                        'product_id' => $this->product->id,
                        'quantity' => '1',
                        'unit_price' => '10',
                    ],
                ],
            ]);

        $response->assertStatus(200);

        // …and the response does not even report it. `line_count` reads
        // `$document->lines->count()` off the relation collection that was
        // loaded BEFORE the deletes, so the endpoint answers "1 line saved"
        // while the database holds none. A second, smaller residual of the
        // same review.
        $response->assertJsonPath('line_count', 1);

        $this->assertSame(
            0,
            DocumentLine::query()->where('document_id', $draft->id)->count(),
            'Characterisation: the client-id line set neither matched nor was added, '
            .'and the pre-existing server line was deleted. When this is fixed, this '
            .'assertion must be inverted, not deleted.'
        );
    }

    /**
     * CHARACTERISATION (gate F8) — the whole ticket thesis in one flow, driven
     * ONLY through the endpoint, with no hand-seeded server line.
     *
     * POST #1 authors the draft and burns a number; POST #2 replays the same
     * client-minted ids the editor still holds, and the document ends LINELESS
     * with the number spent. This is the faithful two-save pin the first round's
     * "editor's exact payload" test could not give, because a null `draft_id`
     * takes the create branch where `id` is ignored.
     *
     * Fails-to-red when Residual 1 is fixed — invert, do not delete.
     */
    public function test_characterisation_two_editor_saves_end_lineless_with_a_burnt_number(): void
    {
        $author = $this->authorizedUser();

        $line = [
            'id' => 'line-1756000000010-aaa111bbb',
            'product_id' => $this->product->id,
            'quantity' => '2.0000',
            'unit_price' => '10.500',
            'tax_rate' => '19.00',
        ];

        $first = $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => null,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [$line],
            ]);
        $first->assertStatus(200);

        $draftId = $first->json('draft_id');
        $this->assertIsString($draftId);
        $this->assertSame(1, DocumentLine::query()->where('document_id', $draftId)->count());

        // The editor never learns the server uuid, so the next debounce replays
        // the same client id (useDraftAutoSave.ts:158-164 sends draft_id + the
        // unchanged `lines` from DocumentForm.tsx:294-298).
        $this->actingAsUser($author)
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $draftId,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [$line],
            ])
            ->assertStatus(200);

        $this->assertSame(
            0,
            DocumentLine::query()->where('document_id', $draftId)->count(),
            'Characterisation: a document authored entirely through auto-save ends lineless.'
        );

        $this->assertSame(
            1,
            (int) DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Invoice->value)
                ->value('last_number'),
            'Characterisation: and the invoice number it burned is spent on a lineless row.'
        );
    }

    /**
     * CHARACTERISATION (gate P3-8) — the cross-tenant `draft_id` path, which had
     * no test at all: the strongest tenancy property of this endpoint was
     * unpinned.
     *
     * ISOLATION HOLDS — the foreign document is never read or mutated, because
     * the lookup in `saveDraft()` is tenant+company scoped (and row-locked).
     * But the miss falls through to `createNewDraft()`, so the caller gets a 200
     * with a DIFFERENT `draft_id` instead of a 404. Recorded, not changed:
     * refusing an unresolvable non-null `draft_id` is residual R-7's contract
     * change, not this lane's.
     *
     * INVERTED IN PART by N-14 (as the residuals ticket requires of a
     * characterisation that a later lane fixes): the miss no longer BURNS A
     * NUMBER on a lineless payload, because the create branch now refuses to
     * author anything without a line. The second probe below keeps R-7's
     * remaining half — a payload that does carry a line — characterised.
     */
    public function test_characterisation_a_foreign_tenant_draft_id_authors_a_new_document(): void
    {
        $foreignTenant = Tenant::create([
            'name' => 'Foreign Tenant',
            'slug' => 'autosave-foreign',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $foreignCompany = Company::create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Foreign Co',
            'legal_name' => 'Foreign Co LLC',
            'tax_id' => 'TAX-FOREIGN',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $foreignPartner = Partner::create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
            'name' => 'Foreign Customer',
            'type' => 'customer',
        ]);
        $foreignDraft = Document::create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
            'partner_id' => $foreignPartner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-FOREIGN-1',
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);
        $foreignDraft->lines()->create([
            'product_id' => null,
            'line_number' => 1,
            'description' => 'Foreign line',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => 0,
            'line_total' => '100.000',
        ]);

        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $foreignDraft->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [],
            ]);

        $response->assertStatus(200);

        // The property that matters, and that had no test: no foreign mutation.
        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $foreignDraft->id)->count(),
            'A foreign tenant draft must never be touched.'
        );
        $this->assertNotSame(
            $foreignDraft->id,
            $response->json('draft_id'),
            'The foreign id must not be adopted.'
        );

        // N-14: with no line there is nothing to author, so the unresolvable id
        // no longer costs a number.
        $this->assertNull($response->json('draft_id'), 'A lineless miss must author nothing at all.');
        $this->assertSame(
            0,
            Document::query()->where('company_id', $this->company->id)->count(),
            'A lineless unresolvable draft_id must not author a document.'
        );

        // Characterised, not endorsed (residual R-7): WITH a line, the miss still
        // authors a new local draft instead of 404ing.
        $withLine = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $foreignDraft->id,
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10'],
                ],
            ]);

        $withLine->assertStatus(200);
        $this->assertNotSame($foreignDraft->id, $withLine->json('draft_id'));
        $this->assertSame(
            1,
            DocumentLine::query()->where('document_id', $foreignDraft->id)->count(),
            'A foreign tenant draft must never be touched.'
        );
        $this->assertSame(
            1,
            Document::query()->where('company_id', $this->company->id)->count(),
            'Characterisation: an unresolvable draft_id authors a NEW document instead of 404ing.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $abilities
     */
    private function userWithAbilities(array $abilities): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Autosave User',
            'email' => 'autosave-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $user->givePermissionTo($abilities);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
        ]);

        return $user;
    }

    /**
     * A principal shaped like the seeded `manager`: the coarse document-write
     * permission PLUS the per-type create abilities for every family the
     * editor auto-saves (gate P2-3).
     */
    private function authorizedUser(): User
    {
        return $this->userWithAbilities([
            'documents.view',
            'documents.update',
            'quotes.create',
            'orders.create',
            'invoices.create',
            'credit-notes.create',
            'purchase-orders.create',
            'deliveries.create',
        ]);
    }

    private function actingAsUser(User $user, ?Company $company = null): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', ($company ?? $this->company)->id);
    }

    /**
     * A second company in the same tenant whose currency is NOT the `documents`
     * table's `'EUR'` default, with its own partner + product so the
     * company-scoped validation rules resolve. Gate F1.
     *
     * @return array{0: User, 1: Company, 2: Partner, 3: Product}
     */
    private function tunisianCompanyFixture(): array
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Autosave TN',
            'legal_name' => 'Autosave TN SARL',
            'tax_id' => 'TAX-AUTOSAVE-TN',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $user = $this->userWithAbilities(['documents.view', 'documents.update', 'quotes.create']);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Manager,
        ]);

        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'TN Customer',
            'type' => 'customer',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'TN Product',
        ]);

        return [$user, $company, $partner, $product];
    }

    /**
     * A DRAFT document of an arbitrary type with one line — for the spoof tests,
     * where the target's persisted type is the whole point.
     *
     * Correcting entries are `FiscalCategory::NonFiscal` by owner ruling
     * (`CorrectingEntryService::create()`), which is also what keeps them out of
     * the `chk_fiscal_mandatory_core` CHECK on PostgreSQL.
     */
    private function draftOfType(DocumentType $type, FiscalCategory $category): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => $type,
            'fiscal_category' => $category,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'DOC-AS-'.bin2hex(random_bytes(3)),
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        $document->lines()->create([
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Autosave Product',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => 0,
            'line_total' => '100.000',
        ]);

        return $document->refresh();
    }

    /**
     * A fiscal invoice with one line.
     *
     * A non-DRAFT `fiscal_status` MUST carry `fiscal_hash` + `chain_sequence`:
     * PostgreSQL enforces `chk_fiscal_mandatory_core`
     * (`2026_03_10_300000_fix_fiscal_constraints_for_drafts.php:22-35`), which
     * exempts only `NON_FISCAL` and `DRAFT` rows. SQLite does not enforce CHECK
     * constraints of this shape, so a fixture missing them passes the default
     * `:memory:` gate and only fails on PG — which is exactly what the PG leg of
     * this lane's verification caught. Same shape as the sealed fixtures in
     * `RefundResidualTenantIsolationTest::setUp()`.
     */
    private function documentWithOneLine(
        DocumentStatus $status,
        FiscalStatus $fiscalStatus = FiscalStatus::Draft,
    ): Document {
        $sealed = $fiscalStatus !== FiscalStatus::Draft;

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => $fiscalStatus,
            'fiscal_hash' => $sealed ? hash('sha256', uniqid('seal-', true)) : null,
            'chain_sequence' => $sealed ? 1 : null,
            'status' => $status,
            'document_number' => 'INV-AS-'.bin2hex(random_bytes(3)),
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        $document->lines()->create([
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Autosave Product',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => 0,
            'line_total' => '100.000',
        ]);

        return $document->refresh();
    }
}
