<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Rules\ValidLocationAccess;
use App\Shared\Domain\QuantityScale;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateStockThresholdsRequest extends FormRequest
{
    public function __construct(
        private readonly LocationContext $locationContext,
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'product_id' => ['required', 'uuid'],
            'variant_id' => ['nullable', 'uuid'],
            'location_id' => ['required', 'uuid', ScopedExists::company('locations', $companyId), new ValidLocationAccess($this->locationContext, $this->companyContext, $companyId)],
            'min_quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'max_quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'min_quantity.regex' => 'must have at most 4 decimal places.',
            'max_quantity.regex' => 'must have at most 4 decimal places.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = $this->input('min_quantity');
            $max = $this->input('max_quantity');
            if (is_string($min) && is_string($max) && is_numeric($min) && is_numeric($max)
                && bccomp(
                    QuantityScale::round($min, QuantityScale::SCALE, QuantityScale::FLOOR),
                    QuantityScale::round($max, QuantityScale::SCALE, QuantityScale::FLOOR),
                    QuantityScale::SCALE,
                ) > 0) {
                $validator->errors()->add('max_quantity', 'max_quantity must be ≥ min_quantity.');
            }
        });
    }
}
