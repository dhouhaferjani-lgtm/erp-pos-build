<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Requests;

use App\Models\SuperAdmin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use LogicException;

final class CreateDefaultsEditorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\Rule|ValidationRule>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique(SuperAdmin::class, 'email')],
            'password' => ['nullable', 'string', 'max:255', $this->defaultPasswordRule()],
            'role' => ['prohibited'],
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
