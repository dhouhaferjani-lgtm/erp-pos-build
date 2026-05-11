<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\DocumentPdfService;
use App\Modules\Document\Domain\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class DocumentPdfController extends Controller
{
    public function __construct(
        private readonly DocumentPdfService $pdfService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Download document as PDF.
     */
    public function download(string $id): Response
    {
        $document = $this->scopedFindOrFail($id);

        $pdf = $this->pdfService->generate($document);
        $filename = $this->pdfService->getFilename($document);

        return $pdf->download($filename);
    }

    /**
     * Stream document as PDF (for preview/print).
     */
    public function preview(string $id): Response
    {
        $document = $this->scopedFindOrFail($id);

        $pdf = $this->pdfService->generate($document);
        $filename = $this->pdfService->getFilename($document);

        return $pdf->stream($filename);
    }

    /**
     * Generate PDF and return path (for email attachments).
     */
    public function generatePath(string $id): JsonResponse
    {
        $document = $this->scopedFindOrFail($id);

        $path = $this->pdfService->generateAndSave($document);

        return response()->json([
            'data' => [
                'path' => $path,
                'filename' => $this->pdfService->getFilename($document),
            ],
        ]);
    }

    /**
     * Tenant+company scoped Document lookup.
     *
     * api.document.031/032/033: PDF download/preview/generatePath must refuse
     * cross-tenant document ids — a leaked or guessed UUID otherwise streams
     * a foreign tenant's PDF.
     */
    private function scopedFindOrFail(string $id): Document
    {
        $company = $this->companyContext->requireCompany();

        return Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);
    }
}
