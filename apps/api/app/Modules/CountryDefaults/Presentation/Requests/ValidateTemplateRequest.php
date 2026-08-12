<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ValidateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['id' => $this->route('id')]);
    }

    /** @return array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\Rule|\Illuminate\Contracts\Validation\ValidationRule>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'scope' => ['required', 'string', 'regex:/^(?:\*|[A-Za-z]{2})(?:,(?:\*|[A-Za-z]{2}))*$/'],
        ];
    }
}
