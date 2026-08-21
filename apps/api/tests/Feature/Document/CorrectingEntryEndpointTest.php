<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\CorrectingEntryRefusalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\CorrectingEntryPayload;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * R2-F4 — the HTTP surface of the correcting-entry document, and its gate.
 *
 * Owner ruling c4 makes the correction a document with its own Draft ->
 * Confirmed -> Posted lifecycle, created FROM the original it repairs. Every
 * route is admin-gated on the dedicated `documents.correct` permission: posting
 * an arbitrary pair of GL legs is strictly more powerful than cancelling a
 * document, so it must not ride on `invoices.cancel` or `documents.update`.
 */
final class CorrectingEntryEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    private User $manager;

    private Partner $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Correcting Endpoint Tenant',
            'slug' => 'ce-http-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Correcting Endpoint Company',
            'legal_name' => 'Correcting Endpoint Company SARL',
            'tax_id' => 'TAX-CE-HTTP',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = $this->userWithRole('admin', 'ce-admin@example.com');
        $this->manager = $this->userWithRole('manager', 'ce-manager@example.com');

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
            'sku' => 'PROD-CE-'.uniqid(),
            'name' => 'Doliprane 1000mg',
            'type' => ProductType::Part,
            'cost_price' => '5.000',
            'sale_price' => '10.000',
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------- lifecycle ---

    public function test_it_creates_a_draft_correcting_entry_linked_to_the_original(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/documents/{$invoice->id}/correcting-entries", [
                'reason' => 'Missing AR leg on the January invoice',
                'legs' => [
                    ['account_id' => $this->accountId('411'), 'debit' => '19.000', 'credit' => '0', 'description' => 'AR'],
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', DocumentType::CorrectingEntry->value);
        $response->assertJsonPath('data.status', DocumentStatus::Draft->value);
        $response->assertJsonPath('data.source_document_id', $invoice->id);

        $correctionId = $response->json('data.id');
        self::assertIsString($correctionId);

        $correction = Document::findOrFail($correctionId);
        self::assertStringStartsWith('CE-', (string) $correction->document_number);
        self::assertSame($invoice->id, $correction->source_document_id);

        $payload = CorrectingEntryPayload::fromDocumentPayload($correction->payload);
        self::assertSame('Missing AR leg on the January invoice', $payload->reason);
        self::assertCount(1, $payload->legs);
        self::assertSame('19.000', $payload->legs[0]->debit);
    }

    public function test_the_full_draft_confirm_post_lifecycle_writes_the_gl(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();
        $correctionId = $this->createCorrection($invoice);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/correcting-entries/{$correctionId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', DocumentStatus::Confirmed->value);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/correcting-entries/{$correctionId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', DocumentStatus::Posted->value);

        self::assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE)
                ->where('source_id', $correctionId)
                ->count(),
        );
    }

    public function test_a_draft_cannot_be_posted_before_it_is_confirmed(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();
        $correctionId = $this->createCorrection($invoice);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/correcting-entries/{$correctionId}/post")
            ->assertStatus(422);

        self::assertSame(0, JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE)
            ->count());
    }

    public function test_a_draft_can_be_deleted_but_a_posted_correction_cannot(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();
        $draftId = $this->createCorrection($invoice);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/correcting-entries/{$draftId}")
            ->assertOk();

        self::assertNull(Document::query()->find($draftId));

        $postedId = $this->createCorrection($invoice);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/correcting-entries/{$postedId}/confirm");
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/correcting-entries/{$postedId}/post");

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/correcting-entries/{$postedId}")
            ->assertStatus(422);

        self::assertNotNull(Document::query()->find($postedId));
    }

    public function test_it_lists_the_corrections_of_a_document(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();
        $this->createCorrection($invoice);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/documents/{$invoice->id}/correcting-entries");

        $response->assertOk();
        self::assertCount(1, $response->json('data'));
    }

    public function test_it_shows_a_single_correcting_entry_with_its_legs(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();
        $correctionId = $this->createCorrection($invoice);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/correcting-entries/{$correctionId}");

        $response->assertOk();
        $response->assertJsonPath('data.source_document_id', $invoice->id);
        $response->assertJsonPath('data.correcting_entry.legs.0.debit', '19.000');
        $response->assertJsonPath('data.correcting_entry.reason', 'Missing AR leg');
    }

    // ---------------------------------------------------------- refusals ---

    public function test_posting_a_correction_that_leaves_the_target_unbalanced_returns_a_coded_422(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();
        $correctionId = $this->createCorrection($invoice, '9.000');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/correcting-entries/{$correctionId}/confirm")
            ->assertOk();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/correcting-entries/{$correctionId}/post");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', CorrectingEntryRefusalCode::LeavesTargetUnbalanced->value);

        self::assertSame(DocumentStatus::Confirmed, Document::findOrFail($correctionId)->status);
    }

    public function test_creating_a_correction_against_an_unsupported_target_type_is_refused(): void
    {
        $supplierInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::SupplierInvoice,
            'document_number' => 'SI-CE-'.uniqid(),
            'document_date' => Carbon::parse('2026-01-15'),
            'status' => DocumentStatus::Posted,
            'total' => '238.000',
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/documents/{$supplierInvoice->id}/correcting-entries", [
                'reason' => 'nope',
                'legs' => [
                    ['account_id' => $this->accountId('411'), 'debit' => '1.000', 'credit' => '0'],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', CorrectingEntryRefusalCode::UnsupportedTargetType->value);
    }

    public function test_a_leg_with_both_a_debit_and_a_credit_is_rejected_by_validation(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/documents/{$invoice->id}/correcting-entries", [
                'reason' => 'both sides',
                'legs' => [
                    ['account_id' => $this->accountId('411'), 'debit' => '5.000', 'credit' => '5.000'],
                ],
            ])
            ->assertStatus(422);
    }

    /**
     * Rule 19's FormRequest ceiling: money carries at most three decimals.
     */
    public function test_a_leg_amount_beyond_the_money_scale_is_rejected_by_validation(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/documents/{$invoice->id}/correcting-entries", [
                'reason' => 'too precise',
                'legs' => [
                    ['account_id' => $this->accountId('411'), 'debit' => '19.00001', 'credit' => '0'],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_a_target_in_another_company_is_not_found(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company SARL',
            'tax_id' => 'TAX-CE-OTHER',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $foreignInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-OTHER-'.uniqid(),
            'document_date' => Carbon::parse('2026-01-15'),
            'status' => DocumentStatus::Posted,
            'total' => '119.000',
            'currency' => 'TND',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/documents/{$foreignInvoice->id}/correcting-entries", [
                'reason' => 'cross company',
                'legs' => [
                    ['account_id' => $this->accountId('411'), 'debit' => '1.000', 'credit' => '0'],
                ],
            ])
            ->assertStatus(404);
    }

    // ------------------------------------------------------------- gate ---

    /**
     * The gate. `manager` is the most privileged non-admin role and holds
     * `documents.view`/`documents.update` plus the invoice permissions — none of
     * which may buy the ability to post arbitrary GL legs.
     */
    public function test_a_user_without_the_correct_permission_is_forbidden_everywhere(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();
        $correctionId = $this->createCorrection($invoice);

        self::assertFalse(
            $this->manager->hasPermissionTo('documents.correct', 'sanctum'),
            'Precondition: manager must NOT hold the correcting-entry permission',
        );

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/documents/{$invoice->id}/correcting-entries", [
                'reason' => 'nope',
                'legs' => [['account_id' => $this->accountId('411'), 'debit' => '1.000', 'credit' => '0']],
            ])
            ->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/documents/{$invoice->id}/correcting-entries")
            ->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/correcting-entries/{$correctionId}")
            ->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/correcting-entries/{$correctionId}/confirm")
            ->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/correcting-entries/{$correctionId}/post")
            ->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/v1/correcting-entries/{$correctionId}")
            ->assertForbidden();
    }

    public function test_the_permission_exists_and_is_granted_to_admin_only(): void
    {
        self::assertTrue($this->admin->hasPermissionTo('documents.correct', 'sanctum'));

        $grants = RolesAndPermissionsSeeder::rolePermissionGrants();
        foreach ($grants as $role => $permissions) {
            if ($role === 'admin') {
                continue;
            }

            self::assertNotContains(
                'documents.correct',
                $permissions,
                "Role {$role} must not be able to post correcting entries",
            );
        }
    }

    // ------------------------------------------ gate fix round (P3) ---

    /**
     * P3-9. The created row itself carries NON_FISCAL — not merely the enum
     * mapping, but what actually reached Postgres, since
     * `chk_fiscal_category_enum` is the constraint the zero-schema claim rests on.
     */
    public function test_a_created_correction_row_carries_the_non_fiscal_category(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();
        $correctionId = $this->createCorrection($invoice);

        self::assertSame(
            'NON_FISCAL',
            DB::table('documents')->where('id', $correctionId)->value('fiscal_category'),
        );
    }

    /**
     * P3-10. A POSTED correction is SEALED, and that is not cosmetic: it is what
     * arms `trg_document_immutability` and `trg_prevent_fiscal_deletion`, both of
     * which key on `fiscal_status IN ('SEALED','VOIDED')`. Left at DRAFT — as it
     * was — a posted correction's row was protected by NEITHER trigger while its
     * legs were sealed into the journal chain.
     *
     * Asserted against the TRIGGERS, not against the column, because the column
     * value is only interesting for what it switches on.
     */
    public function test_a_posted_correction_is_sealed_and_the_database_refuses_to_mutate_its_identity(): void
    {
        $correctionId = $this->postedCorrection();

        self::assertSame(
            FiscalStatus::Sealed->value,
            DB::table('documents')->where('id', $correctionId)->value('fiscal_status'),
        );

        $this->expectException(QueryException::class);
        DB::table('documents')->where('id', $correctionId)->update(['document_number' => 'TAMPERED']);
    }

    /** The deletion trigger is armed too — a posted correction cannot be removed. */
    public function test_a_posted_correction_cannot_be_deleted_at_the_database_level(): void
    {
        $correctionId = $this->postedCorrection();

        $this->expectException(QueryException::class);
        DB::table('documents')->where('id', $correctionId)->delete();
    }

    /**
     * THE RESIDUAL GAP, pinned so it is recorded rather than assumed closed.
     *
     * `trg_document_immutability`'s immutable-field list
     * (`2025_12_11_054716_add_document_immutability_trigger.php:33-45`) was written
     * for fiscal SALES documents: document_number, document_date, partner_id, the
     * money columns, currency, the hash columns, fiscal_category. It does NOT
     * include `payload` — which is exactly where a correcting entry keeps its
     * LEGS — nor `source_document_id`, the link ruling c4 makes mandatory.
     *
     * So sealing buys real protection (identity frozen, deletion refused) but not
     * total protection, and this test says so out loud rather than letting a
     * future reader infer immutability the row does not have.
     *
     * WHY THIS IS TOLERABLE, and it is not "because it is hard": the LEDGER is the
     * authority, not this row. The legs were copied into `journal_lines` under a
     * hash-chained `journal_entries` row at post time; editing `payload`
     * afterwards desynchronises the document from the ledger but cannot alter a
     * single posted amount, and the chain verifier reads the ledger. Widening the
     * trigger is a schema migration and would forfeit the lane's zero-schema
     * property, so it belongs to its own lane — recorded in the report.
     */
    public function test_the_sealed_row_still_permits_payload_edits_a_known_and_recorded_gap(): void
    {
        $correctionId = $this->postedCorrection();

        // No exception: `payload` is outside the trigger's field list.
        DB::table('documents')->where('id', $correctionId)->update([
            'payload' => json_encode(['correcting_entry' => ['reason' => 'edited after posting', 'legs' => []]]),
        ]);

        self::assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE)
                ->where('source_id', $correctionId)
                ->count(),
            'The LEDGER is untouched by a payload edit — which is why the payload half is survivable',
        );
    }

    /**
     * THE SECOND ARM, and the more serious one — measured, not asserted.
     *
     * `source_document_id` is also outside the trigger's field list, and unlike
     * `payload` its consequence is NOT confined to the document row. The link is
     * the sole input to `AccountingService::correctingEntryDocumentIdsFor()`,
     * which is what `documentLedgerFootprint()` reads — so re-pointing a SEALED
     * correction MIGRATES its posted legs from one document's ledger footprint
     * to another's. Both documents' balance verdicts change, and so does whether
     * `reverseDocumentGl()` will consent to withdraw either of them, without a
     * single journal row being touched.
     *
     * This test performs the re-point and MEASURES the migration rather than
     * describing it, so the residual is recorded at its true size. It is the
     * strongest argument for widening the trigger in the follow-on lane: the
     * payload half is cosmetic, this half is not.
     */
    public function test_a_sealed_correction_can_still_be_re_pointed_and_its_footprint_migrates(): void
    {
        $firstInvoice = $this->invoiceWithStrandedVatLeg();
        $correctionId = $this->postedCorrectionFor($firstInvoice);

        $secondInvoice = $this->invoiceWithStrandedVatLeg();

        $footprintOf = function (string $documentId): int {
            $correctionIds = DB::table('documents')
                ->where('type', DocumentType::CorrectingEntry->value)
                ->where('source_document_id', $documentId)
                ->pluck('id')
                ->all();

            return $correctionIds === [] ? 0 : JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CORRECTION_SOURCE_TYPE)
                ->whereIn('source_id', $correctionIds)
                ->count();
        };

        self::assertSame(1, $footprintOf($firstInvoice->id));
        self::assertSame(0, $footprintOf($secondInvoice->id));

        // The re-point. No exception: `source_document_id` is outside
        // `trg_document_immutability`'s immutable-field list, even though the row
        // is SEALED.
        DB::table('documents')->where('id', $correctionId)->update([
            'source_document_id' => $secondInvoice->id,
        ]);

        // MEASURED: the posted legs now belong to a different document's ledger.
        self::assertSame(
            0,
            $footprintOf($firstInvoice->id),
            'The correction left the first document\'s footprint without any journal row moving',
        );
        self::assertSame(
            1,
            $footprintOf($secondInvoice->id),
            'and arrived in the second document\'s footprint — which is what changes both balance verdicts',
        );
    }

    /**
     * A DRAFT correction is NOT sealed — the protection arrives with posting, and
     * `delete` must keep working before it. Without this the test above would
     * pass just as well on a document sealed at creation, which would break the
     * discard path.
     */
    public function test_a_draft_correction_is_not_sealed(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();
        $correctionId = $this->createCorrection($invoice);

        self::assertSame(
            FiscalStatus::Draft->value,
            DB::table('documents')->where('id', $correctionId)->value('fiscal_status'),
        );
    }

    /**
     * P1-2 at the HTTP boundary: a leg may name its own partner, and the request
     * accepts it. The refusal side is pinned at the service level, where the
     * chart of accounts lives.
     */
    public function test_a_leg_may_name_its_own_partner(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/documents/{$invoice->id}/correcting-entries", [
                'reason' => 'AR leg for a named partner',
                'legs' => [[
                    'account_id' => $this->accountId('411'),
                    'debit' => '19.000',
                    'credit' => '0',
                    'partner_id' => $this->customer->id,
                ]],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.correcting_entry.legs.0.partner_id', $this->customer->id);
    }

    public function test_a_leg_partner_that_is_not_a_uuid_is_rejected_by_validation(): void
    {
        $invoice = $this->invoiceWithStrandedVatLeg();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/documents/{$invoice->id}/correcting-entries", [
                'reason' => 'bad partner',
                'legs' => [[
                    'account_id' => $this->accountId('411'),
                    'debit' => '19.000',
                    'credit' => '0',
                    'partner_id' => 'not-a-uuid',
                ]],
            ])
            ->assertStatus(422);
    }

    // ----------------------------------------------------------- helpers ---

    private function userWithRole(string $role, string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $role,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $user->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role === 'admin' ? MembershipRole::Admin : MembershipRole::Manager,
        ]);

        return $user;
    }

    private function createCorrection(Document $invoice, string $debit = '19.000'): string
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/documents/{$invoice->id}/correcting-entries", [
                'reason' => 'Missing AR leg',
                'legs' => [
                    ['account_id' => $this->accountId('411'), 'debit' => $debit, 'credit' => '0'],
                ],
            ]);

        $response->assertCreated();

        $id = $response->json('data.id');
        self::assertIsString($id);

        return $id;
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
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-CE-'.uniqid(),
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

        /** @var Document */
        return $invoice->fresh(['lines']);
    }

    /** A correction taken all the way to POSTED through the HTTP surface. */
    private function postedCorrection(): string
    {
        return $this->postedCorrectionFor($this->invoiceWithStrandedVatLeg());
    }

    private function postedCorrectionFor(Document $invoice): string
    {
        $correctionId = $this->createCorrection($invoice);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/correcting-entries/{$correctionId}/confirm")->assertOk();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/correcting-entries/{$correctionId}/post")
            ->assertOk()
            ->assertJsonPath('data.fiscal_status', FiscalStatus::Sealed->value);

        return $correctionId;
    }
}
