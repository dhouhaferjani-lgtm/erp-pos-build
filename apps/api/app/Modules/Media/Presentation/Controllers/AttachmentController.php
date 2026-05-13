<?php

declare(strict_types=1);

namespace App\Modules\Media\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Application\Services\AttachmentService;
use App\Modules\Media\Domain\DocumentAttachment;
use App\Modules\Media\Presentation\Requests\UploadAttachmentRequest;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document attachment controller.
 *
 * dev-remediation/E — closed the cross-tenant + cross-company gap on
 * Route Model Binding by replacing every `Document $document` and
 * `DocumentAttachment $attachment` parameter with string ids resolved
 * through CompanyContext-scoped queries. Prior code only checked
 * `user->tenant_id !== document->tenant_id`, missing the cross-company
 * within same tenant case.
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request, string $document): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);

        $attachments = $this->attachmentService->getAttachments($documentModel);

        return response()->json([
            'data' => $attachments->map(fn (DocumentAttachment $attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'original_filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type,
                'file_size' => $attachment->file_size,
                'formatted_file_size' => $attachment->getFormattedFileSizeAttribute(),
                'description' => $attachment->description,
                'is_image' => $attachment->isImage(),
                'is_pdf' => $attachment->isPdf(),
                'uploaded_by' => [
                    'id' => $attachment->uploader->id,
                    'name' => $attachment->uploader->name,
                ],
                'created_at' => $attachment->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(UploadAttachmentRequest $request, string $document): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);

        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $file = $request->file('file');
        if ($file === null) {
            return response()->json(['error' => 'No file provided'], 422);
        }

        try {
            $attachment = $this->attachmentService->upload(
                $documentModel,
                $file,
                $user,
                $request->input('description')
            );

            return response()->json([
                'data' => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'original_filename' => $attachment->original_filename,
                    'mime_type' => $attachment->mime_type,
                    'file_size' => $attachment->file_size,
                    'formatted_file_size' => $attachment->getFormattedFileSizeAttribute(),
                    'description' => $attachment->description,
                    'is_image' => $attachment->isImage(),
                    'is_pdf' => $attachment->isPdf(),
                    'uploaded_by' => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ],
                    'created_at' => $attachment->created_at?->toIso8601String(),
                ],
                'message' => __('messages.attachment.uploaded'),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function download(Request $request, string $document, string $attachment): StreamedResponse|JsonResponse
    {
        $documentModel = $this->resolveDocument($document);
        $attachmentModel = $this->resolveAttachment($documentModel, $attachment);

        try {
            return $this->attachmentService->download($attachmentModel);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function destroy(Request $request, string $document, string $attachment): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);
        $attachmentModel = $this->resolveAttachment($documentModel, $attachment);

        $this->attachmentService->delete($attachmentModel);

        return response()->json([
            'message' => __('messages.attachment.deleted'),
        ]);
    }

    /**
     * Get allowed file types and max size for frontend
     */
    #[CrossTenantRoute(reason: 'Static config endpoint: returns AttachmentService::MAX_FILE_SIZE and ALLOWED_MIME_TYPES platform constants for UI dropdown population. No DB access; constants are platform-level.')]
    public function config(): JsonResponse
    {
        return response()->json([
            'data' => [
                'max_file_size' => AttachmentService::MAX_FILE_SIZE,
                'max_file_size_mb' => AttachmentService::MAX_FILE_SIZE / 1048576,
                'allowed_extensions' => AttachmentService::getAllowedExtensions(),
                'allowed_mime_types' => AttachmentService::ALLOWED_MIME_TYPES,
            ],
        ]);
    }

    private function resolveDocument(string $documentId): Document
    {
        $company = $this->companyContext->requireCompany();

        $document = Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $documentId)
            ->first();

        if ($document === null) {
            abort(404, 'Document not found');
        }

        return $document;
    }

    private function resolveAttachment(Document $document, string $attachmentId): DocumentAttachment
    {
        $attachment = DocumentAttachment::query()
            ->where('document_id', $document->id)
            ->where('id', $attachmentId)
            ->first();

        if ($attachment === null) {
            abort(404, 'Attachment not found');
        }

        return $attachment;
    }
}
