<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Presentation\Requests;

use App\Modules\Inventory\Domain\Enums\InventoryValuationMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanySettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address.country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:50', 'timezone:all'],
            'date_format' => ['sometimes', 'nullable', 'string', 'max:20'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'line_designation_override_enabled' => ['sometimes', 'boolean'],
            // DPA Wave 3 T9 — layer 1 of D-14's three refusal layers.
            // Deliberately NOT `Rule::enum(InventoryValuationMode::class)`: the
            // enum admits `periodic` (and the CHECK constraint does too) so the
            // mode can be enabled later without DDL, but nothing implements it,
            // so the WRITE boundary must refuse it with a 422 rather than let a
            // company be stamped into a mode whose every journal entry would be
            // a mis-statement. `null` clears the override and re-inherits the
            // country default.
            //
            // The allow-list is DERIVED from `isSupported()` — the enum's own
            // documented "ONE authority" for which modes are implemented — rather
            // than restated here (fix round 1, fiscal gate P3-4).
            'inventory_valuation_mode' => [
                'sometimes',
                'nullable',
                'string',
                'in:'.implode(',', InventoryValuationMode::supportedValues()),
            ],
            // Lane P-1 (owner ruling 2026-08-25) — the tenant override for
            // count-correction GL posting. `null` CLEARS the override and
            // re-inherits the seeded country default, which is why the rule is
            // `nullable` and not just `boolean`: without it a tenant could never
            // get back to "whatever my jurisdiction says", only to a frozen
            // copy of today's answer.
            'count_correction_gl_posting_enabled' => ['sometimes', 'nullable', 'boolean'],
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
            'name.required' => 'Company name is required.',
            'name.max' => 'Company name cannot exceed 255 characters.',
            'email.email' => 'Please provide a valid email address.',
            'website.url' => 'Please provide a valid website URL.',
            'primary_color.regex' => 'Primary color must be a valid hex color (e.g., #FF5733 or #F53).',
            'country_code.size' => 'Country code must be a 2-letter ISO code.',
            'country_code.alpha' => 'Country code must only contain letters.',
            'currency_code.size' => 'Currency code must be a 3-letter ISO code.',
            'currency_code.alpha' => 'Currency code must only contain letters.',
            'timezone.timezone' => 'Please provide a valid timezone.',
            'locale.max' => 'Locale cannot exceed 10 characters.',
            'inventory_valuation_mode.in' => 'Only perpetual inventory valuation is supported. '
                .'Periodic valuation is reserved and not yet implemented.',
        ];
    }
}
