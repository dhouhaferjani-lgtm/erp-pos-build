<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Requests;

use App\Modules\Partner\Domain\Enums\ConsolidationFrequency;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Enums\PaymentTerms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdatePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Modules\Identity\Domain\User|null $user */
        $user = $this->user();
        $tenantId = $user?->tenant_id;
        $partnerId = $this->route('partner');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', new Enum(PartnerType::class)],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('partners', 'code')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($partnerId),
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'vat_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('partners', 'vat_number')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($partnerId),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $countryCode = $this->input('country_code');
                    if ($countryCode === null) {
                        return;
                    }

                    if (! $this->validateVatNumber((string) $countryCode, (string) $value)) {
                        $fail('The VAT number format is invalid for the selected country.');
                    }
                },
            ],
            'customer_category' => ['sometimes', 'nullable', new Enum(CustomerCategory::class)],
            'company_legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payment_terms' => ['sometimes', 'nullable', new Enum(PaymentTerms::class)],
            'payment_terms_days' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:365',
            ],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999.9999'],
            'discount_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'invoice_consolidation' => ['sometimes', 'boolean'],
            'consolidation_frequency' => [
                'sometimes',
                'nullable',
                new Enum(ConsolidationFrequency::class),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'street_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'street_address_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
        ];
    }

    private function validateVatNumber(string $countryCode, string $vatNumber): bool
    {
        return match ($countryCode) {
            'FR' => (bool) preg_match('/^FR[0-9A-Z]{2}[0-9]{9}$/', $vatNumber),
            'TN' => (bool) preg_match('/^[0-9]{7}[A-Z]{3}[0-9]{3}$/', $vatNumber),
            'IT' => (bool) preg_match('/^IT[0-9]{11}$/', $vatNumber),
            'GB' => (bool) preg_match('/^GB([0-9]{9}|[0-9]{12}|(HA|GD)[0-9]{3})$/', $vatNumber),
            default => true,
        };
    }
}
