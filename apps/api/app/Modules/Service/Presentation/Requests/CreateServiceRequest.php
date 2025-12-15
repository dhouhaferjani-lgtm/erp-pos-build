<?php

declare(strict_types=1);

namespace App\Modules\Service\Presentation\Requests;

use App\Modules\Service\Domain\Enums\PricingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreateServiceRequest extends FormRequest
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
        $user = $this->user();
        $companyId = $user->company_id ?? app(\App\Modules\Company\Services\CompanyContext::class)->getCompanyId();

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('services', 'code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'uuid', 'exists:service_categories,id'],
            'pricing_type' => ['required', new Enum(PricingType::class)],
            'base_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'default_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
