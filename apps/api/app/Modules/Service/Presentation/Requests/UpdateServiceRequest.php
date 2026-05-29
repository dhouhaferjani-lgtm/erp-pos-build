<?php

declare(strict_types=1);

namespace App\Modules\Service\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Service\Domain\Enums\PricingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateServiceRequest extends FormRequest
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
        $serviceId = $this->route('service');
        $companyId = app(CompanyContext::class)->getCompanyId();

        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('services', 'code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($serviceId),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'uuid', 'exists:service_categories,id'],
            'pricing_type' => ['sometimes', new Enum(PricingType::class)],
            'base_price' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'default_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_price.regex' => 'Base price must have at most 2 decimal places.',
            'hourly_rate.regex' => 'Hourly rate must have at most 2 decimal places.',
            'tax_rate.regex' => 'Tax rate must have at most 2 decimal places.',
        ];
    }
}
