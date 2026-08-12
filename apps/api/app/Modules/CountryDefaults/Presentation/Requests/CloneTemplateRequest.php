<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class CloneTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
