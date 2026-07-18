<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $currentUser = $this->user();

        if (! $currentUser?->can('users.update')) {
            return false;
        }

        return ! $this->has('allowed_location_ids')
            || $currentUser->can('users.manage_location_access');
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
            'allowed_location_ids' => ['sometimes', 'nullable', 'array', 'list'],
            'allowed_location_ids.*' => ['uuid'],
        ];
    }

    /**
     * Preserve the location-grant API error contract before validation runs.
     */
    protected function failedAuthorization(): void
    {
        $currentUser = $this->user();

        if (
            $this->has('allowed_location_ids')
            && $currentUser instanceof User
            && $currentUser->can('users.update')
            && ! $currentUser->can('users.manage_location_access')
        ) {
            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to manage location access.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $this->header('X-Request-ID', (string) uuid_create()),
                ],
            ], Response::HTTP_FORBIDDEN));
        }

        parent::failedAuthorization();
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
