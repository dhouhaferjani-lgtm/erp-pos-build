<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DeliveryStatus;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteAlreadyClaimedException;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteBatchValidationException;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteClaimNotFinalisedException;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteBillingClaimService;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimRequest;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimSet;
use App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToInvoiceConverter;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SalesOrderBillingClaimTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'SO billing tenant',
            'slug' => 'so-billing-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SO billing company',
            'legal_name' => 'SO billing company SARL',
            'tax_id' => 'SO-BILLING-'.Str::random(8),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'SO billing customer',
            'type' => PartnerType::Customer,
        ]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'SO billing warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SO billing user',
            'email' => 'so-billing-'.Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['deliveries.view', 'invoices.create', 'invoices.view', 'quotes.convert']);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_existing_delivery_notes_are_claimed_before_one_order_invoice_is_created(): void
    {
        $order = $this->createOrder();
        $deliveryNote = $this->createDeliveryNoteFrom($order);
        $storedEventsBefore = DB::table('stored_events')
            ->where('event_class', DocumentConverted::class)
            ->count();
        $auditEventsBefore = DB::table('audit_events')
            ->where('event_type', 'document.converted')
            ->count();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice")
            ->assertStatus(201);
        $invoice = Document::query()->findOrFail((string) $response->json('data.id'));
        $this->assertSame(
            $order->lines()->sole()->id,
            $invoice->lines()->sole()->source_line_id,
            'A full SO invoice line must retain its exact order-line provenance.',
        );

        $deliveryNote->refresh();
        $this->assertSame($invoice->id, $deliveryNote->payload['invoice_id'] ?? null);
        $this->assertSame(DeliveryNoteBillingLane::OrderConversion->value, $deliveryNote->payload['invoiced_via'] ?? null);
        $this->assertDatabaseHas('delivery_note_billing_marks', [
            'delivery_note_id' => $deliveryNote->id,
            'invoice_id' => $invoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::OrderConversion->value,
        ]);
        $this->assertSame(1, Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertSame(
            $storedEventsBefore + 1,
            DB::table('stored_events')->where('event_class', DocumentConverted::class)->count(),
        );
        $this->assertSame(
            $auditEventsBefore + 1,
            DB::table('audit_events')->where('event_type', 'document.converted')->count(),
        );
        $auditEvent = DB::table('audit_events')
            ->where('event_type', 'document.converted')
            ->where('aggregate_id', $invoice->id)
            ->sole();
        /** @var array<string, mixed> $auditPayload */
        $auditPayload = json_decode((string) $auditEvent->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($order->id, $auditPayload['source_document_id']);
        $this->assertSame($invoice->id, $auditPayload['target_document_id']);
        $this->assertSame(DocumentType::SalesOrder->value, $auditPayload['source_type']);
        $this->assertSame(DocumentType::Invoice->value, $auditPayload['target_type']);
        $this->assertSame($this->user->id, $auditPayload['user_id']);
        $this->assertFalse($auditPayload['is_partial']);
        $this->assertSame([], $auditPayload['metadata']);

        $headerLock = $this->queryPosition($queries, 'from "documents"', $order->id, DocumentType::SalesOrder->value);
        $deliveryLocks = $this->queryPosition($queries, 'order by "id" asc', $deliveryNote->id, DocumentType::DeliveryNote->value);
        $invoiceSequence = $this->queryPosition($queries, 'from "document_sequences"', DocumentType::Invoice->value);

        $this->assertLessThan($deliveryLocks, $headerLock, 'The order header must be read and locked before claimed DNs.');
        $this->assertLessThan($invoiceSequence, $deliveryLocks, 'All sorted DN locks must precede invoice numbering.');
    }

    public function test_auto_created_delivery_note_and_all_factory_side_effects_roll_back_on_claim_loss_then_reentry_succeeds(): void
    {
        $order = $this->createOrder('1.2500');
        $line = $order->lines()->firstOrFail();
        $payloadBefore = $order->payload;
        $deliverySequenceBefore = $this->sequenceNumber(DocumentType::DeliveryNote);
        $invoiceSequenceBefore = $this->sequenceNumber(DocumentType::Invoice);
        $statusBefore = $order->fresh()->getDeliveryStatus();
        $storedEventsBefore = DB::table('stored_events')
            ->where('event_class', DocumentConverted::class)
            ->count();
        $auditEventsBefore = DB::table('audit_events')
            ->where('event_type', 'document.converted')
            ->count();
        $this->assertSame(DeliveryStatus::PartiallyDelivered, $statusBefore);

        $connection = DB::connection();
        $losingClaim = new class($connection) extends DeliveryNoteBillingClaimService
        {
            public function __construct(ConnectionInterface $db)
            {
                parent::__construct($db);
            }

            public function claim(DeliveryNoteClaimRequest $request, Closure $createInvoice): DeliveryNoteClaimSet
            {
                throw new DeliveryNoteAlreadyClaimedException($request->deliveryNoteIds[0]);
            }
        };
        $this->app->instance(DeliveryNoteBillingClaimService::class, $losingClaim);
        $this->forgetConverters();

        try {
            $this->registry()->convert($order, DocumentType::Invoice);
            $this->fail('The forced claim loss must abort the conversion.');
        } catch (DeliveryNoteAlreadyClaimedException) {
            // Expected: assertions below prove the outer transaction owned every side effect.
        }

        $this->assertSame(0, Document::query()->where('type', DocumentType::DeliveryNote)->count());
        $this->assertSame($deliverySequenceBefore, $this->sequenceNumber(DocumentType::DeliveryNote));
        $this->assertSame($invoiceSequenceBefore, $this->sequenceNumber(DocumentType::Invoice));
        $this->assertSame($payloadBefore, $order->fresh()->payload);
        $this->assertSame('1.2500', (string) $line->fresh()->quantity_delivered);
        $this->assertSame($statusBefore, $order->fresh()->getDeliveryStatus());
        $this->assertSame(
            $storedEventsBefore,
            DB::table('stored_events')->where('event_class', DocumentConverted::class)->count(),
        );
        $this->assertSame(
            $auditEventsBefore,
            DB::table('audit_events')->where('event_type', 'document.converted')->count(),
        );

        $this->app->forgetInstance(DeliveryNoteBillingClaimService::class);
        $this->forgetConverters();

        $invoice = $this->registry()->convert($order->fresh(), DocumentType::Invoice);
        $deliveryNote = Document::query()->where('type', DocumentType::DeliveryNote)->sole();

        $this->assertSame('3.0000', (string) $line->fresh()->quantity_delivered);
        $this->assertSame(DeliveryStatus::FullyDelivered, $order->fresh()->getDeliveryStatus());
        $this->assertSame($invoice->id, $deliveryNote->payload['invoice_id'] ?? null);
        $this->assertSame(DeliveryNoteBillingLane::OrderConversion->value, $deliveryNote->payload['invoiced_via'] ?? null);
        $this->assertDatabaseHas('delivery_note_billing_marks', [
            'delivery_note_id' => $deliveryNote->id,
            'invoice_id' => $invoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::OrderConversion->value,
        ]);
    }

    public function test_consolidation_winner_causes_attributed_batch_atomic_order_refusal(): void
    {
        $order = $this->createOrder();
        $deliveryNote = $this->createDeliveryNoteFrom($order);
        $winner = $this->registry()->convert($deliveryNote->fresh(), DocumentType::Invoice, [
            'delivery_note_ids' => [$deliveryNote->id],
        ]);
        $invoiceSequenceBefore = $this->sequenceNumber(DocumentType::Invoice);
        $invoiceLinesBefore = DocumentLine::query()
            ->whereIn('document_id', Document::query()->where('type', DocumentType::Invoice)->pluck('id'))
            ->count();

        $response = $this->actingAs($this->user)->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'DELIVERY_NOTE_ALREADY_INVOICED')
            ->assertJsonPath('error.details.documents.0.id', $deliveryNote->id)
            ->assertJsonPath('error.details.documents.0.document_number', $deliveryNote->document_number)
            ->assertJsonPath('error.details.documents.0.reason', 'already_invoiced')
            ->assertJsonPath('error.details.documents.0.invoice_id', $winner->id)
            ->assertJsonPath('error.details.documents.0.invoice_number', $winner->document_number)
            ->assertJsonPath('error.details.documents.0.invoice_date', $winner->document_date?->toDateString())
            ->assertJsonPath('error.details.documents.0.invoiced_via', DeliveryNoteBillingLane::Consolidation->value)
            ->assertJsonPath('error.details.billed_order_line_ids.0', $order->lines()->firstOrFail()->id)
            ->assertJsonMissingPath('error.details.documents.0.source_line_ids');
        $this->assertSame(1, Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertSame($invoiceLinesBefore, DocumentLine::query()
            ->whereIn('document_id', Document::query()->where('type', DocumentType::Invoice)->pluck('id'))
            ->count());
        $this->assertSame($invoiceSequenceBefore, $this->sequenceNumber(DocumentType::Invoice));
    }

    public function test_refusal_reports_complete_order_level_billed_lines_from_lost_dns_and_prior_partial_invoices(): void
    {
        $order = $this->createOrderWithPhysicalAndServiceLines();
        $physicalLine = $order->lines->firstWhere('product_id', $this->product->id);
        $priorServiceLine = $order->lines->firstWhere('product_id', null);
        $this->assertInstanceOf(DocumentLine::class, $physicalLine);
        $this->assertInstanceOf(DocumentLine::class, $priorServiceLine);
        $remainingServiceLine = DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 3,
            'product_id' => null,
            'description' => 'Still billable service line',
            'quantity' => '1.0000',
            'quantity_delivered' => '0.0000',
            'unit_price' => '15.000',
            'tax_rate' => '19.00',
            'line_total' => '15.000',
        ]);
        $priorInvoice = $this->registry()->convert($order->fresh(), DocumentType::Invoice, [
            'partial' => true,
            'line_ids' => [$priorServiceLine->id],
        ]);
        $deliveryNote = $this->createLinkedDeliveryNote($order->fresh(), $physicalLine);
        $winner = $this->registry()->convert($deliveryNote, DocumentType::Invoice, [
            'delivery_note_ids' => [$deliveryNote->id],
        ]);
        $expectedBilledLineIds = collect([$physicalLine->id, $priorServiceLine->id])->sort()->values()->all();

        $response = $this->actingAs($this->user)->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $response->assertStatus(422)
            ->assertJsonPath('error.details.billed_order_line_ids', $expectedBilledLineIds)
            ->assertJsonMissingPath('error.details.documents.0.source_line_ids')
            ->assertJsonPath('error.details.documents.0.invoice_id', $winner->id);
        $this->assertSame($priorServiceLine->id, $priorInvoice->lines()->sole()->source_line_id);
        $this->assertNotContains($remainingServiceLine->id, $response->json('error.details.billed_order_line_ids'));
    }

    public function test_legacy_prior_invoice_without_order_line_provenance_disables_recovery_metadata(): void
    {
        $order = $this->createOrderWithPhysicalAndServiceLines();
        $physicalLine = $order->lines->firstWhere('product_id', $this->product->id);
        $serviceLine = $order->lines->firstWhere('product_id', null);
        $this->assertInstanceOf(DocumentLine::class, $physicalLine);
        $this->assertInstanceOf(DocumentLine::class, $serviceLine);
        $legacyInvoice = $this->registry()->convert($order->fresh(), DocumentType::Invoice, [
            'partial' => true,
            'line_ids' => [$serviceLine->id],
        ]);
        $legacyInvoice->lines()->update(['source_line_id' => null]);
        $deliveryNote = $this->createLinkedDeliveryNote($order->fresh(), $physicalLine);
        $this->registry()->convert($deliveryNote, DocumentType::Invoice, [
            'delivery_note_ids' => [$deliveryNote->id],
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $response->assertStatus(422)
            ->assertJsonMissingPath('error.details.billed_order_line_ids')
            ->assertJsonMissingPath('error.details.documents.0.source_line_ids');
    }

    public function test_order_refusal_reports_every_consumed_delivery_note_and_taker_without_numbering(): void
    {
        $order = $this->createOrder();
        $firstLine = $order->lines()->firstOrFail();
        $secondLine = DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 2,
            'product_id' => $this->product->id,
            'description' => 'Second claimed physical line',
            'quantity' => '2.0000',
            'quantity_delivered' => '0.0000',
            'unit_price' => '12.000',
            'tax_rate' => '19.00',
            'line_total' => '24.000',
        ]);
        $firstDeliveryNote = $this->createLinkedDeliveryNote($order->fresh(), $firstLine);
        $secondDeliveryNote = $this->createLinkedDeliveryNote($order->fresh(), $secondLine);
        $deliveryNoteIds = collect([$firstDeliveryNote->id, $secondDeliveryNote->id])->sort()->values()->all();
        $winner = $this->registry()->convert($firstDeliveryNote->fresh(), DocumentType::Invoice, [
            'delivery_note_ids' => $deliveryNoteIds,
        ]);
        $invoiceSequenceBefore = $this->sequenceNumber(DocumentType::Invoice);

        $response = $this->actingAs($this->user)->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'DELIVERY_NOTE_ALREADY_INVOICED')
            ->assertJsonCount(2, 'error.details.documents')
            ->assertJsonPath('error.details.documents.0.id', $deliveryNoteIds[0])
            ->assertJsonPath('error.details.documents.0.reason', 'already_invoiced')
            ->assertJsonPath('error.details.documents.0.invoice_id', $winner->id)
            ->assertJsonPath('error.details.documents.0.invoiced_via', DeliveryNoteBillingLane::Consolidation->value)
            ->assertJsonPath('error.details.documents.1.id', $deliveryNoteIds[1])
            ->assertJsonPath('error.details.documents.1.reason', 'already_invoiced')
            ->assertJsonPath('error.details.documents.1.invoice_id', $winner->id)
            ->assertJsonPath('error.details.documents.1.invoiced_via', DeliveryNoteBillingLane::Consolidation->value)
            ->assertJsonPath(
                'error.details.billed_order_line_ids',
                collect([$firstLine->id, $secondLine->id])->sort()->values()->all(),
            );
        $this->assertSame(1, Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertSame($invoiceSequenceBefore, $this->sequenceNumber(DocumentType::Invoice));
    }

    public function test_unlinked_lost_delivery_note_omits_unsafe_remaining_line_attribution(): void
    {
        $order = $this->createOrder();
        $deliveryNote = $this->createDeliveryNoteFrom($order);
        $deliveryNote->lines()->update(['source_line_id' => null]);
        $winner = $this->registry()->convert($deliveryNote->fresh(), DocumentType::Invoice, [
            'delivery_note_ids' => [$deliveryNote->id],
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'DELIVERY_NOTE_ALREADY_INVOICED')
            ->assertJsonPath('error.details.documents.0.id', $deliveryNote->id)
            ->assertJsonPath('error.details.documents.0.invoice_id', $winner->id)
            ->assertJsonMissingPath('error.details.documents.0.source_line_ids')
            ->assertJsonMissingPath('error.details.billed_order_line_ids');
    }

    public function test_order_winner_causes_attributed_batch_atomic_consolidation_refusal(): void
    {
        $order = $this->createOrder();
        $deliveryNote = $this->createDeliveryNoteFrom($order);
        $winner = $this->registry()->convert($order->fresh(), DocumentType::Invoice);
        $invoiceSequenceBefore = $this->sequenceNumber(DocumentType::Invoice);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$deliveryNote->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'DELIVERY_NOTE_ALREADY_INVOICED')
            ->assertJsonPath('error.details.documents.0.id', $deliveryNote->id)
            ->assertJsonPath('error.details.documents.0.invoice_id', $winner->id)
            ->assertJsonPath('error.details.documents.0.invoice_number', $winner->document_number)
            ->assertJsonPath('error.details.documents.0.invoiced_via', DeliveryNoteBillingLane::OrderConversion->value);
        $this->assertSame(1, Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertSame($invoiceSequenceBefore, $this->sequenceNumber(DocumentType::Invoice));
    }

    public function test_partial_conversion_claims_only_delivery_notes_linked_to_selected_whole_lines(): void
    {
        $order = $this->createOrderWithPhysicalAndServiceLines();
        $physicalLine = $order->lines->firstWhere('product_id', $this->product->id);
        $serviceLine = $order->lines->firstWhere('product_id', null);
        $this->assertInstanceOf(DocumentLine::class, $physicalLine);
        $this->assertInstanceOf(DocumentLine::class, $serviceLine);
        $deliveryNote = $this->createLinkedDeliveryNote($order, $physicalLine);
        $winner = $this->registry()->convert($deliveryNote, DocumentType::Invoice, [
            'delivery_note_ids' => [$deliveryNote->id],
        ]);

        $invoice = $this->registry()->convert($order->fresh(), DocumentType::Invoice, [
            'partial' => true,
            'line_ids' => [$serviceLine->id],
        ]);

        $this->assertCount(1, $invoice->lines);
        $this->assertSame($serviceLine->description, $invoice->lines->firstOrFail()->description);
        $this->assertSame(
            $serviceLine->id,
            $invoice->lines->firstOrFail()->source_line_id,
            'A partial SO invoice line must retain its selected order-line provenance.',
        );
        $this->assertSame(2, Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertDatabaseHas('delivery_note_billing_marks', [
            'delivery_note_id' => $deliveryNote->id,
            'invoice_id' => $winner->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]);
    }

    public function test_partial_physical_conversion_claims_the_intersecting_delivery_note_only(): void
    {
        $order = $this->createOrder();
        $secondLine = DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 2,
            'product_id' => $this->product->id,
            'description' => 'Second physical line',
            'quantity' => '2.0000',
            'quantity_delivered' => '0.0000',
            'unit_price' => '12.000',
            'tax_rate' => '19.00',
            'line_total' => '24.000',
        ]);
        $firstLine = $order->lines()->where('line_number', 1)->firstOrFail();
        $firstDeliveryNote = $this->createLinkedDeliveryNote($order->fresh(), $firstLine);
        $secondDeliveryNote = $this->createLinkedDeliveryNote($order->fresh(), $secondLine);

        $invoice = $this->registry()->convert($order->fresh(), DocumentType::Invoice, [
            'partial' => true,
            'line_ids' => [$secondLine->id],
        ]);

        $this->assertCount(1, $invoice->lines);
        $this->assertSame($secondLine->description, $invoice->lines->firstOrFail()->description);
        $this->assertDatabaseMissing('delivery_note_billing_marks', ['delivery_note_id' => $firstDeliveryNote->id]);
        $this->assertDatabaseHas('delivery_note_billing_marks', [
            'delivery_note_id' => $secondDeliveryNote->id,
            'invoice_id' => $invoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::OrderConversion->value,
        ]);
    }

    public function test_partial_physical_conversion_refuses_a_subset_of_one_multi_line_delivery_note_before_claiming(): void
    {
        $order = $this->createOrder();
        $selectedLine = $order->lines()->firstOrFail();
        $unselectedLine = DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 2,
            'product_id' => $this->product->id,
            'description' => 'Unselected line on the same delivery note',
            'quantity' => '2.0000',
            'quantity_delivered' => '0.0000',
            'unit_price' => '12.000',
            'tax_rate' => '19.00',
            'line_total' => '24.000',
        ]);
        $deliveryNote = $this->createLinkedDeliveryNote($order->fresh(), $selectedLine);
        DocumentLine::create([
            'document_id' => $deliveryNote->id,
            'line_number' => 2,
            'product_id' => $unselectedLine->product_id,
            'description' => $unselectedLine->description,
            'quantity' => $unselectedLine->quantity,
            'unit_price' => $unselectedLine->unit_price,
            'tax_rate' => $unselectedLine->tax_rate,
            'line_total' => $unselectedLine->line_total,
            'source_line_id' => $unselectedLine->id,
        ]);
        $unselectedLine->update(['quantity_delivered' => $unselectedLine->quantity]);
        $invoiceSequenceBefore = $this->sequenceNumber(DocumentType::Invoice);

        try {
            $this->registry()->convert($order->fresh(), DocumentType::Invoice, [
                'partial' => true,
                'line_ids' => [$selectedLine->id],
            ]);
            $this->fail('A partial selection must not claim a DN that also carries an unselected SO line.');
        } catch (DeliveryNoteBatchValidationException $exception) {
            $this->assertSame($deliveryNote->id, $exception->documents[0]['id']);
            $this->assertSame('partial_selection_incomplete', $exception->documents[0]['reason']);
        }

        $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice", [
                'partial' => true,
                'line_ids' => [$selectedLine->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PARTIAL_DELIVERY_NOTE_SELECTION_INCOMPLETE')
            ->assertJsonMissingPath('error.details.billed_order_line_ids');

        $this->assertSame(0, Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertSame($invoiceSequenceBefore, $this->sequenceNumber(DocumentType::Invoice));
        $this->assertDatabaseMissing('delivery_note_billing_marks', ['delivery_note_id' => $deliveryNote->id]);
        $this->assertArrayNotHasKey('invoiced_at', $deliveryNote->fresh()->payload ?? []);

        $winner = $this->registry()->convert($deliveryNote->fresh(), DocumentType::Invoice, [
            'delivery_note_ids' => [$deliveryNote->id],
        ]);
        $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice", [
                'partial' => true,
                'line_ids' => [$selectedLine->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DELIVERY_NOTE_ALREADY_INVOICED')
            ->assertJsonPath('error.details.documents.0.invoice_id', $winner->id);
    }

    #[DataProvider('unverifiableDeliveryNoteSourceProvider')]
    public function test_partial_physical_conversion_refuses_unverifiable_lines_on_an_intersected_delivery_note(
        string $sourceKind,
    ): void {
        $order = $this->createOrder();
        $selectedLine = $order->lines()->sole();
        $deliveryNote = $this->createLinkedDeliveryNote($order, $selectedLine);
        $foreignSourceLineId = $sourceKind === 'foreign'
            ? $this->createOrder()->lines()->sole()->id
            : null;
        DocumentLine::create([
            'document_id' => $deliveryNote->id,
            'line_number' => 2,
            'product_id' => $this->product->id,
            'description' => "{$sourceKind} delivery-note line",
            'quantity' => '1.0000',
            'unit_price' => '8.000',
            'tax_rate' => '19.00',
            'line_total' => '8.000',
            'source_line_id' => $foreignSourceLineId,
        ]);
        $invoiceSequenceBefore = $this->sequenceNumber(DocumentType::Invoice);

        try {
            $this->registry()->convert($order->fresh(), DocumentType::Invoice, [
                'partial' => true,
                'line_ids' => [$selectedLine->id],
            ]);
            $this->fail("A {$sourceKind} line must make the intersected DN unsafe to claim.");
        } catch (DeliveryNoteBatchValidationException $exception) {
            $this->assertSame('partial_selection_incomplete', $exception->documents[0]['reason']);
        }

        $this->assertSame(0, Document::query()->where('type', DocumentType::Invoice)->count());
        $this->assertSame($invoiceSequenceBefore, $this->sequenceNumber(DocumentType::Invoice));
        $this->assertDatabaseMissing('delivery_note_billing_marks', ['delivery_note_id' => $deliveryNote->id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unverifiableDeliveryNoteSourceProvider(): array
    {
        return [
            'unlinked source' => ['unlinked'],
            'foreign order source' => ['foreign'],
        ];
    }

    public function test_quote_conversion_keeps_its_existing_domain_error_envelope(): void
    {
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-'.Str::random(8),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '10.000',
            'tax_amount' => '1.900',
            'total' => '11.900',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/quotes/{$quote->id}/convert-to-order")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'QUOTE_NOT_CONFIRMED')
            ->assertJsonMissingPath('error.details.documents');
    }

    /**
     * M5-terminal treasury F-7 — the integrity alarm must not render as a routine 422.
     *
     * DeliveryNoteClaimNotFinalisedException is the wave's ONLY detector for a broken
     * billed-once invariant; the claim service's own docblock says a committed runtime-lane
     * marker with a null invoice id is a DEFECT. While it extended DomainException it fell
     * through the generic `catch (\DomainException)` handlers and shipped as HTTP 422 under
     * a validation error code — indistinguishable from a customer-data refusal, producing no
     * 500, no alert, and an operator-facing message reading "Delivery-note payload
     * finalisation affected 0 rows; expected 1." It must surface as a 500-class alert.
     */
    public function test_a_broken_billed_once_invariant_surfaces_as_a_server_error_not_a_validation_refusal(): void
    {
        $order = $this->createOrder();
        $this->createDeliveryNoteFrom($order);

        $connection = DB::connection();
        $shortFinalise = new class($connection) extends DeliveryNoteBillingClaimService
        {
            public function __construct(ConnectionInterface $db)
            {
                parent::__construct($db);
            }

            public function claim(DeliveryNoteClaimRequest $request, Closure $createInvoice): DeliveryNoteClaimSet
            {
                throw DeliveryNoteClaimNotFinalisedException::forPayloadCount(1, 0);
            }
        };
        $this->app->instance(DeliveryNoteBillingClaimService::class, $shortFinalise);
        $this->forgetConverters();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $this->assertGreaterThanOrEqual(
            500,
            $response->getStatusCode(),
            'A broken billed-once invariant must raise a 500-class alert, never a routine 422.',
        );

        $this->app->forgetInstance(DeliveryNoteBillingClaimService::class);
        $this->forgetConverters();
    }

    /**
     * M5-terminal treasury F-5 — lock-order conformance for the standalone SO->DN lane.
     *
     * The wave's global order is L1 sales-order header < L2 document_sequences[delivery_note].
     * SalesOrderToInvoiceConverter takes L1 immediately after BEGIN and only then reaches L2
     * on its auto-create branch. SalesOrderToDeliveryNoteConverter used to do the opposite —
     * createTargetDocument (L2) first, and L1 only later via appendToSourcePayload's write to
     * the order header — so two concurrent requests on the SAME sales order formed a wait-for
     * cycle that PostgreSQL broke with 40P01 (a 500 on the invoice lane, a 422 carrying a
     * deadlock string on this one, since this lane has no retrier).
     *
     * A two-session deadlock reproduction is inherently racy; the invariant that actually
     * matters is the ORDER in which the two locks are requested, which is deterministic and
     * asserted directly here. The cycle is unreachable while this ordering holds.
     */
    public function test_delivery_note_conversion_locks_the_order_header_before_the_delivery_note_sequence(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite's grammar compiles `lockForUpdate()` to nothing, so there is no
            // FOR UPDATE statement to order — and no deadlock to prevent. The invariant is
            // a PostgreSQL row-lock property, matching the engine gate
            // DeliveryNoteConsolidationConcurrencyTest.php:164 uses for the same reason.
            $this->markTestSkipped('Lock-order conformance requires PostgreSQL.');
        }

        $order = $this->createOrder();

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $this->registry()->convert($order, DocumentType::DeliveryNote);

        // L1 — the sales-order header row, SELECT ... FOR UPDATE on this order's id.
        $headerLock = $this->queryPosition($queries, 'for update', $order->id);
        // L2 — the delivery-note document_sequences row.
        $sequenceLock = $this->queryPosition(
            $queries,
            'document_sequences',
            DocumentType::DeliveryNote->value,
        );

        $this->assertLessThan(
            $sequenceLock,
            $headerLock,
            'SO->DN conversion must take L1 (order header) before L2 (delivery-note sequence), '
            .'matching SalesOrderToInvoiceConverter, or the two lanes deadlock on the same order.',
        );
    }

    private function createOrder(string $quantityDelivered = '0.0000'): Document
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-'.Str::random(8),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '30.000',
            'tax_amount' => '5.700',
            'total' => '35.700',
            'balance_due' => '35.700',
        ]);
        DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 1,
            'product_id' => $this->product->id,
            'description' => 'Claimed physical line',
            'quantity' => '3.0000',
            'quantity_delivered' => $quantityDelivered,
            'unit_price' => '10.000',
            'tax_rate' => '19.00',
            'line_total' => '30.000',
        ]);

        return $order->fresh(['lines', 'partner']);
    }

    private function createDeliveryNoteFrom(Document $order): Document
    {
        $deliveryNote = $this->registry()->convert($order, DocumentType::DeliveryNote);
        $deliveryNote->update(['status' => DocumentStatus::Confirmed]);

        return $deliveryNote->fresh(['lines']);
    }

    private function createOrderWithPhysicalAndServiceLines(): Document
    {
        $order = $this->createOrder();
        DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 2,
            'product_id' => null,
            'description' => 'Remaining service line',
            'quantity' => '1.0000',
            'quantity_delivered' => '0.0000',
            'unit_price' => '20.000',
            'tax_rate' => '19.00',
            'line_total' => '20.000',
        ]);

        return $order->fresh(['lines', 'partner']);
    }

    private function createLinkedDeliveryNote(Document $order, DocumentLine $sourceLine): Document
    {
        $deliveryNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-'.Str::random(8),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '30.000',
            'tax_amount' => '5.700',
            'total' => '35.700',
        ]);
        DocumentLine::create([
            'document_id' => $deliveryNote->id,
            'line_number' => 1,
            'product_id' => $sourceLine->product_id,
            'description' => $sourceLine->description,
            'quantity' => $sourceLine->quantity,
            'unit_price' => $sourceLine->unit_price,
            'tax_rate' => $sourceLine->tax_rate,
            'line_total' => $sourceLine->line_total,
            'source_line_id' => $sourceLine->id,
        ]);
        $payload = $order->payload ?? [];
        $deliveryNoteIds = $payload['delivery_note_ids'] ?? [];
        $deliveryNoteIds[] = $deliveryNote->id;
        $payload['delivery_note_ids'] = $deliveryNoteIds;
        $order->update(['payload' => $payload]);
        $sourceLine->update(['quantity_delivered' => $sourceLine->quantity]);

        return $deliveryNote->fresh(['lines']);
    }

    private function registry(): DocumentConverterRegistry
    {
        return $this->app->make(DocumentConverterRegistry::class);
    }

    private function forgetConverters(): void
    {
        $this->app->forgetInstance(DocumentConverterRegistry::class);
        $this->app->forgetInstance(SalesOrderToInvoiceConverter::class);
    }

    private function sequenceNumber(DocumentType $type): int
    {
        return (int) DB::table('document_sequences')
            ->where('company_id', $this->company->id)
            ->where('type', $type->value)
            ->where('year', (int) date('Y'))
            ->value('last_number');
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $queries
     */
    private function queryPosition(array $queries, string $sqlFragment, string ...$bindings): int
    {
        foreach ($queries as $position => $query) {
            if (! str_contains($query['sql'], $sqlFragment)) {
                continue;
            }

            $actual = array_map('strval', $query['bindings']);
            if (collect($bindings)->every(static fn (string $binding): bool => in_array($binding, $actual, true))) {
                return $position;
            }
        }

        $this->fail('Expected query was not executed: '.$sqlFragment.' with '.implode(', ', $bindings));
    }
}
