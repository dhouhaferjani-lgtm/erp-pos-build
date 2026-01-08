<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Application\DTOs\CreateWithholdingCertificateData;
use App\Modules\Taxation\Application\Services\CertificatePDFService;
use App\Modules\Taxation\Application\Services\TEJExportService;
use App\Modules\Taxation\Application\Services\WithholdingCertificateService;
use App\Modules\Taxation\Domain\Repositories\WithholdingCertificateRepositoryInterface;
use App\Modules\Taxation\Presentation\Requests\CreateWithholdingCertificateRequest;
use App\Modules\Taxation\Presentation\Requests\SubmitToTEJRequest;
use App\Modules\Taxation\Presentation\Requests\VoidCertificateRequest;
use App\Modules\Taxation\Presentation\Resources\WithholdingCertificateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Withholding Certificate Controller
 *
 * Handles CRUD operations and lifecycle actions for withholding certificates.
 */
class WithholdingCertificateController extends Controller
{
    public function __construct(
        private readonly WithholdingCertificateService $certificateService,
        private readonly WithholdingCertificateRepositoryInterface $certificateRepository,
        private readonly TEJExportService $tejExportService,
        private readonly CertificatePDFService $pdfService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List withholding certificates with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $filters = [
            'direction' => $request->input('direction'),
            'status' => $request->input('status'),
            'year' => $request->input('year'),
            'partner_id' => $request->input('partner_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $certificates = $this->certificateRepository->findByCompany(
            $companyId,
            array_filter($filters)
        );

        return response()->json([
            'data' => WithholdingCertificateResource::collection($certificates->items()),
            'meta' => [
                'per_page' => $certificates->perPage(),
                'has_more' => $certificates->hasMorePages(),
            ],
            'links' => [
                'next' => $certificates->nextCursor()?->encode(),
                'prev' => $certificates->previousCursor()?->encode(),
            ],
        ]);
    }

    /**
     * Get a single certificate.
     */
    public function show(string $id): JsonResponse
    {
        $certificate = $this->certificateService->findById($id);

        if (! $certificate) {
            return response()->json([
                'error' => [
                    'code' => 'CERTIFICATE_NOT_FOUND',
                    'message' => 'Withholding certificate not found',
                ],
            ], 404);
        }

        return response()->json([
            'data' => WithholdingCertificateResource::make(
                $this->certificateRepository->findById($id)
            ),
        ]);
    }

    /**
     * Create a new withholding certificate.
     */
    public function store(CreateWithholdingCertificateRequest $request): JsonResponse
    {
        $partner = Partner::findOrFail($request->input('partner_id'));
        $tenantId = $this->companyContext->requireTenantId();

        $data = CreateWithholdingCertificateData::fromArray(
            array_merge($request->validated(), [
                'company_id' => $this->companyContext->requireCompanyId(),
            ])
        );

        try {
            $certificate = $this->certificateService->create($data, $partner, $tenantId);

            return response()->json([
                'data' => $certificate->toArray(),
                'message' => 'Withholding certificate created successfully',
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'CREATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Issue a certificate (finalize with hash chain).
     */
    public function issue(string $id, Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $certificate = $this->certificateService->issue($id, $userId);

            return response()->json([
                'data' => $certificate->toArray(),
                'message' => 'Certificate issued successfully',
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'ISSUE_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Void a certificate.
     */
    public function void(string $id, VoidCertificateRequest $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $certificate = $this->certificateService->void(
                $id,
                $request->input('reason'),
                $userId
            );

            return response()->json([
                'data' => $certificate->toArray(),
                'message' => 'Certificate voided successfully',
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VOID_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Submit certificate to TEJ platform.
     */
    public function submitTEJ(string $id, SubmitToTEJRequest $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $certificate = $this->certificateService->submitToTEJ(
                $id,
                $request->input('tej_reference'),
                $userId
            );

            return response()->json([
                'data' => $certificate->toArray(),
                'message' => 'Certificate submitted to TEJ successfully',
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'TEJ_SUBMISSION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Download certificate as PDF.
     */
    public function downloadPDF(string $id): StreamedResponse
    {
        $certificate = $this->certificateRepository->findById($id);

        if (! $certificate) {
            abort(404, 'Certificate not found');
        }

        $html = $this->pdfService->generateHTML($certificate);
        $filename = $this->pdfService->generateFilename($certificate);

        // For now, download as HTML (TODO: integrate PDF library)
        return response()->streamDownload(function () use ($html) {
            echo $html;
        }, str_replace('.pdf', '.html', $filename), [
            'Content-Type' => 'text/html',
        ]);
    }

    /**
     * Download TEJ XML for single certificate.
     */
    public function downloadTEJXML(string $id): StreamedResponse
    {
        $certificate = $this->certificateRepository->findById($id);

        if (! $certificate) {
            abort(404, 'Certificate not found');
        }

        $xml = $this->tejExportService->generateXML($certificate);
        $filename = $this->tejExportService->generateFilename($certificate);

        return response()->streamDownload(function () use ($xml) {
            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/xml',
        ]);
    }

    /**
     * Download batch TEJ XML for multiple certificates.
     */
    public function downloadBatchTEJXML(Request $request): StreamedResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $filters = [
            'year' => $request->input('year'),
            'direction' => $request->input('direction'),
            'status' => 'issued', // Only issued certificates
        ];

        // Get all matching certificates (not paginated)
        $certificates = $this->certificateRepository->findByCompany(
            $companyId,
            array_filter($filters)
        )->items();

        if (empty($certificates)) {
            abort(404, 'No certificates found for export');
        }

        $certificatesCollection = collect($certificates);
        $xml = $this->tejExportService->generateBatchXML($certificatesCollection);
        $filename = $this->tejExportService->generateFilename($certificatesCollection->first(), true);

        return response()->streamDownload(function () use ($xml) {
            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/xml',
        ]);
    }

    /**
     * Delete a certificate (only drafts).
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->certificateRepository->delete($id);

            return response()->json([
                'message' => 'Certificate deleted successfully',
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'DELETE_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
