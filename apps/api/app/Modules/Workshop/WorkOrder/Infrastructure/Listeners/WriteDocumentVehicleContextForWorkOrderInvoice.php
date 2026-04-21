<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure\Listeners;

use App\Modules\Document\Domain\Contracts\DocumentVehicleContextWriterInterface;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;

/**
 * Closes audit finding 🔴-4b: when an Invoice or CreditNote is posted for a
 * Document that originated from a WorkOrder (non-NULL `documents.work_order_id`,
 * enabled by the 🔴-4a fillable fix), snapshot the WorkOrder's vehicle into
 * `document_vehicle_contexts` so fiscal reporting can correlate services back
 * to the vehicle that received them.
 *
 * The snapshot is the Vehicle's identifying fields AT TIME OF INVOICING —
 * later vehicle edits MUST NOT retroactively mutate the historical document
 * (fiscal immutability). The writer uses `writeIfAbsent` so event replay is
 * idempotent.
 *
 * Module boundary (rule #6):
 *  - Workshop uses `WorkOrderRepositoryInterface` to load the WO — no leakage
 *    of Eloquent internals into the Application layer.
 *  - Vehicle data is fetched through `VehicleRepositoryInterface` (Vehicle's
 *    public contract). Workshop does not read Eloquent directly.
 *  - `DocumentVehicleContext` is written through the Document module's public
 *    `DocumentVehicleContextWriterInterface`. Workshop NEVER imports the
 *    Document or DocumentVehicleContext Eloquent models.
 */
final readonly class WriteDocumentVehicleContextForWorkOrderInvoice
{
    public function __construct(
        private WorkOrderRepositoryInterface $workOrders,
        private VehicleRepositoryInterface $vehicles,
        private DocumentVehicleContextWriterInterface $contextWriter,
    ) {}

    public function handle(InvoicePosted $event): void
    {
        // Only fiscal invoice-family documents carry vehicle context. Quotes
        // reach a different path (Draft document, no InvoicePosted) and
        // DeliveryNotes / SalesOrders never dispatch this event.
        $type = DocumentType::tryFrom($event->documentType);
        if ($type === null || ! in_array($type, [DocumentType::Invoice, DocumentType::CreditNote], true)) {
            return;
        }

        // InvoicePosted is an immutable event (rule #8), so it can't be extended
        // to carry `work_order_id`. Instead, fetch it through the Document
        // module's public contract — preserves the cross-module boundary
        // (rule #6) by avoiding a direct `Document::query()` call here.
        $workOrderId = $this->contextWriter->findWorkOrderIdForDocument($event->invoiceId);

        if ($workOrderId === null) {
            return; // Non-WO invoice (e.g., IziPOS retail sale) — nothing to do.
        }

        $workOrder = $this->workOrders->findById($workOrderId);
        if ($workOrder === null) {
            return;
        }

        $vehicle = $this->vehicles->findById($workOrder->vehicle_id);
        if ($vehicle === null) {
            return;
        }

        $snapshot = [
            'license_plate' => $vehicle->license_plate,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'vin' => $vehicle->vin,
            'color' => $vehicle->color,
            'fuel_type' => $vehicle->fuel_type?->value,
        ];

        // `mileage_at_intake` is the authoritative mileage captured at service
        // intake. Completion mileage (if any) is persisted separately on the
        // VehicleMileageReading timeline — not on this historical snapshot.
        $this->contextWriter->writeIfAbsent(
            documentId: $event->invoiceId,
            vehicleId: $vehicle->id,
            vehicleSnapshot: $snapshot,
            mileageAtService: $workOrder->mileage_at_intake,
            contextData: [
                'work_order_id' => $workOrder->id,
                'work_order_number' => $workOrder->work_order_number,
            ],
        );
    }
}
