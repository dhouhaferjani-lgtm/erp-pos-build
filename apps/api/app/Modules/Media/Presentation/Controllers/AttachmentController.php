<?php

declare(strict_types=1);

namespace App\Modules\Media\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Application\Services\AttachmentService;
use App\Modules\Media\Domain\DocumentAttachment;
use App\Modules\Media\Presentation\Requests\UploadAttachmentRequest;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachmentService
    ) {}

    /**
     * List all attachments for a document
     */
    public function index(Request $request, Document $document): JsonResponse
    {
        // Authorization: check user has access to the document
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant_id !== $document->tenant_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $attachments = $this->attachmentService->getAttachments($document);

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

    /**
     * Upload a new attachment to a document
     */
    public function store(UploadAttachmentRequest $request, Document $document): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant_id !== $document->tenant_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $file = $request->file('file');
        if ($file === null) {
            return response()->json(['error' => 'No file provided'], 422);
        }

        try {
            $attachment = $this->attachmentService->upload(
                $document,
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

    /**
     * Download an attachment
     */
    public function download(Request $request, Document $document, DocumentAttachment $attachment): StreamedResponse|JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant_id !== $document->tenant_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify attachment belongs to document
        if ($attachment->document_id !== $document->id) {
            return response()->json(['error' => 'Attachment not found'], 404);
        }

        try {
            return $this->attachmentService->download($attachment);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Delete an attachment
     */
    public function destroy(Request $request, Document $document, DocumentAttachment $attachment): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant_id !== $document->tenant_id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify attachment belongs to document
        if ($attachment->document_id !== $document->id) {
            return response()->json(['error' => 'Attachment not found'], 404);
        }

        $this->attachmentService->delete($attachment);

        return response()->json([
            'message' => __('messages.attachment.deleted'),
        ]);
    }

    /**
     * Get allowed file types and max size for frontend
     */
    #[CrossTenantRoute(reason: 'Static config endpoint: returns AttachmentService::MAX_FILE_SIZE and ALLOWED_MIME_TYPES platform constants for UI dropdown population. No DB access; constants are platform-level. The other AttachmentController methods (index/store/download/destroy) explicitly validate $user->tenant_id !== $document->tenant_id and pass via the heuristic.')]
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
}
