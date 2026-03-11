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
            'points' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
