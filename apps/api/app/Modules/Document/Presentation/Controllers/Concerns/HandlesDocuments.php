<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers\Concerns;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Vehicle\Application\Services\VehicleContextBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared document handling methods for document-type controllers.
 *
 * This trait extracts common patterns from DocumentController for reuse across
 * all document-related controllers (CreditNoteController, DraftController, etc.).
 *
 * Controllers using this trait MUST:
 * - Inject CompanyContext via constructor
 * - Have a protected property: protected readonly CompanyContext $companyContext
 */
trait HandlesDocuments
{
    /**
     * Get the CompanyContext service.
     *
     * Controllers must implement this to provide the injected CompanyContext.
     */
    abstract protected function getCompanyContext(): CompanyContext;

    /**
     * Build a base query scoped to the current company.
     *
     * @return Builder<Document>
     */
    protected function baseQuery(): Builder
    {
        $companyId = $this->getCompanyContext()->requireCompanyId();

        return Document::forCompany($companyId);
    }

    /**
     * Format a Document model as a JSON response.
     *
     * @param  int  $statusCode  HTTP status code (default 200)
     */
    protected function documentResponse(Document $document, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'data' => DocumentData::fromModel($document),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ], $statusCode);
    }

    /**
     * Format a Document model as a created response (201).
     */
    protected function documentCreatedResponse(Document $document): JsonResponse
    {
        return $this->documentResponse($document, 201);
    }

    /**
     * Get the default relations for eager loading documents.
     *
     * @return list<string>
     */
    protected function defaultRelations(): array
    {
        return ['lines', 'vehicleContext'];
    }

    /**
     * Get extended relations for document detail views.
     *
     * @return list<string>
     */
    protected function detailRelations(): array
    {
        return ['lines', 'allocations.payment.paymentMethod', 'vehicleContext'];
    }

    /**
     * Apply common filters to a document query.
     *
     * Supports:
     * - status: Filter by DocumentStatus enum value
     * - partner_id: Filter by partner UUID
     * - search: Search by document number (LIKE match)
     * - date_from: Filter documents from this date (inclusive)
     * - date_to: Filter documents up to this date (inclusive)
     * - product_id: Filter documents containing a specific product
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    protected function applyFilters(Builder $query, Request $request): Builder
    {
        // Filter by status
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $statusEnum = DocumentStatus::tryFrom($status);
            if ($statusEnum !== null) {
                $query->inStatus($statusEnum);
            }
        }

        // Filter by partner
        $partnerId = $request->query('partner_id');
        if (is_string($partnerId) && $partnerId !== '') {
            $query->where('partner_id', $partnerId);
        }

        // Search by document number
        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where('document_number', 'like', "%{$search}%");
        }

        // Filter by date range
        $dateFrom = $request->query('date_from');
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->whereDate('document_date', '>=', $dateFrom);
        }

        $dateTo = $request->query('date_to');
        if (is_string($dateTo) && $dateTo !== '') {
            $query->whereDate('document_date', '<=', $dateTo);
        }

        // Filter by product (returns documents with lines containing this product)
        $productId = $request->query('product_id');
        if (is_string($productId) && $productId !== '') {
            $query->whereHas('lines', function ($q) use ($productId): void {
                /** @phpstan-ignore argument.type */
                $q->where('product_id', $productId);
            });
        }

        return $query;
    }

    /**
     * Create or update vehicle context for a document.
     *
     * This method handles the vehicle_context field from request data:
     * - Creates new context if provided and document has none
     * - Updates existing context if provided
     * - Deletes context if explicitly set to null
     *
     * @param  array{vehicle_id: string, mileage?: int}|null  $vehicleContextData
     */
    protected function attachVehicleContext(
        Document $document,
        ?array $vehicleContextData,
        bool $wasExplicitlyProvided = true
    ): void {
        // Only process if the field was actually provided in the request
        if (! $wasExplicitlyProvided) {
            return;
        }

        if ($vehicleContextData !== null) {
            $vehicleContextBuilder = app(VehicleContextBuilder::class);

            // Build authoritative context from vehicle_id
            $builtContext = $vehicleContextBuilder->buildFromVehicleId(
                vehicleId: $vehicleContextData['vehicle_id'],
                tenantId: $document->tenant_id,
                companyId: $document->company_id,
                mileage: $vehicleContextData['mileage'] ?? null
            );

            $document->vehicleContext()->updateOrCreate(
                ['document_id' => $document->id],
                [
                    'vehicle_id' => $builtContext['vehicle_id'],
                    'vehicle_snapshot' => $builtContext['snapshot'],
                    'mileage_at_service' => $builtContext['mileage'],
                    'context_data' => $builtContext['additional_data'],
                ]
            );
        } else {
            // Explicitly set to null means delete the context
            $document->vehicleContext()->delete();
        }
    }

    /**
     * Create vehicle context for a new document.
     *
     * This is used during document creation when we don't have an existing
     * document to update. It creates a fresh DocumentVehicleContext record.
     *
     * @param  array{vehicle_id: string, mileage?: int}  $vehicleContextData
     */
    protected function createVehicleContext(
        Document $document,
        array $vehicleContextData,
        string $tenantId,
        string $companyId
    ): void {
        $vehicleContextBuilder = app(VehicleContextBuilder::class);

        // Build authoritative context from vehicle_id
        $builtContext = $vehicleContextBuilder->buildFromVehicleId(
            vehicleId: $vehicleContextData['vehicle_id'],
            tenantId: $tenantId,
            companyId: $companyId,
            mileage: $vehicleContextData['mileage'] ?? null
        );

        DocumentVehicleContext::create([
            'document_id' => $document->id,
            'vehicle_id' => $builtContext['vehicle_id'],
            'vehicle_snapshot' => $builtContext['snapshot'],
            'mileage_at_service' => $builtContext['mileage'],
            'context_data' => $builtContext['additional_data'],
        ]);
    }

    /**
     * Return a standardized not-found error response.
     *
     * @param  string  $resource  The resource type (e.g., 'Document', 'Invoice')
     */
    protected function notFoundResponse(string $resource = 'Document'): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => "{$resource} not found",
            ],
        ], 404);
    }

    /**
     * Return a standardized validation error response.
     */
    protected function validationErrorResponse(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 422);
    }

    /**
     * Return a standardized error response for fiscal immutability violations.
     */
    protected function fiscalImmutabilityErrorResponse(): JsonResponse
    {
        return $this->validationErrorResponse(
            'DOCUMENT_SEALED',
            'Sealed fiscal documents cannot be modified. Use credit notes for corrections.'
        );
    }

    /**
     * Return a standardized error response for non-editable documents.
     */
    protected function notEditableErrorResponse(): JsonResponse
    {
        return $this->validationErrorResponse(
            'DOCUMENT_NOT_EDITABLE',
            'Posted documents cannot be modified'
        );
    }

    /**
     * Return a standardized error response for non-deletable documents.
     */
    protected function notDeletableErrorResponse(): JsonResponse
    {
        return $this->validationErrorResponse(
            'DOCUMENT_NOT_DELETABLE',
            'Posted documents cannot be deleted. Use cancellation instead.'
        );
    }

    /**
     * Return a standardized error response for fiscal non-deletable documents.
     */
    protected function fiscalNotDeletableErrorResponse(): JsonResponse
    {
        return $this->validationErrorResponse(
            'FISCAL_DOCUMENT_NOT_DELETABLE',
            'Sealed fiscal documents cannot be deleted. Use credit notes for corrections.'
        );
    }
}
