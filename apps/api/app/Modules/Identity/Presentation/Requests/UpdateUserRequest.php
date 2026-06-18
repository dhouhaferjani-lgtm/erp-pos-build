<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('users.update') ?? false;
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

        /** @var string $userId */
        $userId = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                Rule::unique('users', 'email')
                    ->where('tenant_id', $currentUser->tenant_id)
                    ->whereNotNull('email')
                    ->ignore($userId),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,20}$/'],
            'role' => ['sometimes', 'string', 'exists:roles,name', new AssignableRole($currentUser)],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'can_discount' => ['sometimes', 'boolean'],
            'max_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
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
            'max_discount_percent.regex' => 'Max discount percent must have at most 2 decimal places.',
        ];
    }
}
