<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class PublishTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['id' => $this->route('id')]);
    }

    /** @return array<string, list<string|\Stringable|Rule|ValidationRule>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'standard_ref' => ['required', 'string', 'max:255'],
            'certified_country_codes' => ['required', 'array', 'min:1', 'max:249'],
            'certified_country_codes.*' => ['required', 'string', 'regex:/^(?:\*|[A-Za-z]{2})$/', 'distinct'],
        ];
    }
}
