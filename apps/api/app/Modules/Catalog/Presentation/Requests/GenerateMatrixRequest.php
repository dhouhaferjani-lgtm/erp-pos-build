<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.variants.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attribute_ids' => ['required', 'array', 'min:1'],
            'attribute_ids.*' => ['uuid'],
        ];
    }
}
