<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Requests;

use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreBundleRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'pricing_mode' => ['required', new Enum(BundlePricingMode::class)],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'estimated_labor_hours' => ['nullable', 'numeric', 'min:0'],
            'service_interval_km' => ['nullable', 'integer', 'min:0'],
            'service_interval_months' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
