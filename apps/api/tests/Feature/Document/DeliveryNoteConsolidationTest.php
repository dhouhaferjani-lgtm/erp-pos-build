<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Tests for the Delivery Note Consolidation feature (Tunisia model).
 *
 * This feature allows creating a single invoice from multiple delivery notes,
 * which is required for Tunisian compliance where multiple deliveries to the
 * same customer can be invoiced together at month-end.
 */
class DeliveryNoteConsolidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company TN',
            'legal_name' => 'Test Company SARL',
            'tax_id' => 'TN123456',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user-'.Str::random(8).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['deliveries.view', 'deliveries.create', 'deliveries.confirm', 'invoices.create', 'invoices.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer SARL',
            'type' => PartnerType::Customer,
            'email' => 'customer@example.com',
        ]);
    }

    private function createConfirmedDeliveryNote(string $number, array $lines): Document
    {
        $dn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        foreach ($lines as $index => $line) {
            DocumentLine::create([
                'document_id' => $dn->id,
                'line_number' => $index + 1,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'] ?? '19.00',
                'line_total' => bcmul($line['quantity'], $line['unit_price'], 2),
            ]);
        }

        return $dn;
    }

    public function test_can_consolidate_single_delivery_note_to_invoice(): void
    {
        $dn = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Oil Change', 'quantity' => '1.00', 'unit_price' => '50.00'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'document_number', 'type', 'status', 'lines'],
            'message',
            'meta' => ['consolidated_delivery_notes', 'source_delivery_note_ids'],
        ]);
        $this->assertEquals('invoice', $response->json('data.type'));
        $this->assertEquals('draft', $response->json('data.status'));
        $this->assertCount(1, $response->json('data.lines'));
    }

    public function test_can_consolidate_multiple_delivery_notes_to_single_invoice(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Oil Change', 'quantity' => '1.00', 'unit_price' => '50.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote('DN-2025-0002', [
            ['description' => 'Brake Pads', 'quantity' => '2.00', 'unit_price' => '80.00'],
            ['description' => 'Labor', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        $dn3 = $this->createConfirmedDeliveryNote('DN-2025-0003', [
            ['description' => 'Filter Set', 'quantity' => '1.00', 'unit_price' => '45.00'],
        ]);

        $requestedIds = [$dn3->id, $dn1->id, $dn2->id];
        $lockedQueries = [];
        DB::listen(static function ($query) use (&$lockedQueries): void {
            if (str_contains($query->sql, 'from "documents"') && str_contains($query->sql, 'order by "id" asc')) {
                $lockedQueries[] = $query->sql;
            }
        });

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => $requestedIds,
        ]);

        $response->assertStatus(201);
        $invoiceId = (string) $response->json('data.id');
        $this->assertEquals(3, $response->json('meta.consolidated_delivery_notes'));
        $this->assertCount(4, $response->json('data.lines')); // 1 + 2 + 1 = 4 lines
        // Company-scoped (M5-terminal treasury r3 minor): absolute global counts in this
        // class bleed under one-process PG runs alongside the fork-based concurrency file.
        $this->assertSame(1, Document::query()->where('type', DocumentType::Invoice)->where('company_id', $this->company->id)->count());
        $this->assertSame(
            3,
            DB::table('delivery_note_billing_marks')
                ->whereIn('delivery_note_id', $requestedIds)
                ->where('invoice_id', $invoiceId)
                ->where('invoiced_via', DeliveryNoteBillingLane::Consolidation->value)
                ->count(),
        );
        $this->assertNotEmpty($lockedQueries, 'The request must lock the tenant/company/type-scoped DN set in id order.');
        $scopedLockQueries = array_values(array_filter(
            $lockedQueries,
            static fn (string $sql): bool => str_contains($sql, '"tenant_id" = ?')
                && str_contains($sql, '"company_id" = ?')
                && str_contains($sql, '"type" = ?'),
        ));
        $this->assertNotEmpty(
            $scopedLockQueries,
            'The sorted DN acquisition must carry tenant, company, and delivery-note type predicates.',
        );
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertStringContainsString('for update', strtolower($scopedLockQueries[0]));
        }

        // M5-terminal r2, treasury `R2-3`. These two counts were GLOBAL — no company,
        // tenant or document predicate — which made this test order-dependent and red
        // whenever DeliveryNoteConsolidationConcurrencyTest ran first in the same
        // process: that file forks child processes on cloned connections whose writes
        // COMMIT, so they survive RefreshDatabase's parent-connection transaction and
        // are still in the database when this test counts. Measured before this fix:
        // the 14-file wave set run in ONE process was 1 failed / 137 passed
        // ("actual size 9 matches expected size 3"), while the executor's 13+1 file
        // split was green — the split hid it.
        //
        // Scoped to the invoice this test actually created. NOTE: `stored_events`
        // .`aggregate_uuid` is NULL for these rows — `DocumentConverted` does pass
        // `$targetDocumentId` to `DomainEvent::__construct`, but these events reach the
        // store through the event bus rather than an aggregate root, so the column is
        // never populated (verified by dumping the table). The serialized payload is
        // the reliable axis: `event_properties->targetDocumentId` is the new invoice's
        // id on all three rows.
        //
        // `audit_events` is scoped by this test's own company — `AuditService::record()`
        // takes `companyId` as an explicit argument (`:44`) and writes it to the column
        // (`AuditEvent.php:127`), whereas `tenant_id` is derived from the auth context.
        // The forked children run under a company of their own, which is the axis the
        // bleed crosses. It did not bleed in the measured run, but it is the identical
        // unscoped-count shape two lines away and there is no reason to leave the class
        // half-closed.
        $storedEvents = DB::table('stored_events')
            ->where('event_class', DocumentConverted::class)
            ->where('event_properties->targetDocumentId', $invoiceId)
            ->get();
        $this->assertCount(3, $storedEvents);

        $auditEvents = DB::table('audit_events')
            ->where('event_type', 'document.converted')
            ->where('company_id', $this->company->id)
            ->get();
        $this->assertCount(3, $auditEvents);
        $auditSources = [];
        foreach ($auditEvents as $auditEvent) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $auditEvent->payload, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($invoiceId, $payload['target_document_id']);
            $this->assertTrue($payload['metadata']['consolidation']);
            $this->assertSame(3, $payload['metadata']['total_dns_consolidated']);
            $auditSources[] = $payload['source_document_id'];
        }
        $this->assertEqualsCanonicalizing($requestedIds, $auditSources);
    }

    public function test_failed_endpoint_conversion_rolls_back_invoice_number_claims_and_events(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote('DN-ROLLBACK-0001', [
            ['description' => 'Part A', 'quantity' => '1.00', 'unit_price' => '50.00'],
        ]);
        $dn2 = $this->createConfirmedDeliveryNote('DN-ROLLBACK-0002', [
            ['description' => 'Part B', 'quantity' => '1.00', 'unit_price' => '75.00'],
        ]);

        $before = [
            'invoices' => Document::query()->where('type', DocumentType::Invoice)->count(),
            'invoice_lines' => DocumentLine::query()
                ->whereIn('document_id', Document::query()->where('type', DocumentType::Invoice)->select('id'))
                ->count(),
            'numbers' => (int) DB::table('document_sequences')
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Invoice->value)
                ->sum('last_number'),
            'claims' => DB::table('delivery_note_billing_marks')->count(),
            'stored_events' => DB::table('stored_events')->where('event_class', DocumentConverted::class)->count(),
            'audit_events' => DB::table('audit_events')->where('event_type', 'document.converted')->count(),
        ];

        Event::listen(DocumentConverted::class, static function (): never {
            throw new \RuntimeException('Force the production endpoint to roll back after claim finalisation.');
        });

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn2->id, $dn1->id],
        ]);

        $response->assertStatus(500)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
            ->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertSame($before['invoices'], Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertSame(
            $before['invoice_lines'],
            DocumentLine::query()
                ->whereIn('document_id', Document::query()->where('type', DocumentType::Invoice)->select('id'))
                ->count(),
        );
        $this->assertSame(
            $before['numbers'],
            (int) DB::table('document_sequences')
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Invoice->value)
                ->sum('last_number'),
        );
        $this->assertSame($before['claims'], DB::table('delivery_note_billing_marks')->count());
        $this->assertSame(
            $before['stored_events'],
            DB::table('stored_events')->where('event_class', DocumentConverted::class)->count(),
        );
        $this->assertSame(
            $before['audit_events'],
            DB::table('audit_events')->where('event_type', 'document.converted')->count(),
        );
        $this->assertNull($dn1->refresh()->payload);
        $this->assertNull($dn2->refresh()->payload);
    }

    public function test_invoice_totals_are_calculated_from_all_delivery_notes(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Part A', 'quantity' => '2.00', 'unit_price' => '100.00', 'tax_rate' => '19.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote('DN-2025-0002', [
            ['description' => 'Part B', 'quantity' => '1.00', 'unit_price' => '300.00', 'tax_rate' => '19.00'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn1->id, $dn2->id],
        ]);

        $response->assertStatus(201);
        // Part A: 2 * 100 = 200
        // Part B: 1 * 300 = 300
        // Subtotal: 500
        $this->assertEquals('500.000', $response->json('data.subtotal'));
    }

    public function test_delivery_notes_are_marked_as_invoiced_after_consolidation(): void
    {
        $dn = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ]);

        $response->assertStatus(201);

        $dn->refresh();
        $this->assertNotNull($dn->payload['invoiced_at']);
        $this->assertEquals($response->json('data.id'), $dn->payload['invoice_id']);
        $this->assertSame(DeliveryNoteBillingLane::Consolidation->value, $dn->payload['invoiced_via']);
        $this->assertDatabaseHas('delivery_note_billing_marks', [
            'delivery_note_id' => $dn->id,
            'invoice_id' => $response->json('data.id'),
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
            'company_id' => $this->company->id,
        ]);
        $this->assertSame(1, DB::table('delivery_note_billing_marks')->count());
    }

    public function test_cannot_consolidate_already_invoiced_delivery_note(): void
    {
        $dn = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        // First consolidation
        $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ])->assertStatus(201);

        // Attempt second consolidation with same DN
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DELIVERY_NOTE_ALREADY_INVOICED');
    }

    public function test_claim_loss_is_attributed_from_the_winner_after_rollback(): void
    {
        $deliveryNote = $this->createConfirmedDeliveryNote('DN-CLAIM-LOSS', [
            ['description' => 'Claim loss', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);
        $winner = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-CLAIM-WINNER',
            'document_date' => '2026-08-18',
            'currency' => 'TND',
        ]);
        DB::table('delivery_note_billing_marks')->insert([
            'delivery_note_id' => $deliveryNote->id,
            'invoice_id' => $winner->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
            'invoiced_at' => now(),
            'company_id' => $this->company->id,
        ]);
        $numberBefore = (int) DB::table('document_sequences')
            ->where('company_id', $this->company->id)
            ->where('type', DocumentType::Invoice->value)
            ->sum('last_number');

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$deliveryNote->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'DELIVERY_NOTE_ALREADY_INVOICED')
            ->assertJsonPath('error.details.documents.0.id', $deliveryNote->id)
            ->assertJsonPath('error.details.documents.0.document_number', $deliveryNote->document_number)
            ->assertJsonPath('error.details.documents.0.reason', 'claim_lost')
            ->assertJsonPath('error.details.documents.0.invoice_id', $winner->id)
            ->assertJsonPath('error.details.documents.0.invoice_number', $winner->document_number)
            ->assertJsonPath('error.details.documents.0.invoice_date', '2026-08-18')
            ->assertJsonPath(
                'error.details.documents.0.invoiced_via',
                DeliveryNoteBillingLane::Consolidation->value,
            );
        $this->assertNull($deliveryNote->refresh()->payload, 'The losing reserve stamp must roll back.');
        $this->assertSame(1, DB::table('delivery_note_billing_marks')->count());
        $this->assertSame(
            $numberBefore,
            (int) DB::table('document_sequences')
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Invoice->value)
                ->sum('last_number'),
        );
    }

    public function test_unrelated_unique_violation_uses_the_internal_error_envelope(): void
    {
        config()->set('app.debug', false);

        $existing = $this->createConfirmedDeliveryNote('DN-UNRELATED-EXISTING', [
            ['description' => 'Existing marker', 'quantity' => '1.00', 'unit_price' => '10.00'],
        ]);
        DB::table('delivery_note_billing_marks')->insert([
            'delivery_note_id' => $existing->id,
            'invoice_id' => null,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
            'invoiced_at' => now(),
            'company_id' => $this->company->id,
        ]);
        Schema::table('delivery_note_billing_marks', static function ($table): void {
            $table->unique('company_id', 'delivery_note_billing_marks_company_id_review_unique');
        });
        $target = $this->createConfirmedDeliveryNote('DN-UNRELATED-TARGET', [
            ['description' => 'Target marker', 'quantity' => '1.00', 'unit_price' => '20.00'],
        ]);
        $invoiceCountBefore = Document::query()->where('type', DocumentType::Invoice)->count();

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$target->id],
        ]);

        $response->assertStatus(500)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
            ->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $this->assertNotSame('DELIVERY_NOTE_ALREADY_INVOICED', $response->json('error.code'));
        $this->assertSame($invoiceCountBefore, Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertDatabaseMissing('delivery_note_billing_marks', [
            'delivery_note_id' => $target->id,
        ]);
        $this->assertArrayNotHasKey('invoiced_at', $target->fresh()->payload ?? []);
    }

    public function test_twelve_note_batch_reports_every_offending_row_with_taker_attribution(): void
    {
        $consumed1 = $this->createConfirmedDeliveryNote('DN-CONSUMED-0001', [
            ['description' => 'Consumed A', 'quantity' => '1.00', 'unit_price' => '10.00'],
        ]);
        $consumed2 = $this->createConfirmedDeliveryNote('DN-CONSUMED-0002', [
            ['description' => 'Consumed B', 'quantity' => '1.00', 'unit_price' => '20.00'],
        ]);
        $takingResponse = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$consumed1->id, $consumed2->id],
        ])->assertStatus(201);
        $takingInvoiceId = (string) $takingResponse->json('data.id');
        $takingInvoiceNumber = (string) $takingResponse->json('data.document_number');
        $takingInvoiceDate = Document::query()->findOrFail($takingInvoiceId)->document_date->toDateString();

        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other batch customer',
            'type' => PartnerType::Customer,
        ]);

        $wrongPartner = $this->createConfirmedDeliveryNote('DN-WRONG-PARTNER', [
            ['description' => 'Wrong partner', 'quantity' => '1.00', 'unit_price' => '30.00'],
        ]);
        $wrongPartner->update(['partner_id' => $otherPartner->id]);
        $wrongCurrency = $this->createConfirmedDeliveryNote('DN-WRONG-CURRENCY', [
            ['description' => 'Wrong currency', 'quantity' => '1.00', 'unit_price' => '40.00'],
        ]);
        $wrongCurrency->update(['currency' => 'EUR']);
        $draft = $this->createConfirmedDeliveryNote('DN-DRAFT-BATCH', [
            ['description' => 'Draft', 'quantity' => '1.00', 'unit_price' => '50.00'],
        ]);
        $draft->update(['status' => DocumentStatus::Draft]);
        $cancelled = $this->createConfirmedDeliveryNote('DN-CANCELLED-BATCH', [
            ['description' => 'Cancelled', 'quantity' => '1.00', 'unit_price' => '60.00'],
        ]);
        $cancelled->update(['status' => DocumentStatus::Cancelled]);

        $valid = [];
        foreach (range(1, 6) as $index) {
            $valid[] = $this->createConfirmedDeliveryNote('DN-VALID-BATCH-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), [
                ['description' => 'Valid '.$index, 'quantity' => '1.00', 'unit_price' => '70.00'],
            ]);
        }

        $requestedIds = [
            $valid[0]->id,
            $consumed1->id,
            $wrongPartner->id,
            $valid[1]->id,
            $wrongCurrency->id,
            $valid[2]->id,
            $draft->id,
            $valid[3]->id,
            $cancelled->id,
            $valid[4]->id,
            $consumed2->id,
            $valid[5]->id,
        ];
        $invoiceCountBefore = Document::query()->where('type', DocumentType::Invoice)->count();

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => $requestedIds,
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'DELIVERY_NOTE_ALREADY_INVOICED');
        $details = collect($response->json('error.details.documents'))->keyBy('id');
        $this->assertCount(6, $details);
        $expectedReasons = [
            $consumed1->id => 'already_invoiced',
            $consumed2->id => 'already_invoiced',
            $wrongPartner->id => 'wrong_partner',
            $wrongCurrency->id => 'wrong_currency',
            $draft->id => 'not_confirmed',
            $cancelled->id => 'cancelled',
        ];
        foreach ($expectedReasons as $id => $reason) {
            $this->assertSame($reason, $details->get($id)['reason']);
            $this->assertArrayHasKey('document_number', $details->get($id));
            $this->assertArrayHasKey('invoice_id', $details->get($id));
            $this->assertArrayHasKey('invoice_number', $details->get($id));
            $this->assertArrayHasKey('invoice_date', $details->get($id));
            $this->assertArrayHasKey('invoiced_via', $details->get($id));
        }
        foreach ([$consumed1->id, $consumed2->id] as $id) {
            $this->assertSame($takingInvoiceId, $details->get($id)['invoice_id']);
            $this->assertSame($takingInvoiceNumber, $details->get($id)['invoice_number']);
            $this->assertSame($takingInvoiceDate, $details->get($id)['invoice_date']);
            $this->assertSame(DeliveryNoteBillingLane::Consolidation->value, $details->get($id)['invoiced_via']);
        }
        foreach ([$wrongPartner->id, $wrongCurrency->id, $draft->id, $cancelled->id] as $id) {
            $this->assertNull($details->get($id)['invoice_id']);
            $this->assertNull($details->get($id)['invoice_number']);
            $this->assertNull($details->get($id)['invoice_date']);
            $this->assertNull($details->get($id)['invoiced_via']);
        }
        $this->assertSame($invoiceCountBefore, Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertSame(
            0,
            DB::table('delivery_note_billing_marks')
                ->whereIn('delivery_note_id', array_map(static fn (Document $dn): string => $dn->id, $valid))
                ->count(),
        );
    }

    public function test_direct_converter_cannot_load_or_stamp_wrong_company_tenant_or_type_ids(): void
    {
        $source = $this->createConfirmedDeliveryNote('DN-C5-SOURCE', [
            ['description' => 'Scoped source', 'quantity' => '1.00', 'unit_price' => '10.00'],
        ]);
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other C5 company',
            'legal_name' => 'Other C5 company SARL',
            'tax_id' => 'C5-OTHER-COMPANY',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $otherCompanyPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other C5 company customer',
            'type' => PartnerType::Customer,
        ]);
        $wrongCompany = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherCompanyPartner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-C5-WRONG-COMPANY',
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        $otherTenant = Tenant::create([
            'name' => 'Other C5 tenant',
            'slug' => 'other-c5-tenant-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $otherTenantCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other tenant C5 company',
            'legal_name' => 'Other tenant C5 company SARL',
            'tax_id' => 'C5-OTHER-TENANT',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $otherTenantPartner = Partner::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherTenantCompany->id,
            'name' => 'Other tenant C5 customer',
            'type' => PartnerType::Customer,
        ]);
        $wrongTenant = Document::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherTenantCompany->id,
            'partner_id' => $otherTenantPartner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-C5-WRONG-TENANT',
            'document_date' => now(),
            'currency' => 'TND',
        ]);
        $wrongType = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-C5-WRONG-TYPE',
            'document_date' => now(),
            'currency' => 'TND',
        ]);
        $ids = [$source->id, $wrongCompany->id, $wrongTenant->id, $wrongType->id];

        try {
            app(DocumentConverterRegistry::class)->convert($source, DocumentType::Invoice, [
                'delivery_note_ids' => array_reverse($ids),
            ]);
            $this->fail('A direct caller must not bypass tenant/company/type scoping.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('active company', $exception->getMessage());
        }

        // Company-scoped (M5-terminal treasury r3 minor): absolute global counts in this
        // class bleed under one-process PG runs alongside the fork-based concurrency file.
        $this->assertSame(1, Document::query()->where('type', DocumentType::Invoice)->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('delivery_note_billing_marks')->whereIn('delivery_note_id', $ids)->count());
        $this->assertNull($source->refresh()->payload);
        $this->assertNull($wrongCompany->refresh()->payload);
        $this->assertNull($wrongTenant->refresh()->payload);
    }

    public function test_cannot_consolidate_delivery_notes_from_different_partners(): void
    {
        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Customer',
            'type' => PartnerType::Customer,
            'email' => 'other@example.com',
        ]);

        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service A', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        $dn2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $otherPartner->id, // Different partner
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-2025-0002',
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn1->id, $dn2->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'CONSOLIDATION_VALIDATION_FAILED');
        $this->assertStringContainsString('same partner', $response->json('error.message'));
    }

    public function test_cannot_consolidate_delivery_notes_with_different_currencies(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service A', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        // Create DN with EUR instead of TND
        $dn2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR', // Different currency
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn1->id, $dn2->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'CONSOLIDATION_VALIDATION_FAILED');
        $this->assertStringContainsString('same currency', $response->json('error.message'));
    }

    public function test_cannot_consolidate_draft_delivery_note(): void
    {
        $dn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft, // Draft, not confirmed
            'document_number' => 'DN-2025-0001',
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'CONSOLIDATION_VALIDATION_FAILED');
        $this->assertStringContainsString('confirmed', $response->json('error.message'));
    }

    public function test_cannot_consolidate_delivery_note_without_lines(): void
    {
        $deliveryNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-NO-LINES',
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$deliveryNote->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'CONSOLIDATION_VALIDATION_FAILED')
            ->assertJsonPath('error.details.documents.0.id', $deliveryNote->id)
            ->assertJsonPath('error.details.documents.0.reason', 'no_lines');
        $this->assertSame(0, Document::query()->where('type', DocumentType::Invoice)->where('company_id', $this->company->id)->count()); // company-scoped (R4-5)
        $this->assertDatabaseMissing('delivery_note_billing_marks', [
            'delivery_note_id' => $deliveryNote->id,
        ]);
    }

    public function test_validation_requires_at_least_one_delivery_note(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [],
        ]);

        $this->assertApiValidationErrors($response, ['delivery_note_ids']);
    }

    public function test_validation_requires_valid_document_ids(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => ['not-a-uuid'],
        ]);

        $this->assertApiValidationErrors($response, ['delivery_note_ids.0']);
    }

    public function test_invoice_reference_contains_all_dn_numbers(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service A', 'quantity' => '1.00', 'unit_price' => '50.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote('DN-2025-0002', [
            ['description' => 'Service B', 'quantity' => '1.00', 'unit_price' => '75.00'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn1->id, $dn2->id],
        ]);

        $response->assertStatus(201);
        $reference = $response->json('data.reference');
        $this->assertStringContainsString('DN-2025-0001', $reference);
        $this->assertStringContainsString('DN-2025-0002', $reference);
    }
}
