<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRecipeRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('composite-items.manage-recipes') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'version_name' => ['nullable', 'string', 'max:255'],
            'yield_quantity' => ['sometimes', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
            // api.catalog.022 round-2: units has nullable tenant_id (system rows = NULL).
            'yield_unit_id' => ['nullable', 'uuid', ScopedExists::tenantOrSystem('units', $company->tenant_id)],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0'],
            'cook_time_minutes' => ['nullable', 'integer', 'min:0'],
            'instructions' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'yield_quantity.regex' => 'Yield quantity must have at most 4 decimal places.',
        ];
    }
}
