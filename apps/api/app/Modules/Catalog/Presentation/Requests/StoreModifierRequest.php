<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreModifierRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('modifier-groups.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'price_adjustment' => ['sometimes', 'numeric'],
            'component_type' => ['nullable', new Enum(ComponentType::class)],
            'component_id' => ['nullable', 'uuid', ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id)],
            'component_quantity' => ['nullable', 'numeric', 'min:0'],
            'component_unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
