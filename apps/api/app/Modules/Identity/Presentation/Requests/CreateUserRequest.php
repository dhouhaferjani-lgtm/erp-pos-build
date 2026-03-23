<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('users.create') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $currentUser */
        $currentUser = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'required_unless:role,cashier',
                'email',
                Rule::unique('users', 'email')->where('tenant_id', $currentUser->tenant_id)->whereNotNull('email'),
            ],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,20}$/'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'locale' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email already exists in your organization.',
            'phone.regex' => 'The phone number format is invalid. Use international format (e.g., +33612345678).',
            'role.exists' => 'The selected role does not exist.',
        ];
    }
}
