<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests;

use App\Shared\Enums\EnrichmentFeedbackReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'reason' => ['required', 'string', Rule::in(array_column(EnrichmentFeedbackReason::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
