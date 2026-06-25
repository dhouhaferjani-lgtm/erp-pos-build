<?php

declare(strict_types=1);

namespace App\Modules\Media\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest for document-attachment uploads.
 *
 * Limits and allow-list are owned by config/media.php (Phase-1 media unification).
 * The legacy UploadAttachmentRequest hard-coded these values inside the service;
 * this request reads from config so the cutover in Phase 2 removes the only
 * remaining coupling to AttachmentService.
 *
 * Authorization is delegated to route middleware (auth:sanctum + permissions),
 * mirroring the behaviour of UploadAttachmentRequest.
 */
class UploadDocumentMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var int $maxBytes */
        $maxBytes = config('media.documents.max_file_size');
        $maxKb = (int) ($maxBytes / 1024);

        /** @var array<string> $mimes */
        $mimes = config('media.documents.allowed_mime_types');
        $allowedMimes = implode(',', $mimes);

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKb,
                'mimetypes:'.$allowedMimes,
            ],
            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * Custom validation messages that mirror the legacy UploadAttachmentRequest.
     *
     * R-M3: these validation.attachment.* keys are NOT defined in lang/{en,fr}/*
     * today — Laravel returns the literal key as the message. This is intentional:
     * the API response stays byte-identical to the legacy contract.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('validation.attachment.required'),
            'file.file' => __('validation.attachment.invalid'),
            'file.max' => __('validation.attachment.too_large', ['size' => '10MB']),
            'file.mimetypes' => __('validation.attachment.invalid_type'),
        ];
    }
}
