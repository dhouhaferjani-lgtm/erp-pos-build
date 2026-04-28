<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptEnrichmentRequest extends FormRequest
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
            'accepted_fields' => ['required', 'array', 'min:1'],
            'accepted_fields.*' => ['required', 'string'],
        ];
    }
}
