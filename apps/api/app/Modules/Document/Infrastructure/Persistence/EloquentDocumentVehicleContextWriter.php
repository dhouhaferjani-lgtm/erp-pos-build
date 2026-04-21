<?php

declare(strict_types=1);

namespace App\Modules\Document\Infrastructure\Persistence;

use App\Modules\Document\Domain\Contracts\DocumentVehicleContextWriterInterface;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentVehicleContext;

/**
 * Eloquent implementation of the public Document-module writer that attaches
 * a vehicle snapshot to a fiscal Document. See
 * {@see DocumentVehicleContextWriterInterface} for the boundary contract.
 *
 * Idempotency: the table has a unique index on `document_id`. This writer
 * short-circuits before attempting an insert when a row is already present,
 * preserving any prior snapshot (fiscal immutability).
 */
final readonly class EloquentDocumentVehicleContextWriter implements DocumentVehicleContextWriterInterface
{
    public function findWorkOrderIdForDocument(string $documentId): ?string
    {
        /** @var string|null $workOrderId */
        $workOrderId = Document::query()
            ->where('id', $documentId)
            ->value('work_order_id');

        return $workOrderId;
    }

    public function writeIfAbsent(
        string $documentId,
        string $vehicleId,
        array $vehicleSnapshot,
        ?int $mileageAtService = null,
        ?array $contextData = null,
    ): void {
        $exists = DocumentVehicleContext::query()
            ->where('document_id', $documentId)
            ->exists();

        if ($exists) {
            return;
        }

        DocumentVehicleContext::query()->create([
            'document_id' => $documentId,
            'vehicle_id' => $vehicleId,
            'vehicle_snapshot' => $vehicleSnapshot,
            'mileage_at_service' => $mileageAtService,
            'context_data' => $contextData,
        ]);
    }
}
