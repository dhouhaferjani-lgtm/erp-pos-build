<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CreateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $currentUser = $this->user();

        if (! $currentUser?->can('users.create')) {
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'required_unless:role,cashier',
                'email',
                Rule::unique('users', 'email')->where('tenant_id', $currentUser->tenant_id)->whereNotNull('email'),
            ],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,20}$/'],
            'role' => ['required', 'string', 'exists:roles,name', new AssignableRole($currentUser)],
            'locale' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:50'],
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
            && $currentUser->can('users.create')
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
        ];
    }
}
