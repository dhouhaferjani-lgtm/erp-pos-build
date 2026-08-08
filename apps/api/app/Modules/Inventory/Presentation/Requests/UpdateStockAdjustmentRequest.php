<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `PATCH /api/v1/stock-adjustments/{adjustment}` (DPA V7 / plan §2).
 *
 * The re-anchor target of the persisted-draft staleness branch. COMPOSES the
 * same line rule set as the store request rather than restating it, so the
 * signed 4-dp regexes, `not_in:0`, the derived reason-sign invariant and the
 * line-uniqueness key cannot drift between the two verbs.
 *
 * `location_id` is PROHIBITED: changing it would invalidate every line's
 * `observed_before` and the whole lock set. The operator cancels and re-authors.
 */
class UpdateStockAdjustmentRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'location_id' => ['prohibited'],
            'occurred_at' => ['prohibited'],

            ...StockAdjustmentLineRules::forCompany($company->tenant_id, $company->id, linesRequired: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StockAdjustmentLineRules::messages();
    }

    public function withValidator(Validator $validator): void
    {
        StockAdjustmentLineRules::attachSignInvariant($validator);
        StockAdjustmentLineRules::attachLineUniqueness($validator);
    }
}
