<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Requests;

use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workshop-bundles.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'pricing_mode' => ['sometimes', new Enum(BundlePricingMode::class)],
            'base_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'estimated_labor_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'service_interval_km' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'service_interval_months' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_price.regex' => 'Base price must have at most 3 decimal places.',
            'tax_rate.regex' => 'Tax rate must have at most 2 decimal places.',
            'estimated_labor_hours.regex' => 'Estimated labor hours must have at most 2 decimal places.',
        ];
    }
}
