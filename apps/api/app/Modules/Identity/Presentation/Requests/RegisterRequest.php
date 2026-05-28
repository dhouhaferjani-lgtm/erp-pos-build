<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use App\Enums\Vertical;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // User fields
            'name' => ['required', 'string', 'max:255'],
            // INTENTIONAL EXCEPTION to the email-first multi-tenant identity model
            // (Codex review S2; owner decision 2026-05-25, LOCKED). Self-signup
            // (register) keeps GLOBAL uniqueness: one self-registered organization
            // per email — this limits self-serve tenant spam. The same email CAN
            // still belong to multiple tenants, but only via INVITATION
            // (CreateUserRequest uses tenant-scoped uniqueness). Do NOT relax this
            // to per-tenant without re-opening the product decision.
            // See docs/sessions/2026-05-25-t6-phase0a-status.md (Deviations §1).
            // Global uniqueness for self-signup is preserved across both tenancy
            // modes. In shared-DB (compat) the single `users` table IS the global
            // index. In database-per-tenant there is no global `users` table — the
            // global email index is `central_identities`. We pin the unique rule to
            // the `central` connection explicitly with the `connection.table`
            // syntax: ResolveTenancy may have already switched the default
            // connection to a tenant DB (e.g. a logged-in user opening /register
            // from a session that carries a tenant context), and an unpinned rule
            // would query the wrong database — central tables are not present
            // there. Same product rule, correct database in every tenancy state.
            'email' => [
                'required',
                'email',
                config('tenancy_resolver.db_per_tenant')
                    ? Rule::unique('central.central_identities', 'email')
                    : Rule::unique('users', 'email'),
            ],
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
