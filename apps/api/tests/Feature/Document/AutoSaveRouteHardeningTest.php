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
