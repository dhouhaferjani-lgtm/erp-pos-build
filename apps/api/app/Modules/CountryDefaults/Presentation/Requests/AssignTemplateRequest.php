<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['country_code' => $this->route('countryCode')]);
    }

    /** @return array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\Rule|\Illuminate\Contracts\Validation\ValidationRule>> */
    public function rules(): array
    {
        return [
            'country_code' => ['required', 'string', 'regex:/^(?:\*|[A-Za-z]{2})$/'],
            'domain' => ['required', Rule::enum(TemplateDomain::class)],
            'template_id' => ['required', 'uuid'],
        ];
    }
}
