<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the ordered attachment id list for a reorder request.
 *
 * The controller adds a custom validation rule to verify that every id in
 * image_ids belongs to the bound product — this request only covers the
 * structural / type rules so the controller can inject product-scoped DB
 * validation without duplicating them here.
 */
final class ReorderMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'image_ids' => ['required', 'array'],
            'image_ids.*' => ['uuid'],
        ];
    }
}
