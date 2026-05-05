<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreRecipeLineRequest extends FormRequest
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
        $componentType = $this->input('component_type', 'product');

        // Both branches scope to the caller's tenant + company. The dynamic
        // table-name interpolation prior to this fix allowed a cross-tenant
        // products / composite_items id to satisfy the validator and be
        // persisted as recipe_lines.component_id (Codex round-1 Finding 1).
        $componentExistsRule = $componentType === ComponentType::CompositeItem->value
            ? ScopedExists::tenantAndCompany('composite_items', $company->tenant_id, $company->id)
            : ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id);

        return [
            'component_type' => ['sometimes', new Enum(ComponentType::class)],
            'component_id' => ['required', 'uuid', $componentExistsRule],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'is_optional' => ['sometimes', 'boolean'],
            'is_scalable' => ['sometimes', 'boolean'],
            'wastage_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
