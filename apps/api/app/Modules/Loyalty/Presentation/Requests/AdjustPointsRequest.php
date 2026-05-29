<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustPointsRequest extends FormRequest
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
            'points' => ['required', 'numeric', 'not_in:0', 'regex:/^-?\d+(\.\d{1,3})?$/'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'points.regex' => 'Points must not exceed 3 decimal places.',
        ];
    }
}
