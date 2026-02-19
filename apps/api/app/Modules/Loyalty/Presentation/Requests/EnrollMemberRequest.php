<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnrollMemberRequest extends FormRequest
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
            'program_id' => ['required', 'uuid', 'exists:loyalty_programs,id'],
            'welcome_bonus' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
