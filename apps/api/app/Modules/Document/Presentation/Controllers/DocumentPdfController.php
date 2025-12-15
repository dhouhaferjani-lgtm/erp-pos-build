<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Application\Services\DocumentPdfService;
use App\Modules\Document\Domain\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentPdfController extends Controller
{
    public function __construct(
        private readonly DocumentPdfService $pdfService
    ) {}

    /**
     * Download document as PDF.
     */
    public function download(string $id): Response
    {
        $document = Document::findOrFail($id);

        $pdf = $this->pdfService->generate($document);
        $filename = $this->pdfService->getFilename($document);

        return $pdf->download($filename);
    }

    /**
     * Stream document as PDF (for preview/print).
     */
    public function preview(string $id): Response
    {
        $document = Document::findOrFail($id);

        $pdf = $this->pdfService->generate($document);
        $filename = $this->pdfService->getFilename($document);

        return $pdf->stream($filename);
    }

    /**
     * Generate PDF and return path (for email attachments).
     */
    public function generatePath(string $id): JsonResponse
    {
        $document = Document::findOrFail($id);

        $path = $this->pdfService->generateAndSave($document);

        return response()->json([
            'data' => [
                'path' => $path,
                'filename' => $this->pdfService->getFilename($document),
            ],
        ]);
    }
}
