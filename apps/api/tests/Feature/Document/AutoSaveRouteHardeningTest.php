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

    public function test_auto_save_without_permission_does_not_burn_a_document_number(): void
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
            DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Invoice->value)
                ->count(),
            'An unauthorized auto-save must not allocate a number out of the invoice sequence.'
        );
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
     * The real editor payload, field for field.
     *
     * `DocumentForm.tsx:285-300` builds `draftData` and `buildLinePayload()`
     * (`DocumentForm.tsx:179-199`) builds each line; `useDraftAutoSave.ts:158`
     * merges `draft_id` and POSTs the result. This pins that exact shape so the
     * request contract cannot drift away from the only caller.
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
                'partner_id' => $this->customer->id,
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
    }

    /**
     * Guard rail for the rule above: a client-minted line id is NOT a uuid, and
     * tightening `lines.*.id` to `uuid` would 422 every auto-save of a line the
     * operator just added.
     */
    public function test_a_client_minted_non_uuid_line_id_is_accepted(): void
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
     * See docs/sessions/2026-08-23-p1-autosave-hardening-notes.md §Residual 1.
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

    private function authorizedUser(): User
    {
        return $this->userWithAbilities(['documents.view', 'documents.update']);
    }

    private function actingAsUser(User $user): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id);
    }

    private function documentWithOneLine(
        DocumentStatus $status,
        FiscalStatus $fiscalStatus = FiscalStatus::Draft,
    ): Document {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => $fiscalStatus,
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
