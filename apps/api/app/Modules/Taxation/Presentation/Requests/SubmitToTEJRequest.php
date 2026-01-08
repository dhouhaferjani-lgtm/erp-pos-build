<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitToTEJRequest extends FormRequest
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
            'tej_reference' => ['required', 'string', 'max:100'],
        ];
    }
}
