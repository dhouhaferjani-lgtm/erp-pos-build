<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Taxation\Application\DTOs\CreateSalesWithholdingTrackingData;
use App\Modules\Taxation\Application\Services\SalesWithholdingTrackingService;
use App\Modules\Taxation\Presentation\Requests\MarkCertificateReceivedRequest;
use App\Modules\Taxation\Presentation\Requests\RecordSalesWithholdingRequest;
use Illuminate\Http\JsonResponse;

/**
 * Sales Withholding Tracking Controller
 *
 * Manages withholding tax tracking for sales invoices.
 */
class SalesWithholdingTrackingController extends Controller
{
    public function __construct(
        private readonly SalesWithholdingTrackingService $service,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Record withholding applied by customer on a sales document.
     *
     * POST /api/v1/documents/{documentId}/record-withholding
     */
    public function recordWithholding(
        string $documentId,
        RecordSalesWithholdingRequest $request
    ): JsonResponse {
        // api.taxation.011: pre-fix Document::findOrFail loaded
        // foreign-tenant document and then 403'd post-load. Per the
        // cluster invariant, every read whose anchor came from a route
        // param MUST carry tenant_id + company_id predicates so a
        // foreign document 404s at the read tier rather than leaking
        // its existence (and content via eager loads) before a
        // post-load check.
        $company = $this->companyContext->requireCompany();
        $document = Document::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($documentId);

        // Verify document is a sales document
        if (! in_array($document->type, [DocumentType::Invoice, DocumentType::CreditNote])) {
            abort(400, 'Withholding can only be recorded on invoices or credit notes');
        }

        $data = CreateSalesWithholdingTrackingData::fromArray(
            array_merge($request->validated(), ['document_id' => $documentId])
        );

        try {
            $tracking = $this->service->recordWithholding(
                $document,
                $data,
                $company->id,
                $company->tenant_id
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data' => $tracking,
            'message' => 'Sales withholding recorded successfully',
        ], 201);
    }

    /**
     * Get all sales withholding tracking records for current company.
     *
     * GET /api/v1/sales-withholding
     */
    public function index(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        // Check for filter parameter
        $filter = request()->query('filter');

        if ($filter === 'pending') {
            $trackingRecords = $this->service->getPendingForCompany($companyId);
        } else {
            $trackingRecords = $this->service->getAllForCompany($companyId);
        }

        return response()->json([
            'data' => $trackingRecords->values()->all(),
        ]);
    }

    /**
     * Get a specific sales withholding tracking record.
     *
     * GET /api/v1/sales-withholding/{id}
     */
    public function show(string $id): JsonResponse
    {
        $tracking = $this->service->findById($id);

        if (! $tracking) {
            abort(404, 'Sales withholding tracking record not found');
        }

        // Verify tracking record belongs to current company
        if ($tracking->companyId !== $this->companyContext->getCompanyId()) {
            abort(403, 'Tracking record does not belong to your company');
        }

        return response()->json([
            'data' => $tracking,
        ]);
    }

    /**
     * Mark certificate as received from customer.
     *
     * PATCH /api/v1/sales-withholding/{id}/certificate-received
     */
    public function markCertificateReceived(
        string $id,
        MarkCertificateReceivedRequest $request
    ): JsonResponse {
        $tracking = $this->service->findById($id);

        if (! $tracking) {
            abort(404, 'Sales withholding tracking record not found');
        }

        // Verify tracking record belongs to current company
        if ($tracking->companyId !== $this->companyContext->getCompanyId()) {
            abort(403, 'Tracking record does not belong to your company');
        }

        try {
            $updatedTracking = $this->service->markCertificateReceived(
                $id,
                $request->validated('certificate_number')
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data' => $updatedTracking,
            'message' => 'Certificate marked as received',
        ]);
    }
}
