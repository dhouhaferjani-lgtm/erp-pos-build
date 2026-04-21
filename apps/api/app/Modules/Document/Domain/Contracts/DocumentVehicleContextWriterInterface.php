<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Contracts;

/**
 * Public Document-module contract for writing vehicle context rows against
 * a fiscal Document. Allows non-Document modules (e.g., Workshop) to link
 * a vehicle to a Document without importing the `DocumentVehicleContext`
 * Eloquent model directly — module boundaries are preserved (see rule #6).
 *
 * The snapshot is immutable at the time of invoicing: later vehicle edits
 * must not retroactively mutate historical documents (fiscal-immutability
 * principle).
 */
interface DocumentVehicleContextWriterInterface
{
    /**
     * Look up the originating `work_order_id` for a Document without exposing
     * the Document Eloquent model to the caller.
     *
     * Listeners in non-Document modules (e.g., Workshop's
     * `WriteDocumentVehicleContextForWorkOrderInvoice`) use this to decide
     * whether the posted document originated from a WorkOrder.
     */
    public function findWorkOrderIdForDocument(string $documentId): ?string;

    /**
     * Idempotently write a vehicle-context row for a fiscal Document.
     *
     * If a row already exists for the given `$documentId` (the table enforces
     * a unique constraint on `document_id`), this method is a no-op and must
     * not overwrite a prior snapshot — historical documents are immutable.
     *
     * @param  array<string, mixed>  $vehicleSnapshot  Keys: license_plate, brand, model, year, vin, color, fuel_type.
     * @param  array<string, mixed>|null  $contextData  Free-form context payload (e.g., intake notes).
     */
    public function writeIfAbsent(
        string $documentId,
        string $vehicleId,
        array $vehicleSnapshot,
        ?int $mileageAtService = null,
        ?array $contextData = null,
    ): void;
}
