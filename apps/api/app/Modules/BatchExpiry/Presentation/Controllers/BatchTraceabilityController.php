<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Identity\Domain\User;
use App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader;
use App\Shared\Contracts\BatchTraceability\PosBatchTraceReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Batch traceability endpoints for recall compliance.
 *
 * Forward trace: batch → which customers received it
 * Backward trace: customer → which batches they received
 */
class BatchTraceabilityController extends Controller
{
    public function __construct(
        private readonly BatchRepositoryInterface $batchRepository,
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
        private readonly LocationScopeResolver $locationScopeResolver,
        private readonly LotActionPermissionActivation $activation,
        private readonly DocumentBatchTraceReader $documentTraceReader,
        private readonly PosBatchTraceReader $posTraceReader,
    ) {}

    /**
     * Forward trace: Given a batch, find all sales (documents + POS receipts).
     *
     * GET /api/v1/batches/{uuid}/traceability
     */
    public function forwardTrace(Request $request, string $uuid): JsonResponse
    {
        if (! Str::isUuid($uuid)) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        $company = $this->companyContext->requireCompany();
        $locations = $this->resolvedTraceLocationIds($request);
        $history = $locations === null ? [] : array_values(array_unique(array_merge(
            $this->documentTraceReader->batchIdsVisibleAtLocations($company->tenant_id, $company->id, $locations),
            $this->posTraceReader->batchIdsVisibleAtLocations($company->tenant_id, $company->id, $locations),
        )));
        $batch = $this->batchRepository->findVisibleByUuid($uuid, $company->id, $locations, $history);

        if ($batch === null || $batch->company_id !== $this->companyContext->requireCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        $documentSales = array_map(static fn ($row): array => [
            'type' => $row->type, 'document_number' => $row->documentNumber,
            'document_type' => $row->documentType, 'document_date' => $row->documentDate,
            'partner_name' => $row->partnerName, 'partner_id' => $row->partnerId,
            'product_name' => $row->productName, 'quantity' => $row->quantity,
        ], $this->documentTraceReader->forwardForBatch($company->tenant_id, $company->id, $batch->id, $locations));
        $posSales = array_map(static fn ($row): array => [
            'type' => $row->type, 'receipt_number' => $row->receiptNumber, 'sale_date' => $row->saleDate,
            'customer_name' => $row->customerName, 'customer_identifier' => $row->customerIdentifier,
            'batch_number' => $row->batchNumber, 'quantity' => $row->quantity,
        ], $this->posTraceReader->forwardForBatch($company->tenant_id, $company->id, $batch->id, $locations));

        return response()->json([
            'data' => [
                'batch' => [
                    'id' => $batch->id,
                    'uuid' => $batch->uuid,
                    'batch_number' => $batch->batch_number,
                    'product_name' => $batch->product->name ?? 'Unknown',
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'is_recalled' => $batch->is_recalled,
                ],
                'document_sales' => $documentSales,
                'pos_sales' => $posSales,
                'total_sales_count' => count($documentSales) + count($posSales),
            ],
        ]);
    }

    /**
     * Backward trace: Given a partner, find all batches they received.
     *
     * GET /api/v1/partners/{partnerId}/batch-history
     */
    public function backwardTrace(Request $request, string $partnerId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $rows = $this->documentTraceReader->backwardForPartner(
            $company->tenant_id, $company->id, $partnerId, $this->resolvedTraceLocationIds($request),
            $request->input('product_id'), $request->input('date_from'), $request->input('date_to'),
        );

        return response()->json(['data' => array_map(static fn ($row): array => [
            'batch_number' => $row->batchNumber, 'batch_id' => $row->batchId,
            'expiry_date' => $row->expiryDate, 'is_recalled' => $row->isRecalled, 'is_expired' => $row->isExpired,
            'product_name' => $row->productName, 'product_id' => $row->productId, 'quantity' => $row->quantity,
            'document_number' => $row->documentNumber, 'document_type' => $row->documentType,
            'document_date' => $row->documentDate,
        ], $rows)]);
    }

    /** @return list<string>|null */
    private function resolvedTraceLocationIds(Request $request): ?array
    {
        if (! $this->activation->enforced()) {
            return null;
        }
        $validated = $request->validate([
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'location_ids' => ['sometimes', 'array', 'list'], 'location_ids.*' => ['uuid'],
        ]);
        $requested = $validated['location_ids'] ?? (isset($validated['location_id']) ? [$validated['location_id']] : []);
        /** @var User $user */
        $user = $request->user();
        if ($requested === [] && $this->locationContext->getAllowedLocationIds($this->companyContext->requireCompanyId(), $user) === null) {
            return null;
        }

        return $this->locationScopeResolver->resolve($user, $requested);
    }
}
