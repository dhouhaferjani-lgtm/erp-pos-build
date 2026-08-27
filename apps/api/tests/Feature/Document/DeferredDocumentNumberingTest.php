<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentSequence;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * R-2 / LEDGER D-T9-1 — DEFERRED DOCUMENT NUMBERING.
 *
 * N-14 closed the LINELESS class: an auto-save with no line authors nothing and
 * spends nothing. It did NOT close the one-line-then-abandoned class — the draft
 * reached one line, `createNewDraft()` allocated out of `document_sequences`, the
 * operator walked away, and the number was gone forever. That is the wave-4
 * `PO-2026-0001 … PO-2026-0009` orphan shape
 * (PLAYWRIGHT-first-tenant-campaign-wave2-imports-2026-08-24 §N-14).
 *
 * THE RULED CONTRACT, pinned here:
 *   1. A DRAFT carries NO `document_number`. The UI shows a placeholder.
 *   2. The number is allocated EXACTLY ONCE, at the first transition out of
 *      `Draft` that produces a real document — never on the way to `Cancelled`.
 *   3. Allocation happens INSIDE the confirming transaction, so a rollback
 *      returns the number to the sequence.
 *   4. A legacy draft that already holds a number keeps it.
 *   5. No fiscal seal ever hashes a NULL number.
 */
final class DeferredDocumentNumberingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Deferred Numbering Tenant',
            'slug' => 'deferred-numbering',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deferred Co',
            'legal_name' => 'Deferred Co LLC',
            'tax_id' => 'TAX-DEFERRED',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
            'fiscal_chain_seed' => hash('sha256', 'deferred-seed'),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Deferred Customer',
            'type' => 'customer',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Deferred Product',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Deferred Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => false,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // 1. A draft is born WITHOUT a number
    // ──────────────────────────────────────────────────────────────────

    public function test_an_auto_saved_draft_with_one_line_carries_no_number_and_burns_none(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
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

        $draft = Document::query()->findOrFail($draftId);

        $this->assertNull(
            $draft->document_number,
            'A draft must be born unnumbered — the number belongs to the confirm.'
        );
        $this->assertNull(
            DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number'),
            'and the quote sequence must not have been touched at all.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. Abandoning it burns nothing — the next document gets 0001
    // ──────────────────────────────────────────────────────────────────

    public function test_an_abandoned_draft_leaves_the_first_number_for_the_next_document(): void
    {
        $author = $this->authorizedUser();

        // Nine abandoned drafts — the wave-4 shape, one line each.
        for ($i = 0; $i < 9; $i++) {
            $this->actingAsUser($author)
                ->postJson('/api/v1/documents/auto-save', [
                    'type' => DocumentType::Quote->value,
                    'partner_id' => $this->customer->id,
                    'lines' => [
                        ['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10'],
                    ],
                ])
                ->assertStatus(200);
        }

        $realQuote = $this->draftQuoteWithOneLine();

        $this->actingAsUser($author)
            ->postJson("/api/v1/quotes/{$realQuote->id}/confirm")
            ->assertStatus(200);

        $this->assertSame(
            sprintf('QT-%d-0001', (int) date('Y')),
            (string) $realQuote->refresh()->document_number,
            'Nine abandoned drafts must not have pushed the real quote to 0010.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. Confirm allocates — and two confirms are sequential
    // ──────────────────────────────────────────────────────────────────

    public function test_confirming_allocates_the_number_and_successive_confirms_are_sequential(): void
    {
        $author = $this->authorizedUser();
        $year = (int) date('Y');

        $first = $this->draftQuoteWithOneLine();
        $second = $this->draftQuoteWithOneLine();

        $this->actingAsUser($author)->postJson("/api/v1/quotes/{$first->id}/confirm")->assertStatus(200);
        $this->actingAsUser($author)->postJson("/api/v1/quotes/{$second->id}/confirm")->assertStatus(200);

        $this->assertSame(sprintf('QT-%d-0001', $year), (string) $first->refresh()->document_number);
        $this->assertSame(sprintf('QT-%d-0002', $year), (string) $second->refresh()->document_number);
        $this->assertSame(DocumentStatus::Confirmed, $first->status);
        $this->assertSame(DocumentStatus::Confirmed, $second->status);
    }

    public function test_a_second_confirm_of_the_same_document_does_not_reallocate(): void
    {
        $author = $this->authorizedUser();
        $quote = $this->draftQuoteWithOneLine();

        $this->actingAsUser($author)->postJson("/api/v1/quotes/{$quote->id}/confirm")->assertStatus(200);
        $allocated = (string) $quote->refresh()->document_number;

        $this->actingAsUser($author)->postJson("/api/v1/quotes/{$quote->id}/confirm")->assertStatus(200);

        $this->assertSame($allocated, (string) $quote->refresh()->document_number);
        $this->assertSame(
            1,
            (int) DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number'),
            'The idempotent re-confirm must not spend a second number.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. A rollback mid-confirm returns the number
    // ──────────────────────────────────────────────────────────────────

    public function test_a_rolled_back_confirm_does_not_burn_the_number(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);

        $doomed = $this->draftQuoteWithOneLine();

        DB::beginTransaction();

        try {
            $statusService->transition($doomed, DocumentStatus::Confirmed);
            $this->assertNotNull(
                $doomed->document_number,
                'Sanity: the number is allocated inside the transaction.'
            );
        } finally {
            // `finally`, not a bare call: a failing assertion above must not leave
            // the transaction open and take every later test in this file with it.
            DB::rollBack();
        }

        $this->assertNull(
            $doomed->refresh()->document_number,
            'The rolled-back confirm must leave the draft unnumbered.'
        );

        $survivor = $this->draftQuoteWithOneLine();
        $statusService->transition($survivor, DocumentStatus::Confirmed);

        $this->assertSame(
            sprintf('QT-%d-0001', (int) date('Y')),
            (string) $survivor->refresh()->document_number,
            'The number the rolled-back confirm reserved must be handed to the next one.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. A legacy numbered draft keeps its number
    // ──────────────────────────────────────────────────────────────────

    public function test_a_legacy_numbered_draft_keeps_its_number_on_confirm(): void
    {
        $legacy = $this->draftQuoteWithOneLine();
        $legacy->update(['document_number' => 'QT-2026-0007']);

        DocumentSequence::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Quote->value,
            'year' => (int) date('Y'),
            'last_number' => 7,
        ]);

        $this->actingAsUser($this->authorizedUser())
            ->postJson("/api/v1/quotes/{$legacy->id}/confirm")
            ->assertStatus(200);

        $this->assertSame(
            'QT-2026-0007',
            (string) $legacy->refresh()->document_number,
            'A draft that already holds a number must never be renumbered.'
        );
        $this->assertSame(
            7,
            (int) DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number'),
            'and confirming it must not advance the sequence either.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 6. Cancelling an abandoned draft allocates nothing
    // ──────────────────────────────────────────────────────────────────

    public function test_cancelling_an_abandoned_draft_allocates_nothing(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);

        $abandoned = $this->draftQuoteWithOneLine();

        $statusService->transition($abandoned, DocumentStatus::Cancelled);

        $this->assertNull(
            $abandoned->refresh()->document_number,
            'A draft that dies on the way to Cancelled never became a document — it must spend nothing.'
        );
        $this->assertNull(
            DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number')
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 7. FISCAL INVARIANT — no seal ever hashes a NULL number
    // ──────────────────────────────────────────────────────────────────

    public function test_a_delivery_note_is_numbered_before_its_fiscal_hash_is_sealed(): void
    {
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $deliveryNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::DeliveryNote,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => null,
            'document_date' => now()->format('Y-m-d'),
            'location_id' => $this->location->id,
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        // A non-physical line: the seal is the subject here, not stock movement.
        $deliveryNote->lines()->create([
            'line_number' => 1,
            'description' => 'Consulting hours',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => 0,
            'line_total' => '100.000',
        ]);

        $confirmed = app(DeliveryNoteService::class)->confirm($deliveryNote->refresh());

        $this->assertNotNull($confirmed->fiscal_hash, 'Sanity: the delivery note was sealed.');
        $this->assertSame(
            sprintf('DN-%d-0001', (int) date('Y')),
            (string) $confirmed->document_number,
            'The number must exist BEFORE the hash input is serialized — a NULL number in a fiscal hash is unrecoverable.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function draftQuoteWithOneLine(): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Quote),
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => null,
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        $document->lines()->create([
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Deferred Product',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => 0,
            'line_total' => '100.000',
        ]);

        return $document->refresh();
    }

    private function authorizedUser(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deferred User',
            'email' => 'deferred-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $user->givePermissionTo([
            'documents.view',
            'documents.update',
            'quotes.create',
            'quotes.update',
            'orders.create',
            'invoices.create',
            'credit-notes.create',
            'purchase-orders.create',
            'deliveries.create',
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
        ]);

        return $user;
    }

    private function actingAsUser(User $user): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id);
    }
}
