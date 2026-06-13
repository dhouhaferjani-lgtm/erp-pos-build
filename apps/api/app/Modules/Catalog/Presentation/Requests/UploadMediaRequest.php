<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an image upload for the media sub-system.
 *
 * Used as a rule reference and standalone validator inside MediaUploadService
 * (service-layer guard against non-HTTP callers such as importers and seeders).
 *
 * Rules mirror the legacy ProductImageController constraints exactly:
 *  - JPEG, PNG, WebP, GIF only
 *  - 5 120 KB max (= 5 MiB, same as ProductImageService::MAX_FILE_SIZE)
 */
final class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ];
    }
}
