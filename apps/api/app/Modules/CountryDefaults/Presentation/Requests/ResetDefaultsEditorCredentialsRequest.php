<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use LogicException;

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

    /** @return array<string, list<string|\Stringable|Rule|ValidationRule>> */
    public function rules(): array
    {
        return [
            'editor_id' => ['required', 'uuid'],
            'password' => ['nullable', 'string', 'max:255', $this->defaultPasswordRule()],
        ];
    }

    private function defaultPasswordRule(): Password
    {
        $rule = Password::defaults();
        if (! $rule instanceof Password) {
            throw new LogicException('The shared password policy did not resolve to a password rule.');
        }

        return $rule;
    }
}
