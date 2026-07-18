<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Rules\ValidLocationAccess;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class StoreStockTransferRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Preserve the existing authorization response for a valid but inaccessible source.
     *
     * The rule remains a validation failure (422) when evaluated directly, while the
     * HTTP endpoint retains its established LOCATION_ACCESS_DENIED contract (403).
     */
    protected function failedValidation(Validator $validator): void
    {
        $message = 'You do not have permission to access this location.';
        $errors = $validator->errors()->toArray();

        if (count($errors) === 1 && ($errors['source_location_id'] ?? null) === [$message]) {
            $sourceLocationId = (string) $this->input('source_location_id');
            $user = $this->user();

            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'LOCATION_ACCESS_DENIED',
                    'message' => 'You do not have permission to act on this location.',
                    'details' => [
                        'location_id' => $sourceLocationId,
                        'user_id' => $user?->getAuthIdentifier(),
                    ],
                ],
            ], Response::HTTP_FORBIDDEN));
        }

        parent::failedValidation($validator);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'source_location_id' => [
                'bail',
                'required',
                'string',
                'uuid',
                ScopedExists::company('locations', $company->id),
                new ValidLocationAccess($this->locationContext, $this->companyContext, $company->id),
            ],
            'destination_location_id' => ['required', 'string', 'uuid', 'different:source_location_id', ScopedExists::company('locations', $company->id)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'transfer_cost' => ['nullable', 'numeric', 'min:0'],
            'transfer_cost_label' => ['nullable', 'string', 'max:64'],
            'transfer_cost_distribution' => ['nullable', Rule::enum(TransferCostDistribution::class)],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string', 'uuid', ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id)],
            // Variant ownership (variant belongs to THIS line's product) and the
            // "required when the product has active variants" rule are enforced
            // in StockTransferService::assertVariantValidForProduct, where the
            // per-line product is in scope. Here we only check existence +
            // tenant/company + active.
            'lines.*.variant_id' => [
                'nullable',
                'string',
                'uuid',
                Rule::exists('product_variants', 'id')
                    ->where('tenant_id', $company->tenant_id)
                    ->where('company_id', $company->id)
                    ->where('is_active', true),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.batch_allocations' => ['nullable', 'array'],
            'lines.*.batch_allocations.*.batch_id' => [
                'required',
                'integer',
                Rule::exists('product_batches', 'id')->where('tenant_id', $company->tenant_id)->where('company_id', $company->id),
            ],
            'lines.*.batch_allocations.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
