<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResetDefaultsEditorCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['editor_id' => $this->route('editor')]);
    }

    /** @return array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\Rule|\Illuminate\Contracts\Validation\ValidationRule>> */
    public function rules(): array
    {
        return [
            'editor_id' => ['required', 'uuid'],
            'password' => ['nullable', 'string', 'min:12', 'max:255'],
        ];
    }
}
