<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\POS\Domain\ReceiptLineBatchAllocation;
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
    ) {}

    /**
     * Forward trace: Given a batch, find all sales (documents + POS receipts).
     *
     * GET /api/v1/batches/{uuid}/traceability
     */
    public function forwardTrace(string $uuid): JsonResponse
    {
        if (! Str::isUuid($uuid)) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        $batch = $this->batchRepository->findByUuid($uuid);

        if ($batch === null || $batch->company_id !== $this->companyContext->requireCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        // Document sales (invoices, delivery notes)
        $documentSales = DocumentLine::where('batch_id', $batch->id)
            ->whereHas('document', fn ($q) => $q->whereIn('type', [DocumentType::Invoice, DocumentType::DeliveryNote]))
            ->with(['document:id,document_number,type,document_date,partner_id', 'document.partner:id,name'])
            ->get()
            ->map(fn (DocumentLine $line): array => [
                'type' => 'document',
                'document_number' => $line->document->document_number,
                'document_type' => $line->document->type->value,
                'document_date' => $line->document->document_date,
                'partner_name' => $line->document->partner->name ?? 'Unknown',
                'partner_id' => $line->document->partner_id,
                'product_name' => $line->description,
                'quantity' => $line->quantity,
            ]);

        // POS sales
        $posSales = ReceiptLineBatchAllocation::where('batch_id', $batch->id)
            ->with(['receipt:id,receipt_number,created_at,customer_name,customer_identifier'])
            ->get()
            ->map(fn (ReceiptLineBatchAllocation $alloc): array => [
                'type' => 'pos_receipt',
                'receipt_number' => $alloc->receipt?->receipt_number,
                'sale_date' => $alloc->receipt?->created_at?->toDateString(),
                'customer_name' => $alloc->receipt?->customer_name,
                'customer_identifier' => $alloc->receipt?->customer_identifier,
                'batch_number' => $alloc->batch_number,
                'quantity' => $alloc->quantity,
            ]);

        return response()->json([
            'data' => [
                'batch' => [
                    'id' => $batch->id,
                    'uuid' => $batch->uuid,
                    'batch_number' => $batch->batch_number,
                    'product_name' => $batch->product->name ?? 'Unknown',
                    'expiry_date' => $batch->expiry_date->toDateString(),
                    'is_recalled' => $batch->is_recalled,
                ],
                'document_sales' => $documentSales->toArray(),
                'pos_sales' => $posSales->toArray(),
                'total_sales_count' => $documentSales->count() + $posSales->count(),
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
        $companyId = $this->companyContext->requireCompanyId();

        $query = DocumentLine::whereNotNull('batch_id')
            ->whereHas('document', function (\Illuminate\Database\Eloquent\Builder $q) use ($partnerId, $companyId): void {
                $q->whereRaw('partner_id = ?', [$partnerId])
                    ->whereRaw('company_id = ?', [$companyId])
                    ->whereIn('type', [DocumentType::Invoice, DocumentType::DeliveryNote]);
            })
            ->with([
                'document:id,document_number,type,document_date',
                'batch:id,batch_number,expiry_date,is_recalled,is_expired',
            ]);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->has('date_from')) {
            $query->whereHas('document', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->whereRaw('document_date >= ?', [$request->input('date_from')]));
        }

        if ($request->has('date_to')) {
            $query->whereHas('document', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->whereRaw('document_date <= ?', [$request->input('date_to')]));
        }

        $lines = $query->get();

        $batchHistory = $lines->map(fn (DocumentLine $line): array => [
            'batch_number' => $line->batch->batch_number ?? null,
            'batch_id' => $line->batch_id,
            'expiry_date' => $line->batch?->expiry_date?->toDateString(),
            'is_recalled' => $line->batch !== null ? $line->batch->is_recalled : false,
            'is_expired' => $line->batch !== null ? $line->batch->is_expired : false,
            'product_name' => $line->description,
            'product_id' => $line->product_id,
            'quantity' => $line->quantity,
            'document_number' => $line->document->document_number,
            'document_type' => $line->document->type->value,
            'document_date' => $line->document->document_date,
        ]);

        return response()->json([
            'data' => $batchHistory->toArray(),
        ]);
    }
}
