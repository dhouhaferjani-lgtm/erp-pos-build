<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectEnrichmentRequest extends FormRequest
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
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
