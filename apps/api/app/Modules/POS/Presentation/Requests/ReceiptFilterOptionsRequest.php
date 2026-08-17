<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReceiptFilterOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['uuid'],
            'from_date' => ['sometimes', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ];
    }
}
