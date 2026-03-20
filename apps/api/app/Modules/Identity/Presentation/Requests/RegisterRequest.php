<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Enums\Vertical;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Request for user registration (signup).
 *
 * Creates:
 * - Tenant (subscription account)
 * - User (the person)
 * - Company (the legal entity)
 * - UserCompanyMembership (owner role)
 */
class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // User fields
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],

            // Company fields
            'company_name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],

            // Business vertical (required - determines modules)
            'vertical' => ['required', 'string', Rule::enum(Vertical::class)],

            // Optional company fields
            'company_legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],

            // Company address fields (optional during signup)
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:100'],
            'address_postal_code' => ['nullable', 'string', 'max:20'],
            'address_state' => ['nullable', 'string', 'max:100'],

            // Optional locale settings
            'currency' => ['nullable', 'string', 'size:3'],
            'locale' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:50'],

            // Device info (optional)
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'in:ios,android,windows,macos,linux,web'],
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
            'email.unique' => 'An account with this email already exists.',
            'password.confirmed' => 'Password confirmation does not match.',
            'country_code.size' => 'Country code must be a 2-letter ISO code (e.g., FR, TN, US).',
        ];
    }
}
