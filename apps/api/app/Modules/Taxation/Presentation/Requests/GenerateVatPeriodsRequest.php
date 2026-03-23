<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateVatPeriodsRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
        ];
    }
}
