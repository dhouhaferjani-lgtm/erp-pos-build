<?php

declare(strict_types=1);

namespace App\Modules\Media\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Presentation\Requests\UploadDocumentMediaRequest;
use App\Shared\Architecture\CrossTenantRoute;
use App\Shared\Contracts\MediaServiceInterface;
use App\Shared\DTOs\Media\MediaAttachmentView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Document attachment controller — Phase 2 unified media subsystem.
 *
 * Replaces AttachmentController. Routes are identical; this controller
 * delegates to MediaServiceInterface instead of AttachmentService.
 *
 * Company-scope gate: every document lookup is scoped to the resolved
 * Company (same logic as AttachmentController::resolveDocument).
 * Cross-company attachments within the same tenant → 404.
 */
class DocumentAttachmentController extends Controller
{
    public function __construct(
        private readonly MediaServiceInterface $mediaService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request, string $document): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);

        $attachments = $this->mediaService->listForOwner(
            MediaOwnerType::Document,
            $documentModel->id,
            $documentModel->tenant_id,
        );

        return response()->json([
            'data' => array_map(
                fn (MediaAttachmentView $view) => $this->toJson($view),
                $attachments,
            ),
        ]);
    }

    public function store(UploadDocumentMediaRequest $request, string $document): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $documentModel = $this->resolveDocument($document);

        $file = $request->file('file');
        if ($file === null) {
            return response()->json(['error' => 'No file provided'], 422);
        }

        /** @var User|null $user */
        $user = $request->user();
        $userId = $user?->id;

        try {
            /** @var array<int, string> $allowedMime */
            $allowedMime = config('media.documents.allowed_mime_types');

            /** @var string|null $rawRole */
            $rawRole = $request->input('role');
            $role = $rawRole !== null ? MediaRole::from($rawRole) : MediaRole::Datasheet;

            $view = $this->mediaService->attachUpload(
                MediaOwnerType::Document,
                $documentModel->id,
                $documentModel->tenant_id,
                $file,
                $userId,
                $role,
                $request->input('description'),
                $allowedMime,
                MediaAssetType::Document,
            );

            return response()->json([
                'data' => $this->toJson($view),
                'message' => __('messages.attachment.uploaded'),
            ], 201);
        } catch (ValidationException $e) {
            // Defense-in-depth: service-layer MIME guard fires if FormRequest
            // allow-list and service allow-list diverge (should not happen in
            // normal operation but protects against config drift).
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function download(Request $request, string $document, string $attachment): StreamedResponse|RedirectResponse|JsonResponse
    {
        $documentModel = $this->resolveDocument($document);

        try {
            return $this->mediaService->download(
                MediaOwnerType::Document,
                $documentModel->id,
                $attachment,
                $documentModel->tenant_id,
            );
        } catch (NotFoundHttpException $e) {
            // abort(404) from resolveDocument or MediaService — propagate as a
            // clean Laravel 404 rather than re-wrapping it in a JSON {error: ""}
            // body (which produces an empty-message 404 and breaks error handling).
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function destroy(Request $request, string $document, string $attachment): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);

        $this->mediaService->detach(
            MediaOwnerType::Document,
            $documentModel->id,
            $attachment,
            $documentModel->tenant_id,
        );

        return response()->json([
            'message' => __('messages.attachment.deleted'),
        ]);
    }

    /**
     * Get allowed file types and max size for the frontend.
     *
     * Values are sourced from config('media.documents.*') — set in
     * config/media.php as part of the Phase-1 unification.
     */
    #[CrossTenantRoute(reason: 'Static config endpoint: returns media.documents config (max_file_size, allowed_mime_types, allowed_extensions) for UI validation. No DB access; constants are platform-level.')]
    public function config(): JsonResponse
    {
        /** @var int $maxFileSize */
        $maxFileSize = config('media.documents.max_file_size');

        return response()->json([
            'data' => [
                'max_file_size' => $maxFileSize,
                'max_file_size_mb' => $maxFileSize / 1048576,
                'allowed_extensions' => config('media.documents.allowed_extensions'),
                'allowed_mime_types' => config('media.documents.allowed_mime_types'),
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

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

    /**
     * Map a MediaAttachmentView to the legacy JSON contract shape.
     *
     * Field mapping:
     *  - filename        = originalFilename (no separate stored filename; frontend displays original_filename)
     *  - original_filename = originalFilename
     *  - description     = caption
     *  - formatted_file_size: byte→human string, byte-for-byte identical to
     *    DocumentAttachment::getFormattedFileSizeAttribute().
     *
     * @return array<string, mixed>
     */
    private function toJson(MediaAttachmentView $view): array
    {
        return [
            'id' => $view->id,
            'filename' => $view->originalFilename,
            'original_filename' => $view->originalFilename,
            'mime_type' => $view->mimeType,
            'file_size' => $view->fileSize,
            'formatted_file_size' => $this->formatFileSize($view->fileSize),
            'description' => $view->caption,
            'is_image' => $view->isImage,
            'is_pdf' => $view->isPdf,
            'uploaded_by' => [
                'id' => $view->uploadedById,
                'name' => $view->uploadedByName,
            ],
            'created_at' => $view->createdAt,
        ];
    }

    /**
     * Replicate DocumentAttachment::getFormattedFileSizeAttribute() exactly.
     *
     * Byte boundaries and number_format(…, 2) precision match the legacy model
     * so the frontend receives the same human-readable string.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }
}
