<?php

declare(strict_types=1);

namespace App\Modules\Media\Presentation\Requests;

use App\Modules\Media\Application\Services\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;

class UploadAttachmentRequest extends FormRequest
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
        $maxSize = AttachmentService::MAX_FILE_SIZE / 1024; // Convert to KB
        $allowedMimes = implode(',', $this->getAllowedMimeTypes());

        return [
            'file' => [
                'required',
                'file',
                'max:' . $maxSize,
                'mimetypes:' . $allowedMimes,
            ],
            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
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

    /**
     * @return array<string>
     */
    private function getAllowedMimeTypes(): array
    {
        return AttachmentService::ALLOWED_MIME_TYPES;
    }
}
