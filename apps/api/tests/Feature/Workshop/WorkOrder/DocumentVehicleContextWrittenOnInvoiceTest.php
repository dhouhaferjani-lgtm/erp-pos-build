<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Workshop\WorkOrder\Application\Commands\TransitionStatusCommand;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use App\Modules\Workshop\WorkOrder\Infrastructure\Listeners\WriteDocumentVehicleContextForWorkOrderInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Audit finding 🔴-4b: posting an Invoice generated from a WorkOrder did not
 * write a `document_vehicle_contexts` row, so fiscal documents lost the
 * automotive context (vehicle identity + mileage snapshot at time of service).
 *
 * After the fix, a listener reacts to `InvoicePosted` events. When the invoice
 * is linked to a WorkOrder (via `documents.work_order_id`, which also requires
 * 🔴-4a's fillable fix), the listener snapshots the Vehicle's identifying
 * fields into `document_vehicle_contexts`. The snapshot is immutable at the
 * time of invoicing so later vehicle edits do not retroactively mutate a
 * sealed fiscal document.
 */
final class DocumentVehicleContextWrittenOnInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        // The Accounting module's InvoicePosted listener needs a seeded chart
        // of accounts to write GL entries. This test's scope is the Workshop
        // listener that snapshots the vehicle context — not GL posting. Detach
        // ALL InvoicePosted listeners (registered by Laravel's EventServiceProvider
        // during boot) then re-register only the Workshop listener under test.
        Event::forget(InvoicePosted::class);
        Event::listen(
            InvoicePosted::class,
            WriteDocumentVehicleContextForWorkOrderInvoice::class,
        );
    }

    public function test_invoice_posted_from_wo_with_vehicle_writes_context_with_snapshot(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => '123-TN-456',
            'brand' => 'Renault',
            'model' => 'Clio IV',
            'year' => 2019,
            'vin' => 'VF1RJL00166123456',
        ]);

        $wo = WorkOrder::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'vehicle_id' => $vehicle->id,
            'mileage_at_intake' => 112_500,
        ]);

        WorkOrderLine::factory()->part()->create([
            'tenant_id' => $wo->tenant_id,
            'work_order_id' => $wo->id,
        ]);

        $service = $this->app->make(WorkOrderTransitionService::class);
        $updated = $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Invoiced,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            context: null,
        ));

        $invoiceId = $updated->invoice_document_id;
        $this->assertNotNull($invoiceId, 'WO→Invoice transition must write invoice_document_id on the WO.');

        /** @var Document $invoice */
        $invoice = Document::findOrFail($invoiceId);

        // Fillable fix (🔴-4a): documents.work_order_id is now populated.
        $this->assertSame(
            $wo->id,
            $invoice->work_order_id,
            'documents.work_order_id must match the originating WorkOrder id after fillable fix (🔴-4a).',
        );

        // Listener fix (🔴-4b): a DocumentVehicleContext row must exist.
        $contexts = DocumentVehicleContext::query()
            ->where('document_id', $invoice->id)
            ->get();
        $this->assertCount(
            1,
            $contexts,
            'Exactly one document_vehicle_contexts row must be written for a WO-invoice with a vehicle.',
        );

        /** @var DocumentVehicleContext $context */
        $context = $contexts->first();
        $this->assertSame($vehicle->id, $context->vehicle_id);

        $snapshot = $context->vehicle_snapshot ?? [];
        $this->assertSame('123-TN-456', $snapshot['license_plate'] ?? null);
        $this->assertSame('Renault', $snapshot['brand'] ?? null);
        $this->assertSame('Clio IV', $snapshot['model'] ?? null);
        $this->assertSame(2019, $snapshot['year'] ?? null);
        $this->assertSame('VF1RJL00166123456', $snapshot['vin'] ?? null);
    }

    public function test_invoice_from_wo_without_vehicle_writes_no_vehicle_context(): void
    {
        // The Workshop WorkOrder schema requires vehicle_id (NOT NULL), so this
        // test simulates the listener early-returning by directly dispatching
        // InvoicePosted for a non-WO invoice. No DocumentVehicleContext row
        // must be written.
        $nonWoInvoice = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'work_order_id' => null,
        ]);

        event(new InvoicePosted(
            invoiceId: $nonWoInvoice->id,
            tenantId: $nonWoInvoice->tenant_id,
            companyId: $nonWoInvoice->company_id,
            documentNumber: $nonWoInvoice->document_number,
            documentType: DocumentType::Invoice->value,
            partnerId: $nonWoInvoice->partner_id,
            total: (string) ($nonWoInvoice->total ?? '0.00'),
            currency: $nonWoInvoice->currency,
            fiscalHash: 'deadbeef',
            chainSequence: 1,
            postedAt: (new \DateTimeImmutable)->format('c'),
        ));

        $this->assertSame(
            0,
            DocumentVehicleContext::query()->where('document_id', $nonWoInvoice->id)->count(),
            'Non-WO invoices must not generate a DocumentVehicleContext row.',
        );
    }

    public function test_listener_is_idempotent_under_event_replay(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $wo = WorkOrder::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'vehicle_id' => $vehicle->id,
        ]);

        WorkOrderLine::factory()->part()->create([
            'tenant_id' => $wo->tenant_id,
            'work_order_id' => $wo->id,
        ]);

        $service = $this->app->make(WorkOrderTransitionService::class);
        $updated = $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Invoiced,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            context: null,
        ));

        $invoiceId = (string) $updated->invoice_document_id;
        $this->assertSame(
            1,
            DocumentVehicleContext::query()->where('document_id', $invoiceId)->count(),
            'First listener run must insert one DocumentVehicleContext row.',
        );

        // Simulate event replay (e.g., retried queue job). The listener must
        // not double-insert — the (document_id) unique index is the belt-and-
        // braces against that, but the listener should short-circuit before
        // even trying.
        /** @var Document $invoice */
        $invoice = Document::findOrFail($invoiceId);
        event(new InvoicePosted(
            invoiceId: $invoice->id,
            tenantId: $invoice->tenant_id,
            companyId: $invoice->company_id,
            documentNumber: $invoice->document_number,
            documentType: $invoice->type->value,
            partnerId: $invoice->partner_id,
            total: (string) ($invoice->total ?? '0.00'),
            currency: $invoice->currency,
            fiscalHash: $invoice->fiscal_hash ?? 'replayed',
            chainSequence: $invoice->chain_sequence ?? 1,
            postedAt: (new \DateTimeImmutable)->format('c'),
        ));

        $this->assertSame(
            1,
            DocumentVehicleContext::query()->where('document_id', $invoiceId)->count(),
            'Replaying InvoicePosted must not create a duplicate DocumentVehicleContext.',
        );
    }
}
